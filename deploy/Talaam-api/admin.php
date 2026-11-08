<?php
/**
 * طلام — داشبورد ادمین از مرورگر/گوشی (بدون نیاز به رفتن به هاست)
 *
 * فعال‌سازی: در config.php مقدار 'admin_token' را یک رشته تصادفی بگذار (خالی = خاموش)
 * آدرس:      https://YOUR-HOST/server/admin.php?token=YOUR_TOKEN
 *
 * بخش‌ها:
 *   1) نرخ‌ها      → ذخیره override.json (اعتبار ۲۴ ساعت) + انتشار فوری
 *   2) منابع و API → مدیریت کامل پروایدرها (افزودن، ویرایش، حذف، تغییر اولویت، فعال/غیرفعال،
 *                    وارد کردن مستقیم از دستور curl، تنظیم ساده مسیر فیلدها، تست اتصال و نمایش RAW)
 *
 * قوانین ثابت: فونت لوکال Vazir (@font-face محلی) و آیکون‌های لوکال (SVG inline).
 * امنیت: توکن timing-safe؛ هیچ secret در خروجی تست (کلید ماسک می‌شود)؛ config.php با var_export بازنویسی می‌شود.
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

$serverDir = __DIR__;
require $serverDir . '/providers/ProviderInterface.php';
require $serverDir . '/providers/BaseProvider.php';
require $serverDir . '/providers/ConfigurableHttpProvider.php';
require $serverDir . '/providers/TemplateProvider.php';
require $serverDir . '/providers/NavasanStyleProvider.php';
require $serverDir . '/providers/BrsapiStyleProvider.php';
require $serverDir . '/providers/ManualProvider.php';
require $serverDir . '/src/DefaultConfig.php';
require $serverDir . '/src/ProviderFactory.php';
require $serverDir . '/src/PricePipeline.php';

$configFile = $serverDir . '/config.php';
if (file_exists($configFile)) {
    /** @var array<string,mixed> $config */
    $config = require $configFile;
} else {
    $config = DefaultConfig::get($serverDir);
}

$adminToken = (string)($config['admin_token'] ?? '');
$given = (string)($_REQUEST['token'] ?? '');
if ($adminToken === '' || !hash_equals($adminToken, $given)) {
    http_response_code(403);
    exit('forbidden — admin_token در config.php تنظیم نشده یا توکن اشتباه است');
}

$paths = $config['paths'] ?? [];
$overridePath = (string)($paths['override'] ?? $serverDir . '/data/override.json');
$msg = '';
$msgType = 'ok';
$rawPreview = '';
$activeTab = (string)($_REQUEST['tab'] ?? 'rates');

/** ذخیره اتمیک آرایه به مسیر JSON */
function writeJsonAtomic(string $path, array $data): void
{
    @mkdir(dirname($path), 0775, true);
    $tmp = $path . '.tmp.' . getmypid();
    if (file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) === false || !rename($tmp, $path)) {
        throw new RuntimeException('cannot write ' . $path);
    }
}

/** بازنویسی امن config.php از آرایه (var_export → PHP معتبر) */
function writeConfigAtomic(string $path, array $config): void
{
    $tmp = $path . '.tmp.' . getmypid();
    $php = "<?php\n// تولید خودکار توسط داشبورد — ویرایش دستی هم مجاز است\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents($tmp, $php) === false || !rename($tmp, $path)) {
        throw new RuntimeException('cannot write ' . $path);
    }
}

/** ماسک کردن کلید امنیتی */
function maskKey(string $k): string
{
    $k = trim($k);
    if ($k === '') {
        return '';
    }
    return strlen($k) <= 10 ? '***' : substr($k, 0, 6) . '…' . substr($k, -4);
}

/** تبدیل آدرس‌های مطلق داخل config به مسیر فعلی سرور در صورت جابه‌جایی */
function ensureProvidersList(array &$config, string $serverDir): void
{
    if (!isset($config['providers']) || !is_array($config['providers']) || empty($config['providers'])) {
        $def = DefaultConfig::get($serverDir);
        $config['providers'] = $def['providers'];
    }
}

/**
 * پارس کردن دستور curl و استخراج URL، Query، Headers و Auth
 * @return array{url:string, method:string, query:array<string,string>, headers:array<string,string>, auth:array{type:string,name:string,key:string}}
 */
function parseCurlCommand(string $curl): array
{
    $curl = str_replace(["\\\r\n", "\\\n"], ' ', trim($curl));
    $method = 'GET';
    if (preg_match('/(?:-X|--request)\s+([A-Z]+)/i', $curl, $m)) {
        $method = strtoupper($m[1]) === 'POST' ? 'POST' : 'GET';
    }
    $fullUrl = '';
    if (preg_match('/https?:\/\/[^\s"\'\\\\]+/i', $curl, $m)) {
        $fullUrl = trim($m[0], "\"'");
    }
    if ($fullUrl === '') {
        throw new RuntimeException('آدرس اینترنتی (http/https) در دستور curl پیدا نشد');
    }

    $parts = parse_url($fullUrl);
    $baseUrl = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');
    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }

    $headers = [];
    $auth = ['type' => '', 'name' => 'Authorization', 'key' => ''];
    if (preg_match_all('/(?:-H|--header)\s+(?:"([^"]+)"|\'([^\']+)\')/i', $curl, $hm, PREG_SET_ORDER)) {
        foreach ($hm as $match) {
            $hLine = $match[1] !== '' ? $match[1] : ($match[2] ?? '');
            $pos = strpos($hLine, ':');
            if ($pos !== false) {
                $hName = trim(substr($hLine, 0, $pos));
                $hVal = trim(substr($hLine, $pos + 1));
                if (strcasecmp($hName, 'Authorization') === 0) {
                    $auth = ['type' => 'header', 'name' => 'Authorization', 'key' => $hVal];
                } elseif (preg_match('/^(x-api-key|api-key|apikey|token)$/i', $hName)) {
                    $auth = ['type' => 'header', 'name' => $hName, 'key' => $hVal];
                } else {
                    $headers[$hName] = $hVal;
                }
            }
        }
    }

    return [
        'url' => $baseUrl,
        'method' => $method,
        'query' => $query,
        'headers' => $headers,
        'auth' => $auth,
    ];
}

