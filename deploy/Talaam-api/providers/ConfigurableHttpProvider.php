<?php
/**
 * آداپتور همه‌کاره کاملاً کانفیگ‌محور — افزودن منبع جدید فقط با یک بلوک config.php، بدون تغییر کد.
 *
 * گزینه‌های options (همه در config.php — هیچ secret در کد/ریپو):
 *   id                  (اجباری) شناسه کوتاه، مثل 'example-provider'
 *   url                 (اجباری) https://… (http مسدود نیست ولی HTTPS توصیه میشود)
 *   method              GET (پیش‌فرض) | POST
 *   query               []  پارامترهای کوئری
 *   headers             []  هدرهای سفارشی ['X-Foo' => 'bar']
 *   auth                ['type' => 'query'|'header'|'bearer'|'' , 'name' => 'api_key', 'key' => '…' یا 'key_env' => 'ENV_NAME']
 *   map                 (اجباری) نگاشت به فیلدهای کانونی:
 *       'gold18'      => ['path' => 'data.gold.18k', 'unit' => 'toman'|'rial', 'multiplier' => 1.0]
 *       'mazaneh'     => [...]  (اختیاری)
 *       'coins.emami' => [...]  (اختیاری — هر سکه جدا)
 *   max_age_seconds     حداکثر عمر داده کش‌شده این منبع برای استفاده هنگام skip (پیش‌فرض: بدون استفاده از کش)
 *   min_interval_seconds  حداقل فاصله بین فراخوانی واقعی (احترام به rate limit منبع)
 *   daily_cap           سقف فراخوانی روزانه؛ 0 = بی‌سقف. نزدیک سقف، فاصله خودکار ۲ برابر میشود
 *   enabled             پیش‌فرض true
 *
 * paths: مسیر نقطه‌ای در پاسخ JSON مثل 'data.gold.18k.price'؛ عدد = ایندکس آرایه ('items.0.price')؛
 * کلید انجمنی = lookup ('coins.18k'). پاک‌سازی عدد: ارقام فارسی/عربی، جداکننده هزارگان و اعشار.
 * داده جزئی مجاز است — منبع میتواند فقط بعضی فیلدها را بدهد؛ pipeline merge میکند.
 */
class ConfigurableHttpProvider extends BaseProvider implements ProviderInterface
{
    public const CANONICAL_FIELDS = ['gold18', 'mazaneh', 'coins.emami', 'coins.bahar', 'coins.nim', 'coins.rob', 'coins.gerami'];

    /** @var string */
    private $pid;
    /** @var string */
    private $url;
    /** @var string */
    private $method;
    /** @var array<string,string> */
    private $query;
    /** @var array<string,string> */
    private $headers;
    /** @var array<string,mixed> */
    private $auth;
    /** @var array<string,array> */
    private $map;
    /** @var bool */
    private $enabled;
    /** @var int|null */
    private $maxAge;
    /** @var int */
    private $minInterval;
    /** @var int */
    private $dailyCap;

