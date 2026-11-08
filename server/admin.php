<?php
/**
 * طلام — داشبورد ادمین ساده و خلوت از مرورگر/گوشی (بدون نیاز به رفتن به هاست)
 *
 * فعال‌سازی: در config.php مقدار 'admin_token' را یک رشته تصادفی بگذار
 * آدرس:      https://YOUR-HOST/server/admin.php?token=YOUR_TOKEN
 *
 * قوانین ثابت: فونت لوکال Vazir (@font-face محلی) و آیکون‌های لوکال (SVG inline).
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
$statePath = (string)($paths['state'] ?? $serverDir . '/data/state.json');
$msg = '';
$msgType = 'ok';
$rawPreview = '';
$activeTab = (string)($_REQUEST['tab'] ?? 'rates');

function writeJsonAtomic(string $path, array $data): void
{
    @mkdir(dirname($path), 0775, true);
    $tmp = $path . '.tmp.' . getmypid();
    if (file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) === false || !rename($tmp, $path)) {
        throw new RuntimeException('cannot write ' . $path);
    }
}

function writeConfigAtomic(string $path, array $config): void
{
    $tmp = $path . '.tmp.' . getmypid();
    $php = "<?php\n// تولید خودکار توسط داشبورد — ویرایش دستی هم مجاز است\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents($tmp, $php) === false || !rename($tmp, $path)) {
        throw new RuntimeException('cannot write ' . $path);
    }
}

function maskKey(string $k): string
{
    $k = trim((string)preg_replace('/^Bearer\s+/i', '', trim($k)));
    if ($k === '') {
        return '';
    }
    return strlen($k) <= 10 ? '***' : substr($k, 0, 5) . '…' . substr($k, -4);
}

/** اطمینان از وجود پروایدرهای پیشفرض و تنظیمات schedule در config */
function ensureProvidersList(array &$config, string $serverDir): void
{
    $def = DefaultConfig::get($serverDir);
    if (!isset($config['schedule']) || !is_array($config['schedule'])) {
        $config['schedule'] = $def['schedule'];
    }
    if (!isset($config['providers']) || !is_array($config['providers']) || empty($config['providers'])) {
        $config['providers'] = $def['providers'];
        return;
    }
    $existingIds = [];
    foreach ($config['providers'] as $p) {
        $id = $p['options']['id'] ?? '';
        if ($id !== '') {
            $existingIds[$id] = true;
        }
    }
    $missing = [];
    foreach ($def['providers'] as $dp) {
        $did = $dp['options']['id'] ?? '';
        if ($did !== '' && !isset($existingIds[$did])) {
            $missing[] = $dp;
        }
    }
    if (!empty($missing)) {
        $newList = [];
        $inserted = false;
        foreach ($config['providers'] as $p) {
            if (!$inserted && ($p['class'] ?? '') === 'ManualProvider') {
                foreach ($missing as $m) {
                    $newList[] = $m;
                }
                $inserted = true;
            }
            $newList[] = $p;
        }
        if (!$inserted) {
            foreach ($missing as $m) {
                $newList[] = $m;
            }
        }
        $config['providers'] = $newList;
    }
}

