<?php
/**
 * هسته خط تولید نرخ — تست‌پذیر (providers از بیرون تزریق میشوند) + dry-run.
 *
 * جریان: lock → منابع به ترتیب اولویت (due/enable/skip+cache) → merge جزئی فیلدها
 * → validate سطح merged → jump-guard (تأیید منبع دوم / N اجر متوالی) → انتشار اتمیک
 * → همه شکست؟ stale با updated_at اصلی (هیچ داده جعلی).
 *
 * داده جزئی: هر منبع میتواند فقط بعضی فیلدها را بدهد؛ merge در ترتیب اولویت انجام میشود،
 * منبعِ هر فیلد در state ثبت میشود و source خروجی مثل "a+b" میشود.
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
    /** @var bool */
    private $dryRun = false;

    public function __construct(array $config, array $providers)
    {
        $this->config = $config;
        $this->providers = $providers;
        $paths = $config['paths'] ?? [];
        $this->lastGoodPath = (string)($paths['last_good'] ?? '');
        $this->publishPath = (string)($paths['publish'] ?? '');
        $this->statePath = (string)($paths['state'] ?? '');
        $cron = $config['cron'] ?? [];
        $this->lockPath = isset($cron['lock_path']) && $cron['lock_path'] !== '' ? (string)$cron['lock_path'] : null;
        $this->logPath = isset($cron['log_path']) && $cron['log_path'] !== '' ? (string)$cron['log_path'] : null;
        $this->logMaxBytes = (int)($cron['log_max_bytes'] ?? 524288);
        $this->logKeep = (int)($cron['log_keep'] ?? 3);
        $this->rejectedLog = isset($cron['rejected_log']) && $cron['rejected_log'] !== '' ? (string)$cron['rejected_log'] : null;
    }

    public function setDryRun(bool $v): void
    {
        $this->dryRun = $v;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * یک سیکل کامل اجرا میکند.
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
            $state = $this->readJson($this->statePath) ?? [];
            $ps = is_array($state['provider_state'] ?? null) ? $state['provider_state'] : [];
            $schedule = is_array($this->config['schedule'] ?? null) ? $this->config['schedule'] : [];

            // بررسی ساعات خاموشی شبانه (مثلاً ۲۳ تا ۶ صبح): هیچ درخواستی به APIها زده نشود، فقط اگر override دستی فعال بود اعمال شود
            $tz = new DateTimeZone((string)($this->config['timezone'] ?? 'Asia/Tehran'));
            $currentHour = isset($schedule['force_hour']) ? (int)$schedule['force_hour'] : (int)(new DateTime('now', $tz))->format('G');
            $sleepHours = is_array($schedule['sleep_hours'] ?? null) ? $schedule['sleep_hours'] : null;
            $inSleepWindow = false;
            if ($sleepHours !== null && count($sleepHours) >= 2) {
                $shStart = (int)$sleepHours[0];
                $shEnd = (int)$sleepHours[1];
                $inSleepWindow = ($shStart <= $shEnd)
                    ? ($currentHour >= $shStart && $currentHour < $shEnd)
                    : ($currentHour >= $shStart || $currentHour < $shEnd);
            }

            // چرخش نوبتی (Round-Robin) بین پروایدرهای غیر دستی (در صورت فعال بودن در schedule)
            $orderedProviders = $this->providers;
            $rrCursor = (int)($state['rr_cursor'] ?? 0);
            $nextRrCursor = $rrCursor;
            if (!empty($schedule['round_robin']) && count($orderedProviders) > 1) {
                $apiList = [];
                $manualList = [];
                foreach ($orderedProviders as $p) {
                    if ($p instanceof ManualProvider) {
                        $manualList[] = $p;
                    } else {
                        $apiList[] = $p;
                    }
                }
                $nApi = count($apiList);
                if ($nApi > 1) {
                    $offset = $rrCursor % $nApi;
                    $rotated = array_merge(array_slice($apiList, $offset), array_slice($apiList, 0, $offset));
                    $orderedProviders = array_merge($rotated, $manualList);
                    $nextRrCursor = ($rrCursor + 1) % $nApi;
                }
            }

            $merged = ['gold18' => null, 'mazaneh' => null, 'coins' => ['emami' => null, 'bahar' => null, 'nim' => null, 'rob' => null, 'gerami' => null]];
            $fieldSource = []; // field => provider id (داخلی)
            $fieldTs = [];     // field => unix ts
            $proposals = [];   // field => [[id, value], ...] برای تأیید متقاطع
            $contributed = []; // ترتیب منابع مشارکت‌کننده
            $contributedLabels = [];

            foreach ($orderedProviders as $provider) {
                $pid = $provider->id();
                $plabel = method_exists($provider, 'label') ? (string)$provider->label() : ($pid === 'manual' ? 'نرخ دستی' : $pid);
                if ($inSleepWindow && !($provider instanceof ManualProvider)) {
                    $this->log('provider "' . $pid . '" skipped: night sleep window (' . $currentHour . ':00)');
                    continue;
                }
                if (method_exists($provider, 'isEnabled') && !$provider->isEnabled()) {
                    $this->log('provider "' . $pid . '" disabled — skipped');
                    continue;
                }
                $due = method_exists($provider, 'isDue')
                    ? $provider->isDue($ps[$pid] ?? [], $currentHour)
                    : ['due' => true, 'reason' => 'ok'];

                $data = null;
                $dataTs = 0;
                if ($due['due']) {
                    try {
                        $data = $provider->fetch();
                        $errors = $this->validatePartial($data);
                        if (!empty($errors)) {
                            $this->logRejected($pid, $errors, $data);
                            $data = null;
                        } else {
                            $dataTs = time();
                            if (method_exists($provider, 'isDue')) {
                                // شمارنده روزانه، ماهانه + زمان آخرین فراخوانی واقعی + ذخیره آخرین داده سالم
                                $today = date('Ymd');
                                $month = date('Ym');
                                $ps[$pid]['daily'][$today] = (int)($ps[$pid]['daily'][$today] ?? 0) + 1;
                                $ps[$pid]['monthly'][$month] = (int)($ps[$pid]['monthly'][$month] ?? 0) + 1;
                                $ps[$pid]['last_call_ts'] = time();
                                $ps[$pid]['last_data'] = $data;
                                $ps[$pid]['last_data_ts'] = $dataTs;
                            }
                        }
                    } catch (Throwable $e) {
                        $this->log('provider "' . $pid . '" failed: ' . $e->getMessage());
                        $data = null;
                    }
                } else {
                    $this->log('provider "' . $pid . '" skipped: ' . $due['reason']);
                }

                // cache fallback — وقتی skip/fail شد و داده کش سالمی در بازه max_age دارد
                if ($data === null) {
                    $ld = $ps[$pid]['last_data'] ?? null;
                    $ts = (int)($ps[$pid]['last_data_ts'] ?? 0);
                    $maxAge = method_exists($provider, 'getMaxAgeSeconds') ? $provider->getMaxAgeSeconds() : null;
                    if (is_array($ld) && $maxAge !== null && $ts > 0 && (time() - $ts) <= $maxAge) {
                        $data = $ld;
                        $dataTs = $ts;
                        $this->log('provider "' . $pid . '" — using cached data (' . (time() - $ts) . 's old)');
                    }
                }
                if ($data === null) {
                    continue;
                }
                $beforeCount = count($contributed);
                $this->mergeInto($merged, $data, $pid, $fieldSource, $fieldTs, $proposals, $contributed, $dataTs);
                if (count($contributed) > $beforeCount && !in_array($plabel, $contributedLabels, true)) {
                    $contributedLabels[] = $plabel;
                }
            }

            $state['rr_cursor'] = $nextRrCursor;
            $this->writeProviderState($state, $ps);
            $state = $this->readJson($this->statePath) ?? $state;
            // ثبت منبع هر فیلد (لاگ داخلی — در JSON عمومی فقط source تجمیعی میرود)
            $this->writeState($state, ['field_source' => $fieldSource, 'rr_cursor' => $nextRrCursor]);

            if ($merged['gold18'] === null) {
                if ($inSleepWindow && is_array($lastGood) && isset($lastGood['gold18']) && (float)$lastGood['gold18'] > 0) {
                    $this->log('night sleep window — keeping last published rates without external API call');
                    return ['status' => 'ok', 'published' => $lastGood];
                }
                return $this->serveStale($lastGood);
            }
            if ($merged['mazaneh'] === null) {
                $merged['mazaneh'] = (int)round($merged['gold18'] * 4.3318);
            }

            // سطح merged: ترتیب سکه‌ها، plausibility سکه نسبت به گرم، نسبت مظنه
            $mergedErrors = $this->validateNormalized($merged);
            if (!empty($mergedErrors)) {
                $this->logRejected('merged', $mergedErrors, $merged);
                $this->log('merged data failed validation — serving stale');
                return $this->serveStale($lastGood);
            }

            if (!$this->jumpGuardPasses($fieldSource, $merged, $lastGood, $proposals)) {
                return $this->serveStale($lastGood);
            }

            $sourceStr = implode('+', array_unique($contributed));
            if ($sourceStr === '') {
                $sourceStr = 'unknown';
            }
            $sourceLabelStr = implode(' + ', array_unique($contributedLabels));
            $payload = $this->buildPayload($merged, $sourceStr, false, $fieldTs, $sourceLabelStr);
            $this->writeAtomic($this->lastGoodPath, $payload);
            $this->writeAtomic($this->publishPath, $payload);
            $this->resetAllDown();
            $this->log(($this->dryRun ? '[dry-run] would publish' : 'published') . ' from "' . $sourceStr . '" (gold18=' . $payload['gold18'] . ')');
            return ['status' => 'ok', 'published' => $payload];
        } finally {
            $this->releaseLock();
        }
    }

    /** گزارش تشخیصی برای --diagnose — بدون هیچ secret */
    public function diagnose(): array
    {
        $state = $this->readJson($this->statePath) ?? [];
        $ps = is_array($state['provider_state'] ?? null) ? $state['provider_state'] : [];
        $out = [];
        foreach ($this->providers as $provider) {
            $pid = $provider->id();
            $entry = ['id' => $pid, 'class' => get_class($provider)];
            if (method_exists($provider, 'describe')) {
                $entry['config'] = $provider->describe(); // بدون secret
            }
            $entry['rate_limit'] = method_exists($provider, 'isDue')
                ? $provider->isDue($ps[$pid] ?? [])
                : ['due' => true, 'reason' => 'n/a (stateless)'];
            if (isset($ps[$pid]['daily']) && is_array($ps[$pid]['daily'])) {
                $today = date('Ymd');
                $entry['daily_calls_today'] = (int)($ps[$pid]['daily'][$today] ?? 0);
            }
            try {
                $t0 = microtime(true);
                $data = $provider->fetch();
                $lat = (int)round((microtime(true) - $t0) * 1000);
                $entry['reachable'] = true;
                $entry['latency_ms'] = $lat;
                $fields = [];
                foreach (['gold18', 'mazaneh'] as $f) {
                    if (isset($data[$f]) && is_numeric($data[$f]) && (float)$data[$f] > 0) {
                        $fields[$f] = (int)$data[$f];
                    }
                }
                foreach (($data['coins'] ?? []) as $k => $v) {
                    if (is_numeric($v) && (float)$v > 0) {
                        $fields['coins.' . $k] = (int)$v;
                    }
                }
                $entry['parsed_fields'] = $fields;
                $errors = $this->validatePartial($data);
                $entry['validation'] = empty($errors) ? 'ok' : implode('; ', $errors);
            } catch (Throwable $e) {
                $entry['reachable'] = false;
                $entry['error'] = $e->getMessage();
            }
            $out[] = $entry;
        }
        return [
            'dry_run_note' => 'no secrets are included in this output',
            'providers' => $out,
        ];
    }

    /** اعتبارسنجی داده جزئی منبع — فقط روی فیلدهای ارائهشده */
    public function validatePartial(array $data): array
    {
        $th = $this->config['thresholds'] ?? [];
        $errors = [];
        $g = isset($data['gold18']) && is_numeric($data['gold18']) ? (float)$data['gold18'] : null;
        if ($g !== null) {
            if (!is_finite($g) || $g <= 0) {
                $errors[] = 'gold18 invalid';
            } elseif ($g < (float)($th['gold18_min'] ?? 100000)) {
                $errors[] = "gold18 below plausible min ($g)";
            } elseif ($g > (float)($th['gold18_max'] ?? 2000000000)) {
                $errors[] = "gold18 above plausible max ($g)";
            }
        }
        $m = isset($data['mazaneh']) && is_numeric($data['mazaneh']) ? (float)$data['mazaneh'] : null;
        if ($m !== null && (!is_finite($m) || $m <= 0)) {
            $errors[] = 'mazaneh invalid';
        }
        $coins = is_array($data['coins'] ?? null) ? $data['coins'] : [];
        foreach ($coins as $k => $v) {
            if (!is_numeric($v) || !is_finite((float)$v) || (float)$v < 0) {
                $errors[] = "coins.$k invalid";
            } elseif ($g !== null && (float)$v > $g * (float)($th['coin_max_gold18_ratio'] ?? 100)) {
                $errors[] = "coins.$k implausible vs gold18";
            }
        }
        // sanity ترتیب (فقط وقتی هر دو > ۰)
        if (($coins['emami'] ?? 0) > 0 && ($coins['nim'] ?? 0) > 0 && (float)$coins['emami'] < (float)$coins['nim']) {
            $errors[] = 'ordering sanity failed: emami < nim';
        }
        if (($coins['nim'] ?? 0) > 0 && ($coins['rob'] ?? 0) > 0 && (float)$coins['nim'] < (float)$coins['rob']) {
            $errors[] = 'ordering sanity failed: nim < rob';
        }
        // خودسازگاری منبع: نسبت مظنه/گرم وقتی هر دو از همین منبع‌اند
        if ($g !== null && $m !== null && $m > 0) {
            $ratio = $m / $g;
            $expected = (float)($th['mazaneh_ratio'] ?? 4.3318);
            $tol = (float)($th['mazaneh_tolerance'] ?? 0.5);
            if (abs($ratio - $expected) > $tol) {
                $errors[] = sprintf('provider self-consistency: mazaneh/gold18 ratio %.3f outside %.3f ± %.2f', $ratio, $expected, $tol);
            }
        }
        return $errors;
    }

    /** اعتبارسنجی داده merged نهایی */
    public function validateNormalized(array $n): array
    {
        $th = $this->config['thresholds'] ?? [];
        $errors = [];
        $g = (float)($n['gold18'] ?? 0);
        if ($g <= 0) {
            $errors[] = 'gold18 missing';
            return $errors;
        }
        $coins = is_array($n['coins'] ?? null) ? $n['coins'] : [];
        $coinMax = $g * (float)($th['coin_max_gold18_ratio'] ?? 100);
        foreach ($coins as $k => $v) {
            if ($v === null) {
                continue; // ارائه نشده — buildPayload صفر میگذارد
            }
            if (!is_numeric($v) || (float)$v < 0) {
                $errors[] = "coins.$k invalid";
            } elseif ((float)$v > $coinMax) {
                $errors[] = "coins.$k implausible ($v > " . (int)$coinMax . ')';
            }
        }
        if (($coins['emami'] ?? 0) > 0 && ($coins['nim'] ?? 0) > 0 && (float)$coins['emami'] < (float)$coins['nim']) {
            $errors[] = 'ordering sanity failed: emami < nim';
        }
        if (($coins['nim'] ?? 0) > 0 && ($coins['rob'] ?? 0) > 0 && (float)$coins['nim'] < (float)$coins['rob']) {
            $errors[] = 'ordering sanity failed: nim < rob';
        }
        $m = (float)($n['mazaneh'] ?? 0);
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

    /** merge جزئی: هر فیلد فقط از اولین منبعِ اولویت‌دارِ ارائهکننده پر میشود */
    private function mergeInto(array &$merged, array $data, string $pid, array &$fieldSource, array &$fieldTs, array &$proposals, array &$contributed, int $ts): void
    {
        foreach (['gold18', 'mazaneh'] as $f) {
            $v = $data[$f] ?? null;
            if (is_numeric($v) && (float)$v > 0) {
                $proposals[$f][] = ['id' => $pid, 'value' => (float)$v];
                if ($merged[$f] === null) {
                    $merged[$f] = (int)round((float)$v);
                    $fieldSource[$f] = $pid;
                    $fieldTs[$f] = $ts;
                    $this->markContributed($contributed, $pid);
                }
            }
        }
        foreach (($data['coins'] ?? []) as $k => $v) {
            if (!array_key_exists($k, $merged['coins'])) {
                continue;
            }
            if (is_numeric($v) && (float)$v > 0) {
                $field = 'coins.' . $k;
                $proposals[$field][] = ['id' => $pid, 'value' => (float)$v];
                if ($merged['coins'][$k] === null) {
                    $merged['coins'][$k] = (int)round((float)$v);
                    $fieldSource[$field] = $pid;
                    $fieldTs[$field] = $ts;
                    $this->markContributed($contributed, $pid);
                }
            }
        }
    }

    private function markContributed(array &$contributed, string $pid): void
    {
        if (!in_array($pid, $contributed, true)) {
            $contributed[] = $pid;
        }
    }

    /**
     * گارد پرش: تغیر بیش از max_jump_pct نسبت به last_good رد میشود،
     * مگر با تأیید یک منبع دیگرِ ارائهکننده همان فیلد، یا N اجر متوالی همسان (state).
     */
    private function jumpGuardPasses(array $fieldSource, array $merged, ?array $lastGood, array $proposals): bool
    {
        if (!is_array($lastGood) || !isset($lastGood['gold18']) || (float)$lastGood['gold18'] <= 0) {
            return true; // اولین داده موفق
        }
        $th = $this->config['thresholds'] ?? [];
        $maxPct = (float)($th['max_jump_pct'] ?? 5.0);
        $confirmRuns = (int)($th['jump_confirm_runs'] ?? 3);

        $fields = ['gold18'];
        foreach (['emami', 'bahar', 'nim', 'rob', 'gerami'] as $k) {
            if (($merged['coins'][$k] ?? 0) > 0 && (($lastGood['coins'][$k] ?? 0) > 0)) {
                $fields[] = 'coins.' . $k;
            }
        }

        $state = $this->readJson($this->statePath) ?? [];
        $consecutive = is_array($state['consecutive'] ?? null) ? $state['consecutive'] : [];
        $newConsecutive = [];
        $jumped = [];

        foreach ($fields as $f) {
            $new = $f === 'gold18' ? (float)$merged['gold18'] : (float)$merged['coins'][substr($f, 6)];
            $old = $f === 'gold18' ? (float)$lastGood['gold18'] : (float)($lastGood['coins'][substr($f, 6)] ?? 0);
            if ($old <= 0 || $new <= 0) {
                continue;
            }
            $pct = abs($new - $old) / $old * 100;
            if ($pct <= $maxPct) {
                $newConsecutive[$f] = ['value' => $new, 'count' => 0];
                continue;
            }
            // تأیید متقاطع: یک منبع دیگرِ ارائهکننده همین فیلد با مقدار نزدیک
            $confirmedBySecond = false;
            foreach (($proposals[$f] ?? []) as $prop) {
                if ($prop['id'] === ($fieldSource[$f] ?? '')) {
                    continue; // منبع خودِ فیلد نمیتواند خودش را تأیید کند
                }
                if (abs((float)$prop['value'] - $new) / $new * 100 <= $maxPct) {
                    $confirmedBySecond = true;
                    break;
                }
            }
            if ($confirmedBySecond) {
                $this->log("jump on $f confirmed by another provider — accepted");
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
                $newConsecutive[$f] = ['value' => $new, 'count' => 0];
                continue;
            }
            $jumped[] = sprintf('%s changed %.2f%% (%d → %d), consecutive %d/%d', $f, $pct, (int)$old, (int)$new, $count, $confirmRuns);
        }

        $this->writeState($state, ['consecutive' => $newConsecutive]);

        if (empty($jumped)) {
            return true;
        }
        foreach ($jumped as $j) {
            $this->logRejected('jump-guard', [$j], $merged);
        }
        return false;
    }

    /** همه منابع شکست خوردند → last good با stale=true و updated_at اصلی */
    private function serveStale(?array $lastGood): array
    {
        if (!is_array($lastGood) || !isset($lastGood['gold18']) || (float)$lastGood['gold18'] <= 0) {
            $this->markAllDown();
            $this->log('all providers failed AND no usable last good — nothing published');
            return ['status' => 'error', 'published' => null];
        }
        $lastGood['stale'] = true;
        if (!isset($lastGood['source'])) {
            $lastGood['source'] = 'last-good';
        }
        $lastGood['schema_version'] = (int)($lastGood['schema_version'] ?? 1);
        $this->writeAtomic($this->publishPath, $lastGood);
        $this->markAllDown();
        $this->log('all providers failed — serving stale last good (updated_at ' . $lastGood['updated_at'] . ')');
        return ['status' => 'stale', 'published' => $lastGood];
    }

    private function buildPayload(array $n, string $source, bool $stale, array $fieldTs = [], string $sourceLabel = ''): array
    {
        $tz = new DateTimeZone((string)($this->config['timezone'] ?? 'Asia/Tehran'));
        $nowIso = (new DateTime('now', $tz))->format(DateTime::ATOM);
        $coins = [];
        foreach (['emami', 'bahar', 'nim', 'rob', 'gerami'] as $k) {
            $coins[$k] = (int)($n['coins'][$k] ?? 0);
        }
        $payload = [
            'schema_version' => 1,
            'updated_at' => $nowIso,
            'source' => $source,
            'source_label' => $sourceLabel !== '' ? $sourceLabel : $source,
            'stale' => $stale,
            'gold18' => (int)$n['gold18'],
            'mazaneh' => (int)$n['mazaneh'],
            'coins' => $coins,
        ];
        if (!empty($fieldTs)) {
            $byField = [];
            foreach ($fieldTs as $field => $ts) {
                if ($ts > 0) {
                    $byField[$field] = date('c', $ts);
                }
            }
            if (!empty($byField)) {
                $payload['updated_at_by_field'] = $byField; // additive — اپ ناشناخته‌ها را نادیده میگیرد
            }
        }
        return $payload;
    }

    /** نوشتن اتمیک: temp + rename — اپ هیچوقت فایل نیمهنوشته نمیخواند (در dry-run غیرفعال) */
    public function writeAtomic(string $path, array $data): void
    {
        if ($this->dryRun) {
            return;
        }
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
        if ($this->statePath === '' || $this->dryRun) {
            return;
        }
        $this->writeAtomic($this->statePath, array_merge(is_array($old) ? $old : [], $patch));
    }

    private function writeProviderState(array $state, array $ps): void
    {
        if ($this->statePath === '' || $this->dryRun) {
            return;
        }
        $this->writeAtomic($this->statePath, array_merge(is_array($state) ? $state : [], ['provider_state' => $ps]));
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

    /** هوک هشدار — UNVERIFIED: قالب تلگرام با placeholder است. */
    private function sendAlert(string $text): void
    {
        $url = (string)($this->config['cron']['alert_webhook_url'] ?? '');
        if ($url === '') {
            $this->log('ALERT (no webhook configured): ' . $text);
            return;
        }
        $full = $url . rawurlencode($text); // UNVERIFIED: با سرویس خودت تطبیق بده
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