    public function __construct(array $options = [], array $http = [])
    {
        parent::__construct($options, $http);
        $pid = trim((string)($options['id'] ?? ''));
        if ($pid === '') {
            throw new RuntimeException(static::class . ": option 'id' is required (bad provider config)");
        }
        $this->pid = $pid;

        $url = trim((string)($options['url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            throw new RuntimeException(static::class . " [$pid]: option 'url' must be a non-empty http(s) URL");
        }
        $this->url = $url;

        $this->method = strtoupper((string)($options['method'] ?? 'GET'));
        if (!in_array($this->method, ['GET', 'POST'], true)) {
            throw new RuntimeException(static::class . " [$pid]: method must be GET or POST");
        }

        $this->query = is_array($options['query'] ?? null) ? $options['query'] : [];
        $this->headers = is_array($options['headers'] ?? null) ? $options['headers'] : [];
        $this->auth = is_array($options['auth'] ?? null) ? $options['auth'] : [];
        $authType = strtolower((string)($this->auth['type'] ?? ''));
        if (!in_array($authType, ['', 'query', 'header', 'bearer'], true)) {
            throw new RuntimeException(static::class . " [$pid]: auth.type must be query|header|bearer (got '$authType')");
        }

        $map = $options['map'] ?? null;
        if (!is_array($map) || empty($map)) {
            throw new RuntimeException(static::class . " [$pid]: option 'map' is required and must map at least one field");
        }
        foreach ($map as $field => $spec) {
            if (!in_array((string)$field, self::CANONICAL_FIELDS, true)) {
                throw new RuntimeException(static::class . " [$pid]: unknown mapped field '$field' — allowed: " . implode(', ', self::CANONICAL_FIELDS));
            }
            if (!is_array($spec) || empty($spec['path'])) {
                throw new RuntimeException(static::class . " [$pid]: map.$field must have a 'path'");
            }
        }
        $this->map = $map;

        $this->enabled = (bool)($options['enabled'] ?? true);
        $this->maxAge = isset($options['max_age_seconds']) && (int)$options['max_age_seconds'] > 0 ? (int)$options['max_age_seconds'] : null;
        $this->minInterval = (int)($options['min_interval_seconds'] ?? 0);
        $this->dailyCap = (int)($options['daily_cap'] ?? 0);
    }

    public function id(): string
    {
        return $this->pid;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** حداکثر عمر داده کش‌شده این منبع (ثانیه) — null = از کش استفاده نشود */
    public function getMaxAgeSeconds(): ?int
    {
        return $this->maxAge;
    }

    /** آیا نوبت این منبع است؟ (daily cap + min interval — نزدیک سقف، فاصله ۲ برابر) */
    public function isDue(array $providerState): array
    {
        $today = date('Ymd');
        $count = (int)($providerState['daily'][$today] ?? 0);
        if ($this->dailyCap > 0 && $count >= $this->dailyCap) {
            return ['due' => false, 'reason' => "daily cap reached ($count/{$this->dailyCap})"];
        }
        $interval = $this->minInterval;
        if ($this->dailyCap > 0 && $count >= (int)floor($this->dailyCap * 0.8)) {
            $interval *= 2; // نزدیک سقف → فاصله بیشتر
        }
        $last = (int)($providerState['last_call_ts'] ?? 0);
        if ($interval > 0 && $last > 0) {
            $elapsed = time() - $last;
            if ($elapsed < $interval) {
                return ['due' => false, 'reason' => 'min interval — due in ' . ($interval - $elapsed) . 's'];
            }
        }
        return ['due' => true, 'reason' => 'ok'];
    }

    public function fetch(): array
    {
        if (!$this->enabled) {
            throw new RuntimeException('provider disabled in config');
        }
        [$url, $headers] = $this->buildRequest();
        $payload = $this->method === 'POST'
            ? $this->httpPostJson($url, $this->query, $headers)
            : $this->httpGetJson($url, $headers);
        return $this->mapPayload($payload);
    }

    /** توضیح بدون secret — برای --diagnose */
    public function describe(): array
    {
        return [
            'id' => $this->pid,
            'enabled' => $this->enabled,
            'method' => $this->method,
            'url_masked' => $this->maskedUrl(),
            'paths' => array_map(static fn (array $s) => $s['path'], $this->map),
            'max_age_seconds' => $this->maxAge,
            'min_interval_seconds' => $this->minInterval,
            'daily_cap' => $this->dailyCap,
        ];
    }

    // ─── request building ───
    protected function authKey(): string
    {
        $type = strtolower((string)($this->auth['type'] ?? ''));
        if ($type === '') {
            return '';
        }
        if (!empty($this->auth['key'])) {
            return (string)$this->auth['key'];
        }
        if (!empty($this->auth['key_env'])) {
            $v = getenv((string)$this->auth['key_env']);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
        throw new RuntimeException(static::class . " [{$this->pid}]: auth.key / auth.key_env not configured");
    }

    protected function buildRequest(): array
    {
        $authType = strtolower((string)($this->auth['type'] ?? ''));
        $key = $this->authKey();
        $query = $this->query;
        $headers = $this->headers;
        if ($authType === 'query' && $key !== '') {
            $query[(string)($this->auth['name'] ?? 'api_key')] = $key;
        } elseif ($authType === 'header' && $key !== '') {
            $headers[(string)($this->auth['name'] ?? 'X-Api-Key')] = $key;
        } elseif ($authType === 'bearer' && $key !== '') {
            $headers['Authorization'] = 'Bearer ' . $key;
        }
        $url = $this->url;
        if (!empty($query)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }
        return [$url, $headers];
    }

    protected function maskedUrl(): string
    {
        [$url] = $this->buildRequest();
        $authType = strtolower((string)($this->auth['type'] ?? ''));
        $key = $this->auth['type'] ?? '' !== '' ? $this->authKeySafe() : '';
        if ($key !== '') {
            $url = str_replace($key, '***', $url);
        }
        if ($authType === 'query') {
            // مقدار پارامتر کلید در URL ماسک شود
            $name = (string)($this->auth['name'] ?? 'api_key');
            $url = preg_replace('/(' . preg_quote($name, '/') . '=)[^&]+/i', '$1***', $url) ?? $url;
        }
        return $url;
    }

    private function authKeySafe(): string
    {
        try {
            return $this->authKey();
        } catch (Throwable $e) {
            return '';
        }
    }

    // ─── mapping ───
    protected function mapPayload(array $payload): array
    {
        $res = [
            'gold18' => null,
            'mazaneh' => null,
            'coins' => ['emami' => null, 'bahar' => null, 'nim' => null, 'rob' => null, 'gerami' => null],
        ];
        $found = 0;
        foreach ($this->map as $field => $spec) {
            $raw = $this->getPath($payload, (string)$spec['path']);
            $v = $this->cleanNumber($raw);
            if ($v === null) {
                continue; // فیلد جزئی — ارائه نشده
            }
            $unit = strtolower((string)($spec['unit'] ?? 'toman'));
            if ($unit === 'rial') {
                $v = $v / 10;
            }
            $mult = (float)($spec['multiplier'] ?? 1.0);
            $v = $v * $mult;
            if ($field === 'gold18') {
                $res['gold18'] = (int)round($v);
            } elseif ($field === 'mazaneh') {
                $res['mazaneh'] = (int)round($v);
            } else {
                $res['coins'][substr((string)$field, 6)] = (int)round($v);
            }
            $found++;
        }
        if ($found === 0) {
            throw new RuntimeException('none of the configured paths were found in the response (check map paths against the actual payload)');
        }
        return $res;
    }

    /** مسیر نقطه‌ای: کلید انجمنی یا ایندکس عددی آرایه */
    protected function getPath(array $payload, string $path)
    {
        $cur = $payload;
        foreach (explode('.', $path) as $seg) {
            if (is_array($cur) && array_key_exists($seg, $cur)) {
                $cur = $cur[$seg];
            } else {
                return null;
            }
        }
        return $cur;
    }

    /**
     * پاک‌سازی عدد رشته‌ای: ارقام فارسی/عربی → انگلیسی، جداکننده هزارگان (٬ , ، فاصله) حذف،
     * ممیز ٫ و . → نقطه. خروجی float یا null.
     */
    protected function cleanNumber($raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            return is_finite((float)$raw) ? (float)$raw : null;
        }
        if (!is_string($raw)) {
            return null;
        }
        $s = trim($raw);
        if ($s === '') {
            return null;
        }
        $map = [];
        $fa = [
            '۰' => '0',
            '۱' => '1',
            '۲' => '2',
            '۳' => '3',
            '۴' => '4',
            '۵' => '5',
            '۶' => '6',
            '۷' => '7',
            '۸' => '8',
            '۹' => '9',
        ];
        $ar = [
            '٠' => '0',
            '١' => '1',
            '٢' => '2',
            '٣' => '3',
            '٤' => '4',
            '٥' => '5',
            '٦' => '6',
            '٧' => '7',
            '٨' => '8',
            '٩' => '9',
        ];
        foreach (array_merge($fa, $ar) as $k => $v) {
            $map[$k] = $v;
        }
        $s = strtr($s, $map);
        $s = strtr($s, [
            "\u{066C}" => '',  // ٬ هزارگان
            "\u{060C}" => '',  // ،
            ',' => '',
            ' ' => '',
            "\u{00A0}" => '',
            "\u{2007}" => '',
            "\u{202F}" => '',
            "\u{066B}" => '.', // ٫ اعشار
        ]);
        $s = preg_replace('/[^0-9.\-]/u', '', $s) ?? '';
        if ($s === '' || !is_numeric($s)) {
            return null;
        }
        return (float)$s;
    }
}