/** پارس کردن ورودی هوشمند (کلید ساده یا دستور کامل curl) */
function applySmartKeyOrCurl(array $opts, string $input): array
{
    $input = trim($input);
    if ($input === '') {
        return $opts;
    }
    if (stripos($input, 'curl ') !== false || preg_match('#^https?://#i', $input)) {
        $flat = str_replace(["\\\r\n", "\\\n"], ' ', $input);
        if (preg_match('/https?:\/\/[^\s"\'\\\\]+/i', $flat, $m)) {
            $fullUrl = trim($m[0], "\"'");
            $parts = parse_url($fullUrl);
            $baseUrl = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');
            if (stripos($baseUrl, 'api.ezrate.me/v1/rates/') !== false) {
                $baseUrl = 'https://api.ezrate.me/v1/rates';
            }
            $opts['url'] = $baseUrl;
            if (!empty($parts['query'])) {
                parse_str($parts['query'], $q);
                $opts['query'] = $q;
            }
        }
        if (preg_match_all('/(?:-H|--header)\s+(?:"([^"]+)"|\'([^\']+)\')/i', $flat, $hm, PREG_SET_ORDER)) {
            foreach ($hm as $match) {
                $hLine = $match[1] !== '' ? $match[1] : ($match[2] ?? '');
                $pos = strpos($hLine, ':');
                if ($pos !== false) {
                    $hName = trim(substr($hLine, 0, $pos));
                    $hVal = trim(substr($hLine, $pos + 1));
                    if (strcasecmp($hName, 'Authorization') === 0 || preg_match('/^(x-api-key|api-key|apikey|token)$/i', $hName)) {
                        $opts['auth'] = ['type' => 'header', 'name' => $hName, 'key' => $hVal];
                    }
                }
            }
        }
        return $opts;
    }

    $isEzrate = stripos((string)($opts['url'] ?? ''), 'ezrate.me') !== false || ($opts['id'] ?? '') === 'ezrate';
    if ($isEzrate) {
        $cleanKey = (string)preg_replace('/^Bearer\s+/i', '', $input);
        $opts['auth'] = ['type' => 'header', 'name' => 'X-API-Key', 'key' => $cleanKey];
    } else {
        if (stripos($input, 'Bearer ') !== 0 && strcasecmp((string)($opts['auth']['name'] ?? 'Authorization'), 'Authorization') === 0) {
            $input = 'Bearer ' . $input;
        }
        $opts['auth'] = [
            'type' => ($opts['auth']['type'] ?? '') !== '' ? $opts['auth']['type'] : 'header',
            'name' => ($opts['auth']['name'] ?? '') !== '' ? $opts['auth']['name'] : 'Authorization',
            'key' => $input,
        ];
    }
    return $opts;
}

function applyAdvancedFields(array $opts, array $post): array
{
    if (!empty($post['api_label'])) {
        $opts['label'] = trim((string)$post['api_label']);
    }
    if (!empty($post['api_url'])) {
        $rawUrl = trim((string)$post['api_url']);
        $parts = parse_url($rawUrl);
        $opts['url'] = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $uq);
            $opts['query'] = $uq;
        }
    }
    if (isset($post['api_query'])) {
        $qRaw = trim((string)$post['api_query']);
        if ($qRaw === '') {
            $opts['query'] = [];
        } elseif ($qRaw[0] === '{') {
            $qd = json_decode($qRaw, true);
            if (is_array($qd)) {
                $opts['query'] = $qd;
            }
        } else {
            parse_str($qRaw, $qd);
            if (is_array($qd)) {
                $opts['query'] = $qd;
            }
        }
    }
    if (!empty($post['api_map'])) {
        $md = json_decode((string)$post['api_map'], true);
        if (is_array($md) && !empty($md)) {
            $opts['map'] = $md;
        }
    }
    return $opts;
}

