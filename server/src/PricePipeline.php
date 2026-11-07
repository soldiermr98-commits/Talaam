<?php
/**
 * هسته خط تولید نرخ — تست‌پذیر (providers از بیرون تزریق می‌شوند).
 *
 * جریان: lock → منابع به ترتیب (validate + sanity) → jump-guard نسبت به last_good →
 * انتشار اتمیک last_good.json و public/prices.json → همه شکست خوردند؟ stale با updated_at اصلی.
 */
class PricePipeline
{
    /** @var array<string,mixed> */
    private $config;
    /** @var ProviderInterface[] */
    private $providers;
    /** @var string|null */
    private $lockPath;
    /** @var resource|null */
    private $lockFp = null;
    /** @var string */
    private $lastGoodPath;
    /** @var string */
    private $publishPath;
    /** @var string */
    private $statePath;
    /** @var string|null */
    private $logPath;
    /** @var int */
    private $logMaxBytes;
    /** @var int */
    private $logKeep;
    /** @var string|null */
    private $rejectedLog;
    /** @var string|null */
    private $overridePath;

    public function __construct(array $config, array $providers)
    {
        $this->config = $config;
        $this->providers = $providers;
        $paths = $config['paths'] ?? [];
        $this->lastGoodPath = (string)($paths['last_good'] ?? '');
        $this->publishPath = (string)($paths['publish'] ?? '');
        $this->statePath = (string)($paths['state'] ?? '');
        $this->overridePath = (string)($paths['override'] ?? '');
        $cron = $config['cron'] ?? [];
        $this->lockPath = isset($cron['lock_path']) && $cron['lock_path'] !== '' ? (string)$cron['lock_path'] : null;
        $this->logPath = isset($cron['log_path']) && $cron['log_path'] !== '' ? (string)$cron['log_path'] : null;
        $this->logMaxBytes = (int)($cron['log_max_bytes'] ?? 524288);
        $this->logKeep = (int)($cron['log_keep'] ?? 3);
        $this->rejectedLog = isset($cron['rejected_log']) && $cron['rejected_log'] !== '' ? (string)$cron['rejected_log'] : null;
    }

