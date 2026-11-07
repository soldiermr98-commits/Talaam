<?php
/**
 * کلاس پایه آداپتورها: HTTP با تایم‌اوت و retry/backoff + تبدیل واحد.
 * هیچ کلید یا آدرس واقعی داخل کد نیست — همه از config.php (git-ignored) می‌آید.
 */
abstract class BaseProvider implements ProviderInterface
{
    /** @var array<string,mixed> */
    protected $options;
    /** @var int */
    protected $httpTimeout;
    /** @var int */
    protected $retries;
    /** @var int */
    protected $backoffMs;

    public function __construct(array $options = [], array $http = [])
    {
        $this->options = $options;
        $this->httpTimeout = (int)($http['timeout_seconds'] ?? 5);
        $this->retries = (int)($http['retries'] ?? 2);
        $this->backoffMs = (int)($http['retry_backoff_ms'] ?? 400);
    }

    /** GET یک JSON؛ با retry و backoff؛ شکست → RuntimeException */
    protected function httpGetJson(string $url, array $headers = []): array
    {
        $attempt = 0;
        $lastError = '';
        while ($attempt <= $this->retries) {
            try {
                return $this->httpGetJsonOnce($url, $headers);
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                $attempt++;
                if ($attempt <= $this->retries) {
                    usleep($this->backoffMs * 1000 * $attempt); // backoff افزایشی
                }
            }
        }
        throw new RuntimeException(static::class . " HTTP failed after " . ($this->retries + 1) . " attempts: $lastError");
    }

    private function httpGetJsonOnce(string $url, array $headers = []): array
    {
        $raw = $this->httpGet($url, $headers);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('response is not a valid JSON object');
        }
        return $data;
    }

    /** GET خام؛ با curl در صورت وجود وگرنه stream */
    protected function httpGet(string $url, array $headers = []): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opt = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->httpTimeout,
                CURLOPT_CONNECTTIMEOUT => $this->httpTimeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'talam-prices/1.0',
            ];
            $hdrs = [];
            foreach ($headers as $k => $v) {
                $hdrs[] = (is_int($k) ? $v : $k . ': ' . $v);
            }
            if (!empty($hdrs)) {
                $opt[CURLOPT_HTTPHEADER] = $hdrs;
            }
            curl_setopt_array($ch, $opt);
            $body = curl_exec($ch);
            $err = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($body === false || $code >= 400) {
                throw new RuntimeException("HTTP $code $err");
            }
            return (string)$body;
        }
        $ctx = stream_context_create([
            'http' => [
                'timeout' => $this->httpTimeout,
                'ignore_errors' => true,
                'header' => "User-Agent: talam-prices/1.0\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new RuntimeException('HTTP failed (stream wrapper)');
        }
        return $body;
    }

    /**
     * تبدیل واحد به تومان.
     * ⚠️ خطای ۱۰x (ریال/تومان اشتباه) اینجا خودکار اصلاح نمی‌شود —
     * validation نسبت مظنه و jump-guard در PricePipeline آن را می‌گیرد (تست‌شده).
     */
    protected function toToman($value, string $unit): int
    {
        $v = (float)$value;
        if ($unit === 'rial') {
            $v = $v / 10;
        }
        return (int)round($v);
    }

    /** خواندن گزینه اجباری؛ مقدار placeholder (TODO/REPLACE/خالی) → RuntimeException */
    protected function requireOption(string $key): string
    {
        $v = trim((string)($this->options[$key] ?? ''));
        if ($v === '' || stripos($v, 'TODO') === 0 || stripos($v, 'REPLACE') === 0) {
            throw new RuntimeException(static::class . ": option '$key' not configured (unverified provider — see server/README_SERVER.md)");
        }
        return $v;
    }
}