ensureProvidersList($config, $serverDir);
$lastGoodPath = (string)($paths['last_good'] ?? '');
$lastGood = is_array(json_decode((string)@file_get_contents($lastGoodPath), true)) ? json_decode((string)file_get_contents($lastGoodPath), true) : null;
$stateData = is_array(json_decode((string)@file_get_contents($statePath), true)) ? json_decode((string)file_get_contents($statePath), true) : [];
$providerState = is_array($stateData['provider_state'] ?? null) ? $stateData['provider_state'] : [];

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
            $srcLbl = $result['published']['source_label'] ?? ($result['published']['source'] ?? '?');
            $msg = $result['status'] === 'ok'
                ? 'نرخ‌ها ذخیره و منتشر شد ✓ — منبع فعال: ' . $srcLbl
                : 'ذخیره شد؛ وضعیت انتشار: ' . $result['status'];
            $lastGood = $result['published'] ?? $lastGood;
        } elseif ($action === 'run_pipeline') {
            $providers = ProviderFactory::createAll($config);
            $result = (new PricePipeline($config, $providers))->run();
            $srcLbl = $result['published']['source_label'] ?? ($result['published']['source'] ?? '—');
            $gPrice = isset($result['published']['gold18']) ? number_format((int)$result['published']['gold18']) . ' تومان' : '';
            $msg = 'بروزرسانی انجام شد (' . $result['status'] . ') — دریافت‌شده از پروایدر: «' . $srcLbl . '» ' . ($gPrice !== '' ? "— گرم ۱۸: $gPrice" : '');
            $lastGood = $result['published'] ?? $lastGood;
            $stateData = is_array(json_decode((string)@file_get_contents($statePath), true)) ? json_decode((string)file_get_contents($statePath), true) : [];
            $providerState = is_array($stateData['provider_state'] ?? null) ? $stateData['provider_state'] : [];
        } elseif ($action === 'save_api' || $action === 'test_api' || $action === 'test_api_raw') {
            $activeTab = 'providers';
            $idx = isset($_POST['provider_index']) ? (int)$_POST['provider_index'] : 0;
            if (!isset($config['providers'][$idx])) {
                throw new RuntimeException('سرویس مورد نظر یافت نشد');
            }
            $opts = $config['providers'][$idx]['options'] ?? [];
            $newKeyInput = trim((string)($_POST['api_auth_key'] ?? ''));
            // اگر کاربر کلید یا دستور curl جدیدی وارد کرده باشد، خودکار این پروایدر را روشن (enabled) کن
            $opts['enabled'] = isset($_POST['api_enabled']) || $newKeyInput !== '';
            $opts = applyAdvancedFields($opts, $_POST);
            $opts = applySmartKeyOrCurl($opts, $newKeyInput);

            // اگر کاربر کلید جدید زده و مستقیماً دکمه «تست دریافت» را زده، کلیدش را هم ذخیره کن تا از بین نرود
            if ($newKeyInput !== '' && ($action === 'test_api' || $action === 'test_api_raw')) {
                $config['providers'][$idx]['options'] = $opts;
                @copy($configFile, $configFile . '.bak');
                writeConfigAtomic($configFile, $config);
                $config = require $configFile;
            }

            // برای تست دستی در پنل، حتی اگر تیک فعال بودن خاموش باشد اجازه تست بده
            $testOpts = $opts;
            $testOpts['enabled'] = true;
            $inst = new ConfigurableHttpProvider($testOpts, $config['http'] ?? []);

            if ($action === 'save_api') {
                $config['providers'][$idx]['options'] = $opts;
                if (!empty($_POST['make_primary']) && $idx > 0) {
                    $item = $config['providers'][$idx];
                    array_splice($config['providers'], $idx, 1);
                    array_unshift($config['providers'], $item);
                }
                @copy($configFile, $configFile . '.bak');
                writeConfigAtomic($configFile, $config);
                $config = require $configFile;
                ensureProvidersList($config, $serverDir);
                $msg = 'تنظیمات پروایدر «' . ($opts['label'] ?? $opts['id']) . '» ذخیره شد ✓';
            } elseif ($action === 'test_api') {
                $t0 = microtime(true);
                $data = $inst->fetch();
                $lat = (int)round((microtime(true) - $t0) * 1000);
                $fields = [];
                foreach (['gold18', 'mazaneh'] as $f) {
                    if (isset($data[$f]) && $data[$f] > 0) {
                        $fields[$f] = number_format((int)$data[$f]);
                    }
                }
                foreach (($data['coins'] ?? []) as $k => $v) {
                    if ($v > 0) {
                        $fields['coins.' . $k] = number_format((int)$v);
                    }
                }
                $msg = 'تست اتصال «' . ($opts['label'] ?? $opts['id']) . '» موفق بود (' . $lat . 'ms) — نرخ‌های دریافتی: ' . json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } elseif ($action === 'test_api_raw') {
                $rmBuild = new ReflectionMethod('ConfigurableHttpProvider', 'buildRequest');
                $rmBuild->setAccessible(true);
                [$url, $hdrs] = $rmBuild->invoke($inst);
                $rmGet = new ReflectionMethod('ConfigurableHttpProvider', 'httpGet');
                $rmGet->setAccessible(true);
                $raw = $rmGet->invoke($inst, $url, $hdrs);
                $masked = preg_replace('/(Bearer\s+|api_key=|api-key=)([A-Za-z0-9_\-]{6})[A-Za-z0-9_\-]+/i', '$1$2***', (string)$raw) ?? '';
                $rawPreview = substr($masked, 0, 1200);
                $msg = 'پاسخ خام از «' . ($opts['label'] ?? $opts['id']) . '» دریافت شد ✓';
            }
        } elseif ($action === 'add_custom_provider') {
            $activeTab = 'providers';
            $customName = trim((string)($_POST['custom_name'] ?? ''));
            if ($customName === '') {
                throw new RuntimeException('لطفاً یک نام برای پروایدر وارد کنید');
            }
            $slug = 'custom-' . (count($config['providers']) + 1);
            $newBlock = [
                'class' => 'ConfigurableHttpProvider',
                'options' => [
                    'id' => $slug,
                    'label' => $customName,
                    'enabled' => true,
                    'url' => 'https://backend.gheymat.online/api/prices',
                    'method' => 'GET',
                    'query' => [],
                    'headers' => [],
                    'auth' => ['type' => 'header', 'name' => 'Authorization', 'key' => ''],
                    'map' => [
                        'gold18' => ['path' => 'data.symbol=GOLD18-IRT.buy_price', 'unit' => 'toman'],
                    ],
                    'max_age_seconds' => 1800,
                    'min_interval_seconds' => 600,
                    'daily_cap' => 250,
                ],
            ];
            $curlOrUrl = trim((string)($_POST['custom_curl'] ?? ''));
            if ($curlOrUrl !== '') {
                $newBlock['options'] = applySmartKeyOrCurl($newBlock['options'], $curlOrUrl);
            } else {
                $newBlock['options']['enabled'] = false; // تا وقتی آدرسش را وارد کند خاموش بماند
            }
            // درج قبل از ManualProvider
            $newList = [];
            $inserted = false;
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
            writeConfigAtomic($configFile, $config);
            $config = require $configFile;
            $msg = 'پروایدر سفارشی «' . $customName . '» اضافه شد ✓';
        } elseif ($action === 'delete_provider') {
            $activeTab = 'providers';
            $idx = (int)($_POST['provider_index'] ?? -1);
            if (isset($config['providers'][$idx]) && ($config['providers'][$idx]['class'] ?? '') !== 'ManualProvider') {
                $delName = $config['providers'][$idx]['options']['label'] ?? ($config['providers'][$idx]['options']['id'] ?? '');
                array_splice($config['providers'], $idx, 1);
                writeConfigAtomic($configFile, $config);
                $config = require $configFile;
                $msg = 'پروایدر «' . $delName . '» حذف شد ✓';
            }
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

$presetMeta = [
    'markaznerkh' => [
        'name' => 'مرکزنرخ (MarkazNerkh.ir)',
        'plan' => 'پیش‌فرض اول • طلای ۱۸ عیار و سکه امامی',
        'needs_key' => true,
    ],
    'nerkh' => [
        'name' => 'نرخ (nerkh.io)',
        'plan' => 'سهمیه ۴۵۰/ماه • استراتژی خودکار: فقط ساعت ۹ تا ۱۸، هر ۸۰ دقیقه (حداکثر ۱۲/روز)',
        'needs_key' => true,
    ],
    'ezrate' => [
        'name' => 'ایزی‌ریت (EZRate.me)',
        'plan' => 'سهمیه ۵۰/روز و ۱۵۰۰/ماه • استراتژی خودکار: هر ۳۵ دقیقه (حداکثر ۲۵/روز)',
        'needs_key' => true,
    ],
    'gheymat' => [
        'name' => 'قیمت آنلاین (gheymat.online)',
        'plan' => 'رایگان بدون نیاز به کلید (۲۵۰/روز) • کامل: طلا ۱۸، مظنه و ۵ سکه',
        'needs_key' => false,
    ],
    'pricetoday' => [
        'name' => 'پرایس‌تودی (priceto.day)',
        'plan' => 'رایگان و بدون محدودیت رسمی (بدون نیاز به کلید) • پشتیبان ساعات کم‌ترافیک',
        'needs_key' => false,
    ],
];

$activeSourceDisplay = $lastGood['source_label'] ?? ($lastGood['source'] ?? 'ثبت نشده');
$todayKey = date('Ymd');
$monthKey = date('Ym');
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>طلام — پنل مدیریت</title>
<style>
  /* فونت لوکال وزیرمتن */
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
    --surface-2: #190D2B;
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
    background: var(--bg);
    color: var(--text);
    margin: 0;
    padding: 16px 14px 48px;
  }
  .wrap { max-width: 490px; margin: 0 auto; }

  .top-bar {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 12px;
  }
  .brand { display: flex; align-items: center; gap: 10px; }
  .brand-icon {
    width: 38px; height: 38px; border-radius: 12px;
    background: linear-gradient(135deg, var(--gold), var(--gold-strong));
    color: #241300; display: inline-flex; align-items: center; justify-content: center;
  }
  .brand h1 { font-size: 16px; margin: 0; font-weight: 800; }
  .brand span { font-size: 11.5px; color: var(--muted); }

  /* بنر منبع فعلی */
  .source-banner {
    background: var(--surface-2);
    border: 1px solid rgba(232, 201, 125, 0.25);
    border-radius: 14px;
    padding: 10px 14px;
    margin-bottom: 14px;
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    font-size: 12px;
  }
  .source-banner strong { color: var(--gold); font-weight: 800; }

  .tabs {
    display: grid; grid-template-columns: 1fr 1fr; gap: 6px;
    background: var(--surface-2); padding: 5px; border-radius: 14px;
    border: 1px solid var(--border); margin-bottom: 14px;
  }
  .tab-btn {
    min-height: 40px; border: none; border-radius: 10px;
    background: transparent; color: var(--muted);
    font-family: inherit; font-size: 13px; font-weight: 700;
    cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;
  }
  .tab-btn.active {
    background: linear-gradient(135deg, var(--gold), var(--gold-strong));
    color: #241300;
  }
  .tab-panel { display: none; }
  .tab-panel.active { display: block; }

  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 15px;
    margin-bottom: 12px;
  }
  .card-head {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 8px; margin-bottom: 10px;
  }
  .card-title { font-size: 14px; font-weight: 800; color: var(--gold); }
  .card-sub { font-size: 11px; color: var(--muted); margin-top: 3px; line-height: 1.6; }

  .badge {
    font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 99px; white-space: nowrap;
  }
  .badge.on { background: rgba(52,211,153,.16); color: var(--ok); }
  .badge.off { background: rgba(255,255,255,.07); color: var(--muted); }

  label { display: block; font-size: 12px; font-weight: 700; margin-bottom: 5px; color: #E5DDF2; }
  input, textarea {
    width: 100%; background: var(--surface-2);
    border: 1px solid var(--border); border-radius: 11px;
    color: #fff; font-family: inherit; font-size: 14.5px; font-weight: 600;
    padding: 10px 12px; outline: none; text-align: center;
  }
  input[dir="ltr"], textarea[dir="ltr"] {
    text-align: left; font-family: ui-monospace, Consolas, monospace; font-size: 12.5px;
  }
  input:focus, textarea:focus { border-color: var(--gold-strong); }

  .row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
  .btn {
    width: 100%; min-height: 42px; border: none; border-radius: 11px;
    background: linear-gradient(135deg, var(--gold), var(--gold-strong));
    color: #241300; font-family: inherit; font-size: 13px; font-weight: 800;
    cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  }
  .btn.ghost {
    background: rgba(232,201,125,.08);
    border: 1px solid rgba(212,168,67,.35);
    color: var(--gold);
  }
  .btn.sm { min-height: 34px; font-size: 12px; padding: 0 12px; width: auto; border-radius: 9px; }

  .switch-row {
    display: flex; align-items: center; justify-content: space-between;
    background: var(--surface-2); padding: 8px 12px; border-radius: 11px;
    margin-bottom: 10px; font-size: 12px;
  }
  .chk { display: inline-flex; align-items: center; gap: 7px; cursor: pointer; font-weight: 700; margin: 0; }
  .chk input { width: 17px; height: 17px; accent-color: var(--gold-strong); margin: 0; }

  .msg {
    border-radius: 12px; padding: 11px 13px; font-size: 12.5px; margin-bottom: 12px; line-height: 1.6;
  }
  .msg.ok { background: rgba(52,211,153,.15); color: var(--ok); border: 1px solid rgba(52,211,153,.3); }
  .msg.err { background: rgba(244,121,107,.15); color: var(--err); border: 1px solid rgba(244,121,107,.3); }

  .strategy-note {
    background: rgba(52, 211, 153, 0.08);
    border: 1px solid rgba(52, 211, 153, 0.22);
    border-radius: 12px;
    padding: 10px 12px;
    font-size: 11.5px;
    color: #D1FAE5;
    margin-bottom: 12px;
    line-height: 1.7;
  }
  details.adv { margin-top: 8px; font-size: 11.5px; color: var(--muted); }
  details.adv summary { cursor: pointer; color: var(--muted); padding: 4px 0; user-select: none; }
  .raw-box {
    background: #10081C; border: 1px solid var(--gold-strong); border-radius: 11px;
    padding: 10px; font-family: monospace; font-size: 11px; direction: ltr; text-align: left;
    white-space: pre-wrap; word-break: break-all; max-height: 200px; overflow: auto; margin-bottom: 12px; color: var(--gold);
  }
  svg.ic { width: 17px; height: 17px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
</style>
</head>
<body>
<div class="wrap">

  <!-- هدر -->
  <div class="top-bar">
    <div class="brand">
      <span class="brand-icon">
        <svg class="ic" viewBox="0 0 24 24"><path d="M6 3h12l4 6-10 13L2 9Z"/><path d="M2 9h20"/></svg>
      </span>
      <div>
        <h1>پنل مدیریت طلام</h1>
        <span>نرخ فعلی: <strong style="color:#fff"><?= isset($lastGood['gold18']) ? number_format((int)$lastGood['gold18']) . ' تومان' : '—' ?></strong></span>
      </div>
    </div>
    <form method="post" style="margin:0">
      <input type="hidden" name="token" value="<?= $esc($given) ?>" />
      <input type="hidden" name="action" value="run_pipeline" />
      <button type="submit" class="btn ghost sm">
        <svg class="ic" viewBox="0 0 24 24"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M21 21v-5h-5"/></svg>
        بروزرسانی و دریافت نرخ
      </button>
    </form>
  </div>

  <!-- نمایش واضح پروایدری که آخرین نرخ از آن دریافت شده -->
  <div class="source-banner">
    <div>
      <span>دریافت‌شده از پروایدر: </span>
      <strong><?= $esc($activeSourceDisplay) ?></strong>
    </div>
    <a href="public/prices.json?t=<?= time() ?>" target="_blank" style="color:var(--gold);text-decoration:none;font-weight:700">خروجی JSON ↗</a>
  </div>

  <?php if ($msg !== ''): ?>
    <div class="msg <?= $msgType === 'ok' ? 'ok' : 'err' ?>"><?= $esc($msg) ?></div>
  <?php endif; ?>

  <?php if ($rawPreview !== ''): ?>
    <div class="raw-box"><?= $esc($rawPreview) ?></div>
  <?php endif; ?>

  <!-- تب‌ها -->
  <div class="tabs">
    <button type="button" class="tab-btn <?= $activeTab === 'rates' ? 'active' : '' ?>" onclick="switchTab('rates')">
      <svg class="ic" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
      نرخ دستی
    </button>
    <button type="button" class="tab-btn <?= $activeTab === 'providers' ? 'active' : '' ?>" onclick="switchTab('providers')">
      <svg class="ic" viewBox="0 0 24 24"><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><path d="M12 20h.01"/></svg>
      پروایدرها (چرخش هوشمند)
    </button>
  </div>

  <!-- تب ۱: نرخ دستی -->
  <div id="panel-rates" class="tab-panel <?= $activeTab === 'rates' ? 'active' : '' ?>">
    <form method="post">
      <input type="hidden" name="token" value="<?= $esc($given) ?>" />
      <input type="hidden" name="action" value="save_rates" />
      <div class="card">
        <label>گرم طلای ۱۸ عیار (تومان) *</label>
        <input name="gold18" inputmode="decimal" value="<?= $esc($current['gold18'] ?? '') ?>" required />
        <label style="margin-top:10px">مظنه تهران (تومان) — اختیاری</label>
        <input name="mazaneh" inputmode="decimal" value="<?= $esc($current['mazaneh'] ?? '') ?>" placeholder="محاسبه خودکار از گرم" />
      </div>
      <div class="card">
        <label style="margin-bottom:8px">سکه‌ها (تومان)</label>
        <div class="row">
          <div><label>امامی</label><input name="coins[emami]" inputmode="decimal" value="<?= $esc($current['coins']['emami'] ?? '') ?>" /></div>
          <div><label>بهار آزادی</label><input name="coins[bahar]" inputmode="decimal" value="<?= $esc($current['coins']['bahar'] ?? '') ?>" /></div>
          <div><label>نیم‌سکه</label><input name="coins[nim]" inputmode="decimal" value="<?= $esc($current['coins']['nim'] ?? '') ?>" /></div>
          <div><label>ربع‌سکه</label><input name="coins[rob]" inputmode="decimal" value="<?= $esc($current['coins']['rob'] ?? '') ?>" /></div>
          <div><label>گرمی</label><input name="coins[gerami]" inputmode="decimal" value="<?= $esc($current['coins']['gerami'] ?? '') ?>" /></div>
        </div>
      </div>
      <button type="submit" class="btn">ذخیره و انتشار فوری نرخ‌ها</button>
    </form>
  </div>

  <!-- تب ۲: پروایدرها -->
  <div id="panel-providers" class="tab-panel <?= $activeTab === 'providers' ? 'active' : '' ?>">

    <div class="strategy-note">
      ✓ <strong>استراتژی خودکار فعال است:</strong> از ساعت ۲۳:۰۰ تا ۰۶:۰۰ صبح هیچ درخواستی ارسال نمی‌شود. در طول روز بین پروایدرهای روشن به‌صورت نوبتی (Round-Robin) می‌چرخد و اگر یکی خطا داد یا سهمیه‌اش پر شد، خودکار سراغ بعدی می‌رود.
    </div>

    <?php foreach (($config['providers'] ?? []) as $idx => $provSpec):
        if (($provSpec['class'] ?? '') !== 'ConfigurableHttpProvider') {
            continue;
        }
        $opts = is_array($provSpec['options'] ?? null) ? $provSpec['options'] : [];
        $pid = (string)($opts['id'] ?? ('api-' . ($idx + 1)));
        $enabled = !empty($opts['enabled']);
        $authKey = (string)($opts['auth']['key'] ?? '');
        $masked = maskKey($authKey);
        $meta = $presetMeta[$pid] ?? [
            'name' => (string)($opts['label'] ?? $pid),
            'plan' => 'پروایدر سفارشی',
            'needs_key' => true,
        ];
        $callsToday = (int)($providerState[$pid]['daily'][$todayKey] ?? 0);
        $callsMonth = (int)($providerState[$pid]['monthly'][$monthKey] ?? 0);
    ?>
      <div class="card">
        <form method="post">
          <input type="hidden" name="token" value="<?= $esc($given) ?>" />
          <input type="hidden" name="provider_index" value="<?= $idx ?>" />

          <div class="card-head">
            <div>
              <div class="card-title"><?= $esc($meta['name']) ?></div>
              <div class="card-sub"><?= $esc($meta['plan']) ?></div>
            </div>
            <span class="badge <?= $enabled ? 'on' : 'off' ?>"><?= $enabled ? 'روشن' : 'خاموش' ?></span>
          </div>

          <div class="switch-row">
            <label class="chk">
              <input type="checkbox" name="api_enabled" <?= $enabled ? 'checked' : '' ?> />
              <span>فعال در چرخش</span>
            </label>
            <span style="color:var(--muted)">مصرف امروز: <?= $callsToday ?> | ماه: <?= $callsMonth ?></span>
          </div>

          <?php if ($meta['needs_key']): ?>
            <label>
              کلید API یا دستور curl
              <span style="font-weight:400;color:var(--muted)">(فعلی: <?= $esc($masked ?: 'ثبت نشده') ?>)</span>
            </label>
            <input name="api_auth_key" dir="ltr" placeholder="کلید API یا دستور curl را وارد کن (خالی = بدون تغییر)" />
          <?php else: ?>
            <input type="hidden" name="api_auth_key" value="" />
          <?php endif; ?>

          <div class="row" style="margin-top:10px">
            <button type="submit" name="action" value="save_api" class="btn">ذخیره</button>
            <button type="submit" name="action" value="test_api" class="btn ghost">تست دریافت</button>
          </div>

          <details class="adv">
            <summary>تنظیمات فنی پروایدر</summary>
            <div style="margin-top:8px">
              <label>نام نمایشی پروایدر</label>
              <input name="api_label" value="<?= $esc($opts['label'] ?? $meta['name']) ?>" style="margin-bottom:6px" />
              <label>آدرس API</label>
              <input name="api_url" dir="ltr" value="<?= $esc($opts['url'] ?? '') ?>" />
              <label style="margin-top:6px">پارامترهای Query</label>
              <input name="api_query" dir="ltr" value="<?= $esc(http_build_query(is_array($opts['query'] ?? null) ? $opts['query'] : [])) ?>" />
              <label style="margin-top:6px">نگاشت فیلدها (Map JSON)</label>
              <textarea name="api_map" dir="ltr" style="min-height:54px"><?= $esc(json_encode($opts['map'] ?? new stdClass(), JSON_UNESCAPED_SLASHES)) ?></textarea>
              <div style="display:flex;gap:8px;margin-top:8px">
                <button type="submit" name="action" value="test_api_raw" class="btn ghost sm">نمایش پاسخ خام (RAW)</button>
                <?php if (!isset($presetMeta[$pid])): ?>
                  <button type="submit" name="action" value="delete_provider" class="btn ghost sm" style="color:var(--err);border-color:var(--err)">حذف این پروایدر</button>
                <?php endif; ?>
              </div>
            </div>
          </details>
        </form>
      </div>
    <?php endforeach; ?>

    <!-- افزودن کاستوم پروایدر فقط با تعریف اسم -->
    <div class="card">
      <div class="card-title" style="margin-bottom:8px">+ افزودن کاستوم پروایدر</div>
      <form method="post">
        <input type="hidden" name="token" value="<?= $esc($given) ?>" />
        <input type="hidden" name="action" value="add_custom_provider" />
        <label>نام پروایدر جدید *</label>
        <input name="custom_name" placeholder="مثلاً: صرافی من / نوسان" required style="margin-bottom:8px" />
        <label>دستور curl یا آدرس API <span style="font-weight:400;color:var(--muted)">(اختیاری — بعداً هم می‌توانی وارد کنی)</span></label>
        <input name="custom_curl" dir="ltr" placeholder='curl "https://..."' />
        <button type="submit" class="btn ghost" style="margin-top:10px">ایجاد پروایدر</button>
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