/** ساخت آرایه options برای ConfigurableHttpProvider از ورودی فرم */
function buildHttpOptionsFromPost(array $post, array $existingOpts = []): array
{
    $opts = $existingOpts;
    $id = preg_replace('/[^a-zA-Z0-9_\-]/', '', trim((string)($post['api_id'] ?? ($opts['id'] ?? 'api-source')))) ?? '';
    $opts['id'] = $id !== '' ? $id : 'api-source';
    $opts['enabled'] = isset($post['api_enabled']);

    $rawUrl = trim((string)($post['api_url'] ?? ($opts['url'] ?? '')));
    if ($rawUrl === '' || !preg_match('#^https?://#i', $rawUrl)) {
        throw new RuntimeException('آدرس API معتبر نیست (باید با http یا https شروع شود)');
    }

    // اگر کاربر Query را مستقیم در انتهای URL چسبانده باشد، خودکار جدا و ادغام می‌کنیم
    $uParts = parse_url($rawUrl);
    $cleanUrl = ($uParts['scheme'] ?? 'https') . '://' . ($uParts['host'] ?? '') . (isset($uParts['port']) ? ':' . $uParts['port'] : '') . ($uParts['path'] ?? '');
    $urlQuery = [];
    if (!empty($uParts['query'])) {
        parse_str($uParts['query'], $urlQuery);
    }
    $opts['url'] = $cleanUrl;
    $opts['method'] = in_array(strtoupper((string)($post['api_method'] ?? ($opts['method'] ?? 'GET'))), ['GET', 'POST'], true)
        ? strtoupper((string)($post['api_method'] ?? 'GET'))
        : 'GET';

    // پارس Query (پشتیبانی از key=val&key2=val2 یا JSON)
    $qInput = trim((string)($post['api_query_text'] ?? ''));
    $query = $urlQuery;
    if ($qInput !== '') {
        if ($qInput[0] === '{') {
            $qd = json_decode($qInput, true);
            if (!is_array($qd)) {
                throw new RuntimeException('فرمت JSON پارامترهای Query نامعتبر است');
            }
            $query = array_merge($query, $qd);
        } else {
            // مثل symbols=GOLD_18K,COIN_86
            foreach (preg_split('/[\r\n&]+/', $qInput) ?: [] as $pair) {
                $pair = trim($pair);
                if ($pair === '') {
                    continue;
                }
                $eq = strpos($pair, '=');
                if ($eq !== false) {
                    $query[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
                }
            }
        }
    }
    $opts['query'] = $query;

    // Headers
    $hInput = trim((string)($post['api_headers'] ?? ''));
    if ($hInput !== '' && $hInput !== '{}') {
        $hd = json_decode($hInput, true);
        if (!is_array($hd)) {
            throw new RuntimeException('فرمت JSON هدرها نامعتبر است');
        }
        $opts['headers'] = $hd;
    } else {
        $opts['headers'] = [];
    }

    // Auth
    $authMode = (string)($post['api_auth_mode'] ?? 'bearer_header');
    $oldKey = (string)($opts['auth']['key'] ?? '');
    $newKey = trim((string)($post['api_auth_key'] ?? ''));
    if ($newKey === '') {
        $newKey = $oldKey;
    }

    if ($authMode === 'none') {
        $opts['auth'] = ['type' => '', 'name' => '', 'key' => ''];
    } elseif ($authMode === 'bearer_header') {
        // اگر کاربر خودش Bearer ننوشته بود، اضافه شود تا مثل هدر استاندارد کار کند
        if ($newKey !== '' && stripos($newKey, 'Bearer ') !== 0) {
            $newKey = 'Bearer ' . $newKey;
        }
        $opts['auth'] = ['type' => 'header', 'name' => 'Authorization', 'key' => $newKey];
    } elseif ($authMode === 'custom_header') {
        $hName = trim((string)($post['api_auth_name'] ?? 'Authorization'));
        $opts['auth'] = ['type' => 'header', 'name' => $hName !== '' ? $hName : 'X-Api-Key', 'key' => $newKey];
    } elseif ($authMode === 'query_param') {
        $qName = trim((string)($post['api_auth_name'] ?? 'api_key'));
        $opts['auth'] = ['type' => 'query', 'name' => $qName !== '' ? $qName : 'api_key', 'key' => $newKey];
    }

    // Map (ساخت از فیلدهای ساده فرم یا از JSON پیشرفته)
    $allowed = ConfigurableHttpProvider::CANONICAL_FIELDS;
    $map = [];
    if (isset($post['map_path']) && is_array($post['map_path'])) {
        $units = is_array($post['map_unit'] ?? null) ? $post['map_unit'] : [];
        foreach ($allowed as $field) {
            $p = trim((string)($post['map_path'][$field] ?? ''));
            if ($p !== '') {
                $u = in_array($units[$field] ?? 'toman', ['toman', 'rial'], true) ? $units[$field] : 'toman';
                $map[$field] = ['path' => $p, 'unit' => $u];
            }
        }
    } elseif (!empty($post['api_map'])) {
        $md = json_decode((string)$post['api_map'], true);
        if (is_array($md)) {
            $map = $md;
        }
    } else {
        $map = $opts['map'] ?? ['gold18' => ['path' => 'GOLD_18K.price', 'unit' => 'toman']];
    }

    if (empty($map)) {
        throw new RuntimeException('حداقل یک فیلد (مثل گرم ۱۸ عیار) باید در بخش نگاشت فیلدها مسیر داشته باشد');
    }
    $opts['map'] = $map;

    $opts['max_age_seconds'] = max(60, (int)($post['api_max_age'] ?? ($opts['max_age_seconds'] ?? 600)));
    $opts['min_interval_seconds'] = max(0, (int)($post['api_min_interval'] ?? ($opts['min_interval_seconds'] ?? 300)));
    $opts['daily_cap'] = max(0, (int)($post['api_daily_cap'] ?? ($opts['daily_cap'] ?? 300)));

    return $opts;
}

ensureProvidersList($config, $serverDir);
$lastGoodPath = (string)($paths['last_good'] ?? '');
$lastGood = is_array(json_decode((string)@file_get_contents($lastGoodPath), true)) ? json_decode((string)file_get_contents($lastGoodPath), true) : null;

// ─── POST handling ───
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save_rates') {
            $activeTab = 'rates';
            $clean = static function ($v): ?int {
                $s = trim((string)$v);
                $s = strtr($s, [
                    '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                    '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                    "\u{066C}" => '', "\u{060C}" => '', ',' => '', ' ' => '', "\u{066B}" => '.',
                ]);
                if ($s === '' || !is_numeric($s)) {
                    return null;
                }
                return (int)round((float)$s);
            };
            $gold18 = $clean($_POST['gold18'] ?? '');
            if ($gold18 === null || $gold18 <= 0) {
                throw new RuntimeException('نرخ گرم ۱۸ عیار نامعتبر است');
            }
            $coins = ['emami' => 0, 'bahar' => 0, 'nim' => 0, 'rob' => 0, 'gerami' => 0];
            $coinsIn = is_array($_POST['coins'] ?? null) ? $_POST['coins'] : [];
            foreach ($coins as $k => $v) {
                $c = $clean($coinsIn[$k] ?? '');
                $coins[$k] = $c !== null && $c > 0 ? $c : 0;
            }
            $mazaneh = $clean($_POST['mazaneh'] ?? '');
            writeJsonAtomic($overridePath, [
                'expires_at' => date('c', time() + 86400),
                'gold18' => $gold18,
                'mazaneh' => $mazaneh !== null && $mazaneh > 0 ? $mazaneh : (int)round($gold18 * 4.3318),
                'coins' => $coins,
            ]);
            $providers = ProviderFactory::createAll($config);
            $result = (new PricePipeline($config, $providers))->run();
            $msg = $result['status'] === 'ok'
                ? 'نرخ‌ها ذخیره و منتشر شد ✓ (منبع فعال: ' . ($result['published']['source'] ?? '?') . ')'
                : 'ذخیره شد؛ وضعیت انتشار: ' . $result['status'];
            $lastGood = $result['published'] ?? $lastGood;
        } elseif ($action === 'run_pipeline') {
            $activeTab = 'providers';
            $providers = ProviderFactory::createAll($config);
            $result = (new PricePipeline($config, $providers))->run();
            $msg = 'بروزرسانی اجرا شد — وضعیت: ' . $result['status'] . (isset($result['published']['gold18']) ? ' (گرم ۱۸: ' . number_format((int)$result['published']['gold18']) . ' تومان)' : '');
            $lastGood = $result['published'] ?? $lastGood;
        } elseif ($action === 'import_curl') {
            $activeTab = 'providers';
            $idx = (int)($_POST['provider_index'] ?? 0);
            if (!isset($config['providers'][$idx]) || ($config['providers'][$idx]['class'] ?? '') !== 'ConfigurableHttpProvider') {
                throw new RuntimeException('پروایدر انتخاب‌شده یافت نشد');
            }
            $parsed = parseCurlCommand((string)($_POST['curl_command'] ?? ''));
            $opts = $config['providers'][$idx]['options'] ?? [];
            $opts['url'] = $parsed['url'];
            $opts['method'] = $parsed['method'];
            if (!empty($parsed['query'])) {
                $opts['query'] = $parsed['query'];
            }
            if (!empty($parsed['headers'])) {
                $opts['headers'] = $parsed['headers'];
            }
            if ($parsed['auth']['key'] !== '') {
                $opts['auth'] = $parsed['auth'];
            }
            // اگر آدرس مربوط به markaznerkh باشد و فیلدی ست نشده باشد، نگاشت پیشفرض آن را ست کن
            if (stripos($parsed['url'], 'markaznerkh') !== false && empty($opts['map'])) {
                $opts['map'] = [
                    'gold18' => ['path' => 'GOLD_18K.price', 'unit' => 'toman'],
                    'coins.emami' => ['path' => 'COIN_86.price', 'unit' => 'toman'],
                ];
            }
            $config['providers'][$idx]['options'] = $opts;
            new ConfigurableHttpProvider($opts, $config['http'] ?? []);
            @copy($configFile, $configFile . '.bak');
            writeConfigAtomic($configFile, $config);
            $config = require $configFile;
            $msg = 'دستور curl با موفقیت تجزیه و در پروایدر ذخیره شد ✓';
        } elseif ($action === 'save_api' || $action === 'save_provider') {
            $activeTab = 'providers';
            // پشتیبانی از فرم قدیمی (بدون provider_index) و فرم جدید چندپروایدری
            $idx = isset($_POST['provider_index']) ? (int)$_POST['provider_index'] : 0;
            if (!isset($config['providers'][$idx])) {
                throw new RuntimeException('پروایدر مورد نظر در تنظیمات یافت نشد');
            }
            $existing = $config['providers'][$idx]['options'] ?? [];
            // سازگاری با پارامترهای قدیمی تست/فرم (api_query و api_auth_type)
            if (isset($_POST['api_query']) && !isset($_POST['api_query_text'])) {
                $_POST['api_query_text'] = (string)$_POST['api_query'];
            }
            if (isset($_POST['api_auth_type']) && !isset($_POST['api_auth_mode'])) {
                $t = strtolower((string)$_POST['api_auth_type']);
                $_POST['api_auth_mode'] = $t === 'query' ? 'query_param' : ($t === '' ? 'none' : 'custom_header');
            }
            $opts = buildHttpOptionsFromPost($_POST, $existing);
            new ConfigurableHttpProvider($opts, $config['http'] ?? []);
            $config['providers'][$idx]['options'] = $opts;
            @copy($configFile, $configFile . '.bak');
            writeConfigAtomic($configFile, $config);
            $config = require $configFile;
            $msg = 'تنظیمات پروایدر «' . $opts['id'] . '» با موفقیت ذخیره شد ✓';
        } elseif ($action === 'test_api' || $action === 'test_provider') {
            $activeTab = 'providers';
            $idx = isset($_POST['provider_index']) ? (int)$_POST['provider_index'] : 0;
            if (!isset($config['providers'][$idx])) {
                throw new RuntimeException('پروایدر یافت نشد');
            }
            if (isset($_POST['api_url'])) {
                if (isset($_POST['api_query']) && !isset($_POST['api_query_text'])) {
                    $_POST['api_query_text'] = (string)$_POST['api_query'];
                }
                if (isset($_POST['api_auth_type']) && !isset($_POST['api_auth_mode'])) {
                    $t = strtolower((string)$_POST['api_auth_type']);
                    $_POST['api_auth_mode'] = $t === 'query' ? 'query_param' : ($t === '' ? 'none' : 'custom_header');
                }
                $opts = buildHttpOptionsFromPost($_POST, $config['providers'][$idx]['options'] ?? []);
                $provider = new ConfigurableHttpProvider($opts, $config['http'] ?? []);
            } else {
                $provider = ProviderFactory::create($config['providers'][$idx], $config['http'] ?? []);
            }
            $t0 = microtime(true);
            $data = $provider->fetch();
            $lat = (int)round((microtime(true) - $t0) * 1000);
            $fields = [];
            foreach (['gold18', 'mazaneh'] as $f) {
                if (isset($data[$f]) && $data[$f] > 0) {
                    $fields[$f] = (int)$data[$f];
                }
            }
            foreach (($data['coins'] ?? []) as $k => $v) {
                if ($v > 0) {
                    $fields['coins.' . $k] = (int)$v;
                }
            }
            $msg = 'اتصال موفق (' . $lat . 'ms) — فیلدهای دریافت‌شده: ' . json_encode($fields, JSON_UNESCAPED_SLASHES);
            $msgType = 'ok';
        } elseif ($action === 'test_api_raw') {
            $activeTab = 'providers';
            $idx = isset($_POST['provider_index']) ? (int)$_POST['provider_index'] : 0;
            $opts = $config['providers'][$idx]['options'] ?? [];
            if (isset($_POST['api_url'])) {
                $opts = buildHttpOptionsFromPost($_POST, $opts);
            }
            $inst = new ConfigurableHttpProvider($opts, $config['http'] ?? []);
            $rmBuild = new ReflectionMethod('ConfigurableHttpProvider', 'buildRequest');
            $rmBuild->setAccessible(true);
            [$url, $hdrs] = $rmBuild->invoke($inst);
            $rmGet = new ReflectionMethod('ConfigurableHttpProvider', 'httpGet');
            $rmGet->setAccessible(true);
            $raw = $rmGet->invoke($inst, $url, $hdrs);
            $masked = preg_replace('/(Bearer\s+|api_key=|api-key=)([A-Za-z0-9_\-]{6})[A-Za-z0-9_\-]+/i', '$1$2***', (string)$raw) ?? '';
            $rawPreview = substr($masked, 0, 1500);
            $msg = 'پاسخ خام (RAW) از سرور دریافت شد ✓';
        } elseif ($action === 'add_provider') {
            $activeTab = 'providers';
            $preset = (string)($_POST['preset_type'] ?? 'markaznerkh');
            $newId = 'api-' . (count($config['providers']) + 1);
            if ($preset === 'markaznerkh') {
                $newBlock = [
                    'class' => 'ConfigurableHttpProvider',
                    'options' => [
                        'id' => 'markaznerkh-' . count($config['providers']),
                        'enabled' => true,
                        'url' => 'https://market.markaznerkh.ir/api/v1/rates',
                        'method' => 'GET',
                        'query' => ['symbols' => 'GOLD_18K,COIN_86'],
                        'headers' => [],
                        'auth' => [
                            'type' => 'header',
                            'name' => 'Authorization',
                            'key' => 'Bearer tala_free_abcf238ceb0cb0b3f72d6fc0a1717579a585decf856756a7b45c14f378152058',
                        ],
                        'map' => [
                            'gold18' => ['path' => 'GOLD_18K.price', 'unit' => 'toman'],
                            'coins.emami' => ['path' => 'COIN_86.price', 'unit' => 'toman'],
                        ],
                        'max_age_seconds' => 600,
                        'min_interval_seconds' => 300,
                        'daily_cap' => 300,
                    ],
                ];
            } else {
                $newBlock = [
                    'class' => 'ConfigurableHttpProvider',
                    'options' => [
                        'id' => $newId,
                        'enabled' => false,
                        'url' => 'https://example.com/api/rates',
                        'method' => 'GET',
                        'query' => [],
                        'headers' => [],
                        'auth' => ['type' => 'header', 'name' => 'Authorization', 'key' => ''],
                        'map' => [
                            'gold18' => ['path' => 'gold18', 'unit' => 'toman'],
                        ],
                        'max_age_seconds' => 600,
                        'min_interval_seconds' => 300,
                        'daily_cap' => 300,
                    ],
                ];
            }
            // درج قبل از ManualProvider تا Manual همیشه آخرین پشتیبان بماند
            $inserted = false;
            $newList = [];
            foreach ($config['providers'] as $p) {
                if (!$inserted && ($p['class'] ?? '') === 'ManualProvider') {
                    $newList[] = $newBlock;
                    $inserted = true;
                }
                $newList[] = $p;
            }
            if (!$inserted) {
                $newList[] = $newBlock;
            }
            $config['providers'] = $newList;
            @copy($configFile, $configFile . '.bak');
            writeConfigAtomic($configFile, $config);
            $config = require $configFile;
            $msg = 'پروایدر جدید اضافه شد ✓';
        } elseif ($action === 'move_provider') {
            $activeTab = 'providers';
            $idx = (int)($_POST['provider_index'] ?? -1);
            $dir = (string)($_POST['dir'] ?? 'up');
            $target = $dir === 'up' ? $idx - 1 : $idx + 1;
            if (isset($config['providers'][$idx], $config['providers'][$target])) {
                $tmp = $config['providers'][$idx];
                $config['providers'][$idx] = $config['providers'][$target];
                $config['providers'][$target] = $tmp;
                $config['providers'] = array_values($config['providers']);
                writeConfigAtomic($configFile, $config);
                $config = require $configFile;
                $msg = 'اولویت پروایدر جابه‌جا شد ✓';
            }
        } elseif ($action === 'delete_provider') {
            $activeTab = 'providers';
            $idx = (int)($_POST['provider_index'] ?? -1);
            if (!isset($config['providers'][$idx])) {
                throw new RuntimeException('پروایدر یافت نشد');
            }
            if (($config['providers'][$idx]['class'] ?? '') === 'ManualProvider') {
                throw new RuntimeException('منبع دستی (ManualProvider) پشتیبان اضطراری است و حذف نمی‌شود');
            }
            array_splice($config['providers'], $idx, 1);
            writeConfigAtomic($configFile, $config);
            $config = require $configFile;
            $msg = 'پروایدر حذف شد ✓';
        } elseif ($action === 'reset_default_api') {
            $activeTab = 'providers';
            $def = DefaultConfig::get($serverDir);
            $config['providers'] = $def['providers'];
            @copy($configFile, $configFile . '.bak');
            writeConfigAtomic($configFile, $config);
            $config = require $configFile;
            $msg = 'تنظیمات پروایدرها به حالت پیش‌فرض (مرکزنرخ + دستی) بازگردانی شد ✓';
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $msgType = 'err';
    }
}