    /**
     * یک سیکل کامل اجرا می‌کند.
     * @return array{status:string, published:?array} — status: ok|stale|locked|error
     */
    public function run(): array
    {
        if (!$this->acquireLock()) {
            $this->log('another run holds the lock — skipping');
            return ['status' => 'locked', 'published' => null];
        }
        try {
            $lastGood = $this->readJson($this->lastGoodPath);

            // ۱) زنجیره منابع
            $validated = [];
            foreach ($this->providers as $provider) {
                try {
                    $normalized = $provider->fetch();
                    $errors = $this->validateNormalized($normalized);
                    if (!empty($errors)) {
                        $this->logRejected($provider->id(), $errors, $normalized);
                        continue;
                    }
                    $validated[] = ['id' => $provider->id(), 'data' => $normalized];
                } catch (Throwable $e) {
                    $this->log('provider "' . $provider->id() . '" failed: ' . $e->getMessage());
                }
            }

            if (empty($validated)) {
                return $this->serveStale($lastGood);
            }

            // ۲) انتخاب اولین کاندیدِ پشت‌سرگذراننده jump-guard
            foreach ($validated as $cand) {
                if ($this->jumpGuardPasses((string)$cand['id'], $cand['data'], $lastGood, $validated)) {
                    $payload = $this->buildPayload($cand['data'], $cand['id'], false);
                    $this->writeAtomic($this->lastGoodPath, $payload);
                    $this->writeAtomic($this->publishPath, $payload);
                    $this->resetAllDown();
                    $this->log('published from "' . $cand['id'] . '" (gold18=' . $payload['gold18'] . ')');
                    return ['status' => 'ok', 'published' => $payload];
                }
            }
            $this->log('all validated candidates rejected by jump guard');
            return $this->serveStale($lastGood);
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * اعتبارسنجی داده نرمال‌شده: finite/positive/integer، مرز منطقی، ترتیب سکه‌ها، نسبت مظنه.
     * @return string[] خطاها (خالی = معتبر)
     */
    public function validateNormalized(array $n): array
    {
        $th = $this->config['thresholds'] ?? [];
        $errors = [];
        foreach (['gold18', 'mazaneh'] as $f) {
            $v = $n[$f] ?? null;
            if (!is_numeric($v) || !is_finite((float)$v) || (float)$v <= 0) {
                $errors[] = "$f invalid (must be finite positive number)";
            }
        }
        if (!empty($errors)) {
            return $errors;
        }
        $g = (float)$n['gold18'];
        if ($g < (float)($th['gold18_min'] ?? 100000)) {
            $errors[] = "gold18 below plausible min ($g)";
        }
        if ($g > (float)($th['gold18_max'] ?? 2000000000)) {
            $errors[] = "gold18 above plausible max ($g)";
        }
        $coins = $n['coins'] ?? [];
        if (!is_array($coins)) {
            $errors[] = 'coins must be an array';
            return $errors;
        }
        $coinMax = $g * (float)($th['coin_max_gold18_ratio'] ?? 100);
        foreach ($coins as $k => $v) {
            if (!is_numeric($v) || !is_finite((float)$v) || (float)$v < 0) {
                $errors[] = "coins.$k invalid";
            } elseif ((float)$v > $coinMax) {
                $errors[] = "coins.$k implausible ($v > " . (int)$coinMax . ')';
            }
        }
        // sanity ترتیب: امامی ≥ نیم ≥ ربع (فقط وقتی هر دو > ۰ باشند)
        if (($coins['emami'] ?? 0) > 0 && ($coins['nim'] ?? 0) > 0 && (float)$coins['emami'] < (float)$coins['nim']) {
            $errors[] = 'ordering sanity failed: emami < nim';
        }
        if (($coins['nim'] ?? 0) > 0 && ($coins['rob'] ?? 0) > 0 && (float)$coins['nim'] < (float)$coins['rob']) {
            $errors[] = 'ordering sanity failed: nim < rob';
        }
        // نسبت مظنه/گرم ≈ 4.33 با تلورانس
        $m = (float)$n['mazaneh'];
        if ($m > 0) {
            $ratio = $m / $g;
            $expected = (float)($th['mazaneh_ratio'] ?? 4.3318);
            $tol = (float)($th['mazaneh_tolerance'] ?? 0.5);
            if (abs($ratio - $expected) > $tol) {
                $errors[] = sprintf('mazaneh/gold18 ratio %.3f outside %.3f ± %.2f', $ratio, $expected, $tol);
            }
        }
        return $errors;
    }

    /**
     * گارد پرش: تغیر بیش از max_jump_pct نسبت به last_good رد می‌شود،
     * مگر با تأیید منبع دوم همسان در همین سیکل یا N اجر متوالی همسان (state).
     */
    private function jumpGuardPasses(string $candidateId, array $cand, ?array $lastGood, array $allValidated): bool
    {
        if ($lastGood === null || !isset($lastGood['gold18']) || (float)$lastGood['gold18'] <= 0) {
            return true; // اولین داده موفق — گاردی برای مقایسه نیست
        }
        $th = $this->config['thresholds'] ?? [];
        $maxPct = (float)($th['max_jump_pct'] ?? 5.0);
        $confirmRuns = (int)($th['jump_confirm_runs'] ?? 3);

        $fields = ['gold18'];
        foreach (['emami', 'bahar', 'nim', 'rob', 'gerami'] as $k) {
            if (($cand['coins'][$k] ?? 0) > 0 && (($lastGood['coins'][$k] ?? 0) > 0)) {
                $fields[] = 'coins.' . $k;
            }
        }

        $state = $this->readJson($this->statePath) ?? [];
        $consecutive = is_array($state['consecutive'] ?? null) ? $state['consecutive'] : [];
        $newConsecutive = [];
        $jumped = [];

        foreach ($fields as $f) {
            $new = $f === 'gold18' ? (float)$cand['gold18'] : (float)$cand['coins'][substr($f, 6)];
            $old = $f === 'gold18' ? (float)$lastGood['gold18'] : (float)($lastGood['coins'][substr($f, 6)] ?? 0);
            if ($old <= 0 || $new <= 0) {
                continue;
            }
            $pct = abs($new - $old) / $old * 100;
            if ($pct <= $maxPct) {
                $newConsecutive[$f] = ['value' => $new, 'count' => 0]; // نرمال — شمارنده صفر
                continue;
            }
            // تأیید با منبع دوم همسان در همین سیکل؟ (منبع خودش نمیتواند خودش را تأیید کند)
            $confirmedBySecond = false;
            foreach ($allValidated as $other) {
                if ($other['id'] === $candidateId) {
                    continue;
                }
                $on = $f === 'gold18' ? (float)$other['data']['gold18'] : (float)($other['data']['coins'][substr($f, 6)] ?? 0);
                if ($on > 0 && abs($on - $new) / $new * 100 <= $maxPct) {
                    $confirmedBySecond = true;
                    break;
                }
            }
            if ($confirmedBySecond) {
                $this->log("jump on $f confirmed by a second provider — accepted");
                continue;
            }
            // شمارش اجرهای متوالی همسان (±۱٪)
            $count = 0;
            if (isset($consecutive[$f]['value'], $consecutive[$f]['count'])
                && abs((float)$consecutive[$f]['value'] - $new) / max(1.0, $new) * 100 <= 1.0) {
                $count = (int)$consecutive[$f]['count'];
            }
            $count++;
            $newConsecutive[$f] = ['value' => $new, 'count' => $count];
            if ($count >= $confirmRuns) {
                $this->log("jump on $f accepted after $count consecutive consistent runs");
                $newConsecutive[$f] = ['value' => $new, 'count' => 0]; // شمارنده پس از پذیرش صفر
                continue;
            }
            $jumped[] = sprintf('%s changed %.2f%% (%d → %d), consecutive %d/%d', $f, $pct, (int)$old, (int)$new, $count, $confirmRuns);
        }

        $this->writeState($state, ['consecutive' => $newConsecutive]);

        if (empty($jumped)) {
            return true;
        }
        foreach ($jumped as $j) {
            $this->logRejected('jump-guard', [$j], $cand);
        }
        return false;
    }

    /** همه منابع شکست خوردند → آخرین داده خوب با stale=true و updated_at اصلی منتشر می‌شود */
    private function serveStale(?array $lastGood): array
    {
        if (!is_array($lastGood) || !isset($lastGood['gold18']) || (float)$lastGood['gold18'] <= 0) {
            $this->markAllDown();
            $this->log('all providers failed AND no usable last good — nothing published');
            return ['status' => 'error', 'published' => null];
        }
        // هرگز داده تازه نمی‌سازیم: updated_at و مقادیر last_good دست‌نخورده می‌مانند
        $lastGood['stale'] = true;
        if (!isset($lastGood['source'])) {
            $lastGood['source'] = 'last-good';
        }
        $this->writeAtomic($this->publishPath, $lastGood);
        $this->markAllDown();
        $this->log('all providers failed — serving stale last good (updated_at ' . $lastGood['updated_at'] . ')');
        return ['status' => 'stale', 'published' => $lastGood];
    }

    private function buildPayload(array $n, string $source, bool $stale): array
    {
        $tz = new DateTimeZone((string)($this->config['timezone'] ?? 'Asia/Tehran'));
        $coins = [];
        foreach (['emami', 'bahar', 'nim', 'rob', 'gerami'] as $k) {
            $coins[$k] = (int)($n['coins'][$k] ?? 0);
        }
        return [
            'updated_at' => (new DateTime('now', $tz))->format(DateTime::ATOM),
            'source' => $source,
            'stale' => $stale,
            'gold18' => (int)$n['gold18'],
            'mazaneh' => (int)$n['mazaneh'],
            'coins' => $coins,
        ];
    }

    /** نوشتن اتمیک: temp + rename — اپ هیچ‌وقت فایل نیمه‌نوشته نمی‌خواند */
    public function writeAtomic(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $path . '.tmp.' . getmypid();
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('cannot write temp file: ' . $tmp);
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('cannot rename temp file to: ' . $path);
        }
    }

    private function writeState(array $old, array $patch): void
    {
        if ($this->statePath === '') {
            return;
        }
        $this->writeAtomic($this->statePath, array_merge(is_array($old) ? $old : [], $patch));
    }

    // ─── lock ───
    private function acquireLock(): bool
    {
        if ($this->lockPath === null) {
            return true;
        }
        @mkdir(dirname($this->lockPath), 0775, true);
        $fp = fopen($this->lockPath, 'c');
        if ($fp === false) {
            $this->log('cannot open lock file — proceeding without lock');
            return true;
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return false;
        }
        $this->lockFp = $fp;
        return true;
    }

    private function releaseLock(): void
    {
        if ($this->lockFp !== null) {
            flock($this->lockFp, LOCK_UN);
            fclose($this->lockFp);
            $this->lockFp = null;
        }
    }

    // ─── هشدار همه منابع خراب ───
    private function markAllDown(): void
    {
        $state = $this->readJson($this->statePath) ?? [];
        $since = $state['all_down_since'] ?? null;
        if ($since === null) {
            $this->writeState($state, ['all_down_since' => date('c'), 'all_down_alerted' => false]);
            return;
        }
        $limit = (float)($this->config['cron']['alert_after_minutes'] ?? 15);
        $mins = (time() - strtotime((string)$since)) / 60;
        if ($mins >= $limit && empty($state['all_down_alerted'])) {
            $this->sendAlert('Talam prices: all providers failing for ' . (int)$limit . '+ minutes');
            $this->writeState($state, ['all_down_alerted' => true]);
        }
    }

    private function resetAllDown(): void
    {
        $state = $this->readJson($this->statePath) ?? [];
        if (!empty($state['all_down_since'])) {
            unset($state['all_down_since'], $state['all_down_alerted']);
            $this->writeState($state, []);
        }
    }

    /**
     * هوک هشدار — UNVERIFIED: قالب تلگرام با placeholder است.
     * در config: alert_webhook_url = 'https://api.telegram.org/bot<TOKEN>/sendMessage?chat_id=<ID>&text='
     */
    private function sendAlert(string $text): void
    {
        $url = (string)($this->config['cron']['alert_webhook_url'] ?? '');
        if ($url === '') {
            $this->log('ALERT (no webhook configured): ' . $text);
            return;
        }
        $full = $url . rawurlencode($text); // UNVERIFIED: قالب تلگرام — با سرویس خودت تطبیق بده
        @file_get_contents($full); // best-effort
        $this->log('ALERT sent: ' . $text);
    }

    // ─── ابزار ───
    private function readJson(string $path): ?array
    {
        if ($path === '' || !file_exists($path)) {
            return null;
        }
        $raw = (string)file_get_contents($path);
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function log(string $msg): void
    {
        if ($this->logPath === null) {
            return;
        }
        @mkdir(dirname($this->logPath), 0775, true);
        if (file_exists($this->logPath) && filesize($this->logPath) > $this->logMaxBytes) {
            for ($i = $this->logKeep; $i >= 1; $i--) {
                $from = $this->logPath . ($i > 1 ? '.' . ($i - 1) : '');
                $to = $this->logPath . '.' . $i;
                if (file_exists($from)) {
                    @rename($from, $to);
                }
            }
        }
        @file_put_contents($this->logPath, '[' . date('Y-m-d H:i:s P') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function logRejected(string $source, array $errors, array $data): void
    {
        $this->log('rejected from "' . $source . '": ' . implode('; ', $errors));
        if ($this->rejectedLog === null) {
            return;
        }
        @mkdir(dirname($this->rejectedLog), 0775, true);
        $entry = json_encode([
            'at' => date('c'),
            'source' => $source,
            'errors' => $errors,
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
        @file_put_contents($this->rejectedLog, $entry, FILE_APPEND | LOCK_EX);
    }
}
