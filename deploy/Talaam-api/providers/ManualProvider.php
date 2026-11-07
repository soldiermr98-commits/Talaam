<?php
/**
 * منبع اضطراری دستی: یک فایل JSON که خودت ویرایش می‌کنی.
 * مسیر فایل در config.php: providers[].options.path (پیش‌فرض data/override.json)
 *
 * ساختار data/override.json (نمونه در data/override.json.example):
 * {
 *   "expires_at": "2026-01-01T12:00:00+03:30",   ← اجباری؛ بعد از این زمان منبع نادیده گرفته می‌شود
 *   "gold18": 8000000,
 *   "mazaneh": 34654400,                          ← اختیاری؛ جاافتاده = از gold18 محاسبه می‌شود
 *   "coins": { "emami": 88000000 }                ← اختیاری؛ جاافتاده = ۰
 * }
 */
class ManualProvider implements ProviderInterface
{
    /** @var string */
    private $path;

    public function __construct(array $options = [], array $http = [])
    {
        $this->path = (string)($options['path'] ?? '');
    }

    public function id(): string
    {
        return 'manual';
    }

    public function fetch(): array
    {
        if ($this->path === '' || !file_exists($this->path)) {
            throw new RuntimeException('override file not found');
        }
        $raw = (string)file_get_contents($this->path);
        $o = json_decode($raw, true);
        if (!is_array($o)) {
            throw new RuntimeException('override file is not valid JSON');
        }
        $exp = isset($o['expires_at']) ? strtotime((string)$o['expires_at']) : false;
        if ($exp === false || $exp < time()) {
            throw new RuntimeException('override expired or expires_at missing/invalid');
        }
        $gold = $o['gold18'] ?? null;
        if (!is_numeric($gold) || (float)$gold <= 0) {
            throw new RuntimeException('override gold18 invalid');
        }
        $coins = ['emami' => 0, 'bahar' => 0, 'nim' => 0, 'rob' => 0, 'gerami' => 0];
        foreach ($coins as $k => $v) {
            $cv = $o['coins'][$k] ?? 0;
            $coins[$k] = is_numeric($cv) && (float)$cv > 0 ? (int)$cv : 0;
        }
        $mazaneh = is_numeric($o['mazaneh'] ?? null) && (float)$o['mazaneh'] > 0
            ? (int)$o['mazaneh']
            : (int)round((float)$gold * 4.3318);
        return ['gold18' => (int)$gold, 'mazaneh' => $mazaneh, 'coins' => $coins];
    }
}