// ─── مقادیر فعلی برای نمایش ───
$current = null;
if (file_exists($overridePath)) {
    $o = json_decode((string)file_get_contents($overridePath), true);
    if (is_array($o) && (!isset($o['expires_at']) || strtotime((string)$o['expires_at']) > time())) {
        $current = $o;
    }
}
if ($current === null && is_array($lastGood)) {
    $current = ['gold18' => $lastGood['gold18'] ?? '', 'mazaneh' => $lastGood['mazaneh'] ?? '', 'coins' => $lastGood['coins'] ?? []];
}
$esc = static fn ($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

$fieldLabels = [
    'gold18' => ['title' => 'گرم طلای ۱۸ عیار *', 'hint' => 'GOLD_18K.price'],
    'mazaneh' => ['title' => 'مظنه تهران (مثقال)', 'hint' => 'اختیاری (محاسبه خودکار از گرم)'],
    'coins.emami' => ['title' => 'سکه امامی (طرح جدید)', 'hint' => 'COIN_86.price'],
    'coins.bahar' => ['title' => 'سکه بهار آزادی', 'hint' => 'اختیاری'],
    'coins.nim' => ['title' => 'نیم‌سکه', 'hint' => 'اختیاری'],
    'coins.rob' => ['title' => 'ربع‌سکه', 'hint' => 'اختیاری'],
    'coins.gerami' => ['title' => 'سکه گرمی', 'hint' => 'اختیاری'],
];
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>طلام — پنل مدیریت</title>
<style>
  /* فونت لوکال وزیرمتن — بدون هیچ وابستگی به CDN */
  @font-face {
    font-family: 'Vazirmatn';
    font-style: normal;
    font-weight: 400;
    font-display: swap;
    src: url('public/fonts/vazirmatn-arabic-400-normal.woff2') format('woff2');
  }
  @font-face {
    font-family: 'Vazirmatn';
    font-style: normal;
    font-weight: 700;
    font-display: swap;
    src: url('public/fonts/vazirmatn-arabic-700-normal.woff2') format('woff2');
  }
  @font-face {
    font-family: 'Vazirmatn';
    font-style: normal;
    font-weight: 800;
    font-display: swap;
    src: url('public/fonts/vazirmatn-arabic-800-normal.woff2') format('woff2');
  }

  :root {
    --bg: #170C26;
    --surface: #25153B;
    --surface-2: #1B0F2E;
    --border: rgba(255, 255, 255, 0.08);
    --gold: #E8C97D;
    --gold-strong: #D4A843;
    --text: #FFFFFF;
    --muted: #9D8EB8;
    --ok: #34D399;
    --err: #F4796B;
  }

  * { box-sizing: border-box; }
  body {
    font-family: 'Vazirmatn', Tahoma, sans-serif;
    background: radial-gradient(circle at top, #2D184A 0%, var(--bg) 70%);
    color: var(--text);
    margin: 0;
    padding: 18px 14px 48px;
    min-height: 100vh;
  }
  .wrap { max-width: 540px; margin: 0 auto; }

  /* هدر */
  .top-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 14px 18px;
    margin-bottom: 14px;
  }
  .brand { display: flex; align-items: center; gap: 10px; }
  .brand-badge {
    width: 38px; height: 38px;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--gold), var(--gold-strong));
    color: #241300;
    display: inline-flex; align-items: center; justify-content: center;
  }
  .brand h1 { font-size: 16px; margin: 0; font-weight: 800; }
  .brand p { font-size: 11.5px; color: var(--muted); margin: 2px 0 0; }

  /* تب‌ها */
  .tabs {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    background: var(--surface-2);
    padding: 5px;
    border-radius: 16px;
    border: 1px solid var(--border);
    margin-bottom: 16px;
  }
  .tab-btn {
    display: flex; align-items: center; justify-content: center; gap: 7px;
    min-height: 42px;
    border-radius: 12px;
    border: none;
    background: transparent;
    color: var(--muted);
    font-family: inherit;
    font-size: 13.5px;
    font-weight: 700;
    cursor: pointer;
    transition: all .15s ease;
  }
  .tab-btn.active {
    background: linear-gradient(135deg, var(--gold), var(--gold-strong));
    color: #241300;
    box-shadow: 0 4px 14px rgba(212, 168, 67, 0.25);
  }
  .tab-panel { display: none; }
  .tab-panel.active { display: block; }

  /* کارت‌ها و فرم‌ها */
  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 16px;
    margin-bottom: 12px;
  }
  .card-head {
    display: flex; align-items: center; justify-content: space-between;
    gap: 8px; margin-bottom: 12px;
  }
  .card-title {
    display: flex; align-items: center; gap: 8px;
    font-size: 14px; font-weight: 800; color: var(--gold);
  }
  .badge {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 11px; font-weight: 700;
    padding: 4px 9px; border-radius: 999px;
  }
  .badge.on { background: rgba(52, 211, 153, 0.16); color: var(--ok); }
  .badge.off { background: rgba(255, 255, 255, 0.08); color: var(--muted); }

  label { display: block; font-size: 12.5px; font-weight: 700; margin-bottom: 6px; color: #E5DDF2; }
  .hint { font-size: 11px; color: var(--muted); font-weight: 400; }

  input, textarea, select {
    width: 100%;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 12px;
    color: #fff;
    font-family: inherit;
    font-size: 14.5px;
    font-weight: 600;
    padding: 10px 12px;
    outline: none;
  }
  input.num-big { font-size: 17px; text-align: center; }
  input[dir="ltr"], textarea[dir="ltr"], select[dir="ltr"] { text-align: left; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 12.5px; }
  textarea { min-height: 68px; resize: vertical; }
  input:focus, textarea:focus, select:focus { border-color: var(--gold-strong); }

  .row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .row3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; }

  /* دکمه‌ها */
  .btn {
    width: 100%; min-height: 46px;
    border: none; border-radius: 14px;
    background: linear-gradient(135deg, var(--gold), var(--gold-strong));
    color: #241300;
    font-family: inherit; font-size: 14px; font-weight: 800;
    cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center; gap: 7px;
  }
  .btn.ghost {
    background: rgba(232, 201, 125, 0.08);
    border: 1px solid rgba(212, 168, 67, 0.4);
    color: var(--gold);
  }
  .btn.danger {
    background: rgba(244, 121, 107, 0.12);
    border: 1px solid rgba(244, 121, 107, 0.35);
    color: var(--err);
  }
  .btn.sm { min-height: 36px; font-size: 12px; padding: 0 10px; border-radius: 10px; width: auto; }
  .icon-btn {
    width: 34px; height: 34px;
    border-radius: 9px;
    border: 1px solid var(--border);
    background: var(--surface-2);
    color: var(--text);
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer;
  }
  .icon-btn:hover { border-color: var(--gold); color: var(--gold); }

  .msg {
    border-radius: 14px; padding: 12px 14px;
    font-size: 12.5px; margin-bottom: 14px;
    word-break: break-word; line-height: 1.7;
    display: flex; align-items: flex-start; gap: 8px;
  }
  .msg.ok { background: rgba(52, 211, 153, 0.15); border: 1px solid rgba(52, 211, 153, 0.35); color: var(--ok); }
  .msg.err { background: rgba(244, 121, 107, 0.15); border: 1px solid rgba(244, 121, 107, 0.35); color: var(--err); }

  .chk { display: flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer; }
  .chk input { width: 18px; height: 18px; accent-color: var(--gold-strong); }

  details.sub-box {
    background: rgba(0, 0, 0, 0.18);
    border: 1px solid var(--border);
    border-radius: 13px;
    padding: 10px 12px;
    margin-top: 10px;
  }
  details.sub-box summary {
    cursor: pointer;
    color: var(--gold);
    font-size: 12.5px;
    font-weight: 700;
    user-select: none;
    display: flex; align-items: center; justify-content: space-between;
  }
  .map-grid {
    display: grid;
    grid-template-columns: 1.2fr 1.4fr 0.8fr;
    gap: 6px;
    align-items: center;
    margin-top: 8px;
  }
  .map-grid .lbl { font-size: 11.5px; color: #E5DDF2; }
  .raw-box {
    background: #10081C;
    border: 1px solid var(--gold-strong);
    border-radius: 12px;
    padding: 12px;
    font-family: ui-monospace, Consolas, monospace;
    font-size: 11.5px;
    direction: ltr;
    text-align: left;
    white-space: pre-wrap;
    word-break: break-all;
    max-height: 240px;
    overflow: auto;
    margin-bottom: 14px;
    color: #E8C97D;
  }
  .status-bar {
    display: flex; align-items: center; justify-content: space-between;
    font-size: 11.5px; color: var(--muted);
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 9px 12px;
    margin-bottom: 14px;
  }
  .status-bar a { color: var(--gold); text-decoration: none; font-weight: 700; }
  svg.ic { width: 18px; height: 18px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }
</style>
</head>
<body>
<div class="wrap">

  <!-- هدر -->
  <div class="top-header">
    <div class="brand">
      <span class="brand-badge">
        <svg class="ic" viewBox="0 0 24 24"><path d="M6 3h12l4 6-10 13L2 9Z"/><path d="M11 3 8 9l4 13 4-13-3-6"/><path d="M2 9h20"/></svg>
      </span>
      <div>
        <h1>پنل مدیریت طلام</h1>
        <p>مدیریت ساده نرخ‌ها، پروایدرها و اتصال API</p>
      </div>
    </div>
    <form method="post" style="margin:0">
      <input type="hidden" name="token" value="<?= $esc($given) ?>" />
      <input type="hidden" name="action" value="run_pipeline" />
      <button type="submit" class="btn ghost sm" title="دریافت فوری از منبع فعال">
        <svg class="ic" viewBox="0 0 24 24"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M21 21v-5h-5"/></svg>
        بروزرسانی نرخ
      </button>
    </form>
  </div>

  <!-- نوار وضعیت آخرین خروجی -->
  <div class="status-bar">
    <span>
      آخرین نرخ منتشرشده:
      <strong style="color:#fff"><?= isset($lastGood['gold18']) ? number_format((int)$lastGood['gold18']) . ' تومان' : 'ثبت نشده' ?></strong>
      <?php if (!empty($lastGood['source'])): ?>(منبع: <?= $esc($lastGood['source']) ?>)<?php endif; ?>
    </span>
    <a href="public/prices.json?t=<?= time() ?>" target="_blank">مشاهده JSON ↗</a>
  </div>

  <?php if ($msg !== ''): ?>
    <div class="msg <?= $msgType === 'ok' ? 'ok' : 'err' ?>">
      <?php if ($msgType === 'ok'): ?>
        <svg class="ic" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
      <?php else: ?>
        <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
      <?php endif; ?>
      <div><?= $esc($msg) ?></div>
    </div>
  <?php endif; ?>

  <?php if ($rawPreview !== ''): ?>
    <div class="raw-box"><?= $esc($rawPreview) ?></div>
  <?php endif; ?>

  <!-- تب‌ها -->
  <div class="tabs">
    <button type="button" class="tab-btn <?= $activeTab === 'rates' ? 'active' : '' ?>" onclick="switchTab('rates')">
      <svg class="ic" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
      ثبت دستی نرخ‌ها
    </button>
    <button type="button" class="tab-btn <?= $activeTab === 'providers' ? 'active' : '' ?>" onclick="switchTab('providers')">
      <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9c.26.6.77 1.05 1.39 1.19H21a2 2 0 1 1 0 4h-.09c-.66.15-1.18.63-1.51 1.81Z"/></svg>
      پروایدرها و تنظیمات API
    </button>
  </div>

  <!-- پنل ۱: نرخ‌های دستی -->
  <div id="panel-rates" class="tab-panel <?= $activeTab === 'rates' ? 'active' : '' ?>">
    <form method="post">
      <input type="hidden" name="token" value="<?= $esc($given) ?>" />
      <input type="hidden" name="action" value="save_rates" />
      <div class="card">
        <label>گرم طلای ۱۸ عیار (تومان) *</label>
        <input class="num-big" name="gold18" inputmode="decimal" value="<?= $esc($current['gold18'] ?? '') ?>" required />
        <label style="margin-top:12px">مظنه تهران — مثقال ۱۷ عیار (تومان) <span class="hint">(خالی = محاسبه خودکار از گرم)</span></label>
        <input class="num-big" name="mazaneh" inputmode="decimal" value="<?= $esc($current['mazaneh'] ?? '') ?>" />
      </div>
      <div class="card">
        <label>سکه‌ها (قیمت بازار، تومان)</label>
        <div class="row">
          <div><label>امامی</label><input class="num-big" name="coins[emami]" inputmode="decimal" value="<?= $esc($current['coins']['emami'] ?? '') ?>" /></div>
          <div><label>بهار آزادی</label><input class="num-big" name="coins[bahar]" inputmode="decimal" value="<?= $esc($current['coins']['bahar'] ?? '') ?>" /></div>
          <div><label>نیم‌سکه</label><input class="num-big" name="coins[nim]" inputmode="decimal" value="<?= $esc($current['coins']['nim'] ?? '') ?>" /></div>
          <div><label>ربع‌سکه</label><input class="num-big" name="coins[rob]" inputmode="decimal" value="<?= $esc($current['coins']['rob'] ?? '') ?>" /></div>
          <div><label>گرمی</label><input class="num-big" name="coins[gerami]" inputmode="decimal" value="<?= $esc($current['coins']['gerami'] ?? '') ?>" /></div>
        </div>
      </div>
      <button type="submit" class="btn">
        <svg class="ic" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
        ذخیره و انتشار فوری
      </button>
    </form>
  </div>

  <!-- پنل ۲: مدیریت پروایدرها و API -->
  <div id="panel-providers" class="tab-panel <?= $activeTab === 'providers' ? 'active' : '' ?>">

    <?php foreach (($config['providers'] ?? []) as $idx => $provSpec):
        $pClass = (string)($provSpec['class'] ?? '');
        $opts = is_array($provSpec['options'] ?? null) ? $provSpec['options'] : [];
    ?>
      <?php if ($pClass === 'ConfigurableHttpProvider'):
          $pid = (string)($opts['id'] ?? ('api-' . ($idx + 1)));
          $enabled = !empty($opts['enabled']);
          $authType = strtolower((string)($opts['auth']['type'] ?? ''));
          $authName = (string)($opts['auth']['name'] ?? 'Authorization');
          $authKey = (string)($opts['auth']['key'] ?? '');
          $authMode = 'none';
          if ($authType === 'query') {
              $authMode = 'query_param';
          } elseif ($authType === 'bearer' || ($authType === 'header' && strcasecmp($authName, 'Authorization') === 0 && stripos($authKey, 'Bearer ') === 0)) {
              $authMode = 'bearer_header';
          } elseif ($authType === 'header') {
              $authMode = 'custom_header';
          }
          $qArr = is_array($opts['query'] ?? null) ? $opts['query'] : [];
          $qTextParts = [];
          foreach ($qArr as $qk => $qv) {
              $qTextParts[] = $qk . '=' . $qv;
          }
          $qText = implode('&', $qTextParts);
          $mapArr = is_array($opts['map'] ?? null) ? $opts['map'] : [];
      ?>
        <div class="card">
          <div class="card-head">
            <div class="card-title">
              <svg class="ic" viewBox="0 0 24 24"><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><path d="M12 20h.01"/></svg>
              <span>پروایدر #<?= $idx + 1 ?>: <?= $esc($pid) ?></span>
              <span class="badge <?= $enabled ? 'on' : 'off' ?>"><?= $enabled ? 'فعال' : 'غیرفعال' ?></span>
            </div>
            <div style="display:flex;gap:4px">
              <?php if ($idx > 0): ?>
                <form method="post" style="margin:0">
                  <input type="hidden" name="token" value="<?= $esc($given) ?>" />
                  <input type="hidden" name="action" value="move_provider" />
                  <input type="hidden" name="provider_index" value="<?= $idx ?>" />
                  <input type="hidden" name="dir" value="up" />
                  <button type="submit" class="icon-btn" title="افزایش اولویت (بالاتر)">↑</button>
                </form>
              <?php endif; ?>
              <?php if ($idx < count($config['providers']) - 1): ?>
                <form method="post" style="margin:0">
                  <input type="hidden" name="token" value="<?= $esc($given) ?>" />
                  <input type="hidden" name="action" value="move_provider" />
                  <input type="hidden" name="provider_index" value="<?= $idx ?>" />
                  <input type="hidden" name="dir" value="down" />
                  <button type="submit" class="icon-btn" title="کاهش اولویت (پایین‌تر)">↓</button>
                </form>
              <?php endif; ?>
            </div>
          </div>

          <!-- وارد کردن سریع دستور curl -->
          <details class="sub-box" style="margin-bottom:12px">
            <summary>
              <span>⚡ تنظیم خودکار با جای‌گذاری دستور curl</span>
              <span class="hint">کلیک کنید</span>
            </summary>
            <form method="post" style="margin-top:10px">
              <input type="hidden" name="token" value="<?= $esc($given) ?>" />
              <input type="hidden" name="action" value="import_curl" />
              <input type="hidden" name="provider_index" value="<?= $idx ?>" />
              <label>دستور curl سرویس را کپی و اینجا Paste کنید:</label>
              <textarea name="curl_command" dir="ltr" placeholder='curl "https://market.markaznerkh.ir/api/v1/rates?symbols=GOLD_18K,COIN_86" -H "Authorization: Bearer ..."'></textarea>
              <button type="submit" class="btn ghost sm" style="margin-top:8px;width:100%">استخراج و ذخیره خودکار از curl</button>
            </form>
          </details>

          <!-- فرم اصلی ویرایش پروایدر -->
          <form method="post">
            <input type="hidden" name="token" value="<?= $esc($given) ?>" />
            <input type="hidden" name="provider_index" value="<?= $idx ?>" />

            <div class="row" style="align-items:center;margin-bottom:10px">
              <label class="chk" style="margin:0">
                <input type="checkbox" name="api_enabled" <?= $enabled ? 'checked' : '' ?> />
                <span>این منبع فعال باشد</span>
              </label>
              <div>
                <label style="margin-bottom:3px">شناسه پروایدر</label>
                <input name="api_id" dir="ltr" value="<?= $esc($pid) ?>" />
              </div>
            </div>

            <label>آدرس پایه API (URL)</label>
            <input name="api_url" dir="ltr" value="<?= $esc($opts['url'] ?? '') ?>" required />

            <label style="margin-top:10px">پارامترهای آدرس (Query) <span class="hint">(مثلاً symbols=GOLD_18K,COIN_86)</span></label>
            <input name="api_query_text" dir="ltr" value="<?= $esc($qText) ?>" placeholder="symbols=GOLD_18K,COIN_86" />

            <div class="row" style="margin-top:10px">
              <div>
                <label>شیوه احراز هویت (Auth)</label>
                <select name="api_auth_mode">
                  <option value="bearer_header" <?= $authMode === 'bearer_header' ? 'selected' : '' ?>>Header: Authorization Bearer</option>
                  <option value="custom_header" <?= $authMode === 'custom_header' ? 'selected' : '' ?>>Header سفارشی</option>
                  <option value="query_param" <?= $authMode === 'query_param' ? 'selected' : '' ?>>پارامتر در URL (Query)</option>
                  <option value="none" <?= $authMode === 'none' ? 'selected' : '' ?>>بدون کلید (عمومی)</option>
                </select>
              </div>
              <div>
                <label>نام هدر / پارامتر کلید</label>
                <input name="api_auth_name" dir="ltr" value="<?= $esc($authName) ?>" />
              </div>
            </div>

            <label style="margin-top:10px">
              کلید/توکن API
              <span class="hint">(فعلی: <?= $esc(maskKey($authKey) ?: 'ثبت نشده') ?> — اگر خالی بگذاری تغییر نمی‌کند)</span>
            </label>
            <input name="api_auth_key" dir="ltr" placeholder="کلید جدید یا Bearer token را وارد کنید (خالی = حفظ کلید قبلی)" />

            <!-- نگاشت ساده فیلدها -->
            <details class="sub-box">
              <summary>
                <span>نگاشت فیلدهای پاسخ JSON (Map)</span>
                <span class="hint"><?= count($mapArr) ?> فیلد فعال</span>
              </summary>
              <p class="hint" style="margin:8px 0 4px">مسیر هر نرخ در خروجی JSON سرویس (مثلاً <code>GOLD_18K.price</code>). فیلدهایی که سرویس ندارد را خالی بگذارید.</p>
              <?php foreach ($fieldLabels as $fKey => $fMeta):
                  $curPath = (string)($mapArr[$fKey]['path'] ?? '');
                  $curUnit = strtolower((string)($mapArr[$fKey]['unit'] ?? 'toman'));
              ?>
                <div class="map-grid">
                  <div class="lbl"><?= $esc($fMeta['title']) ?></div>
                  <input name="map_path[<?= $esc($fKey) ?>]" dir="ltr" value="<?= $esc($curPath) ?>" placeholder="<?= $esc($fMeta['hint']) ?>" />
                  <select name="map_unit[<?= $esc($fKey) ?>]">
                    <option value="toman" <?= $curUnit === 'toman' ? 'selected' : '' ?>>تومان</option>
                    <option value="rial" <?= $curUnit === 'rial' ? 'selected' : '' ?>>ریال (÷۱۰)</option>
                  </select>
                </div>
              <?php endforeach; ?>
            </details>

            <!-- تنظیمات زمان‌بندی و سقف فراخوانی -->
            <details class="sub-box">
              <summary>
                <span>محدودیت فراخوانی و هدرهای اضافی</span>
                <span class="hint">هر <?= (int)($opts['min_interval_seconds'] ?? 300) ?> ثانیه</span>
              </summary>
              <div class="row3" style="margin-top:10px">
                <div>
                  <label>فاصله (ثانیه)</label>
                  <input name="api_min_interval" inputmode="numeric" dir="ltr" value="<?= $esc($opts['min_interval_seconds'] ?? 300) ?>" />
                </div>
                <div>
                  <label>سقف روزانه</label>
                  <input name="api_daily_cap" inputmode="numeric" dir="ltr" value="<?= $esc($opts['daily_cap'] ?? 300) ?>" />
                </div>
                <div>
                  <label>عمر کش (ثانیه)</label>
                  <input name="api_max_age" inputmode="numeric" dir="ltr" value="<?= $esc($opts['max_age_seconds'] ?? 600) ?>" />
                </div>
              </div>
              <label style="margin-top:10px">هدرهای سفارشی اضافی (JSON)</label>
              <textarea name="api_headers" dir="ltr" style="min-height:48px"><?= $esc(json_encode($opts['headers'] ?? new stdClass(), JSON_UNESCAPED_SLASHES)) ?></textarea>
            </details>

            <div class="row" style="margin-top:12px">
              <button type="submit" name="action" value="save_api" class="btn">
                <svg class="ic" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                ذخیره تنظیمات
              </button>
              <button type="submit" name="action" value="test_api" class="btn ghost">
                <svg class="ic" viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                تست اتصال
              </button>
            </div>
            <div class="row" style="margin-top:8px">
              <button type="submit" name="action" value="test_api_raw" class="btn ghost sm" style="width:100%">
                نمایش پاسخ خام (RAW) برای بررسی ساختار JSON
              </button>
              <?php if (count($config['providers']) > 2): ?>
                <button type="submit" name="action" value="delete_provider" class="btn danger sm" style="width:100%" onclick="return confirm('این پروایدر حذف شود؟')">
                  حذف پروایدر
                </button>
              <?php endif; ?>
            </div>
          </form>
        </div>

      <?php elseif ($pClass === 'ManualProvider'): ?>
        <div class="card" style="opacity:0.9">
          <div class="card-head" style="margin-bottom:4px">
            <div class="card-title">
              <svg class="ic" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              <span>پروایدر #<?= $idx + 1 ?>: نرخ دستی (پشتیبان اضطراری)</span>
              <span class="badge on">همیشه فعال</span>
            </div>
            <div style="display:flex;gap:4px">
              <?php if ($idx > 0): ?>
                <form method="post" style="margin:0">
                  <input type="hidden" name="token" value="<?= $esc($given) ?>" />
                  <input type="hidden" name="action" value="move_provider" />
                  <input type="hidden" name="provider_index" value="<?= $idx ?>" />
                  <input type="hidden" name="dir" value="up" />
                  <button type="submit" class="icon-btn" title="افزایش اولویت (بالاتر از API)">↑</button>
                </form>
              <?php endif; ?>
              <?php if ($idx < count($config['providers']) - 1): ?>
                <form method="post" style="margin:0">
                  <input type="hidden" name="token" value="<?= $esc($given) ?>" />
                  <input type="hidden" name="action" value="move_provider" />
                  <input type="hidden" name="provider_index" value="<?= $idx ?>" />
                  <input type="hidden" name="dir" value="down" />
                  <button type="submit" class="icon-btn" title="کاهش اولویت">↓</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
          <p class="hint" style="margin:0">اگر پروایدرهای API قطع باشند یا این گزینه را به اولویت ۱ بیاورید، نرخ‌های تب «ثبت دستی» منتشر می‌شوند.</p>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>

    <!-- افزودن پروایدر جدید یا بازگردانی پیش‌فرض -->
    <div class="card">
      <div class="card-title" style="margin-bottom:10px">
        <svg class="ic" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <span>افزودن منبع API جدید / بازنشانی</span>
      </div>
      <form method="post" class="row">
        <input type="hidden" name="token" value="<?= $esc($given) ?>" />
        <select name="preset_type">
          <option value="markaznerkh">قالب آماده مرکزنرخ (MarkazNerkh)</option>
          <option value="custom">پروایدر HTTP سفارشی (خام)</option>
        </select>
        <button type="submit" name="action" value="add_provider" class="btn ghost">افزودن پروایدر</button>
      </form>
      <form method="post" style="margin-top:8px">
        <input type="hidden" name="token" value="<?= $esc($given) ?>" />
        <button type="submit" name="action" value="reset_default_api" class="btn danger sm" style="width:100%" onclick="return confirm('تنظیمات پروایدرها به پیش‌فرض اولیه بازگردد؟')">
          بازگردانی تنظیمات API به پیش‌فرض اولیه
        </button>
      </form>
    </div>

  </div>

</div>

<script>
function switchTab(tab) {
  document.getElementById('panel-rates').classList.toggle('active', tab === 'rates');
  document.getElementById('panel-providers').classList.toggle('active', tab === 'providers');
  var btns = document.querySelectorAll('.tab-btn');
  if (btns.length >= 2) {
    btns[0].classList.toggle('active', tab === 'rates');
    btns[1].classList.toggle('active', tab === 'providers');
  }
}
</script>
</body>
</html>
