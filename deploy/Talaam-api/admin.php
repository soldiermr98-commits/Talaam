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

/** اطمینان از وجود هر دو پروایدر پیشفرض (markaznerkh و ezrate) در config */
function ensureProvidersList(array &$config, string $serverDir): void
{
    $def = DefaultConfig::get($serverDir);
    if (!isset($config['providers']) || !is_array($config['providers']) || empty($config['providers'])) {
        $config['providers'] = $def['providers'];
        return;
    }
    // اگر ezrate هنوز در لیست نبود، قبل از Manual اضافه شود
    $hasEz = false;
    foreach ($config['providers'] as $p) {
        if (($p['options']['id'] ?? '') === 'ezrate' || stripos((string)($p['options']['url'] ?? ''), 'ezrate.me') !== false) {
            $hasEz = true;
            break;
        }
    }
    if (!$hasEz) {
        $ezBlock = $def['providers'][1];
        $newList = [];
        $inserted = false;
        foreach ($config['providers'] as $p) {
            if (!$inserted && ($p['class'] ?? '') === 'ManualProvider') {
                $newList[] = $ezBlock;
                $inserted = true;
            }
            $newList[] = $p;
        }
        if (!$inserted) {
            $newList[] = $ezBlock;
        }
        $config['providers'] = $newList;
    }
}

/** پارس کردن ورودی هوشمند (کلید ساده یا دستور کامل curl) */
function applySmartKeyOrCurl(array $opts, string $input): array
{
    $input = trim($input);
    if ($input === '') {
        return $opts; // بدون تغییر کلید
    }
    // اگر کاربر دستور curl یا لینک کامل وارد کرده باشد
    if (stripos($input, 'curl ') !== false || preg_match('#^https?://#i', $input)) {
        $flat = str_replace(["\\\r\n", "\\\n"], ' ', $input);
        if (preg_match('/https?:\/\/[^\s"\'\\\\]+/i', $flat, $m)) {
            $fullUrl = trim($m[0], "\"'");
            $parts = parse_url($fullUrl);
            $baseUrl = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');
            // اگر روی ezrate تک‌نرخی مثل /v1/rates/usd زده بود، خودکار به /v1/rates تبدیل شود تا طلا و سکه را بگیرد
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

    // اگر فقط کلید API را وارد کرده باشد
    $isEzrate = stripos((string)($opts['url'] ?? ''), 'ezrate.me') !== false || ($opts['id'] ?? '') === 'ezrate';
    if ($isEzrate) {
        $cleanKey = (string)preg_replace('/^Bearer\s+/i', '', $input);
        $opts['auth'] = ['type' => 'header', 'name' => 'X-API-Key', 'key' => $cleanKey];
    } else {
        if (stripos($input, 'Bearer ') !== 0 && strcasecmp((string)($opts['auth']['name'] ?? 'Authorization'), 'Authorization') === 0) {
            $input = 'Bearer ' . $input;
        }
        $opts['auth'] = [
            'type' => $opts['auth']['type'] ?? 'header',
            'name' => $opts['auth']['name'] ?? 'Authorization',
            'key' => $input,
        ];
    }
    return $opts;
}

/** اعمال فیلدهای پیشرفته در صورت باز بودن بخش پیشرفته */
function applyAdvancedFields(array $opts, array $post): array
{
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
                ? 'نرخ‌ها ذخیره و منتشر شد ✓ (منبع: ' . ($result['published']['source'] ?? '?') . ')'
                : 'ذخیره شد؛ وضعیت انتشار: ' . $result['status'];
            $lastGood = $result['published'] ?? $lastGood;
        } elseif ($action === 'run_pipeline') {
            $providers = ProviderFactory::createAll($config);
            $result = (new PricePipeline($config, $providers))->run();
            $msg = 'بروزرسانی انجام شد — وضعیت: ' . $result['status'] . (isset($result['published']['gold18']) ? ' (گرم ۱۸: ' . number_format((int)$result['published']['gold18']) . ' تومان)' : '');
            $lastGood = $result['published'] ?? $lastGood;
        } elseif ($action === 'save_api' || $action === 'test_api' || $action === 'test_api_raw') {
            $activeTab = 'providers';
            $idx = isset($_POST['provider_index']) ? (int)$_POST['provider_index'] : 0;
            if (!isset($config['providers'][$idx])) {
                throw new RuntimeException('سرویس مورد نظر یافت نشد');
            }
            $opts = $config['providers'][$idx]['options'] ?? [];
            $opts['enabled'] = isset($_POST['api_enabled']);
            $opts = applyAdvancedFields($opts, $_POST);
            $opts = applySmartKeyOrCurl($opts, (string)($_POST['api_auth_key'] ?? ''));

            $inst = new ConfigurableHttpProvider($opts, $config['http'] ?? []);

            if ($action === 'save_api') {
                $config['providers'][$idx]['options'] = $opts;
                // اگر کاربر این سرویس را «بعنوان منبع اول» تیک زده باشد، به ابتدای لیست منتقل شود
                if (!empty($_POST['make_primary']) && $idx > 0) {
                    $item = $config['providers'][$idx];
                    array_splice($config['providers'], $idx, 1);
                    array_unshift($config['providers'], $item);
                }
                @copy($configFile, $configFile . '.bak');
                writeConfigAtomic($configFile, $config);
                $config = require $configFile;
                ensureProvidersList($config, $serverDir);
                $msg = 'تنظیمات سرویس «' . ($opts['id'] ?? '') . '» ذخیره شد ✓';
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
                $msg = 'اتصال موفق (' . $lat . 'ms) — نرخ‌های دریافتی: ' . json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } elseif ($action === 'test_api_raw') {
                $rmBuild = new ReflectionMethod('ConfigurableHttpProvider', 'buildRequest');
                $rmBuild->setAccessible(true);
                [$url, $hdrs] = $rmBuild->invoke($inst);
                $rmGet = new ReflectionMethod('ConfigurableHttpProvider', 'httpGet');
                $rmGet->setAccessible(true);
                $raw = $rmGet->invoke($inst, $url, $hdrs);
                $masked = preg_replace('/(Bearer\s+|api_key=|api-key=)([A-Za-z0-9_\-]{6})[A-Za-z0-9_\-]+/i', '$1$2***', (string)$raw) ?? '';
                $rawPreview = substr($masked, 0, 1200);
                $msg = 'پاسخ خام سرور دریافت شد ✓';
            }
        } elseif ($action === 'add_custom_provider') {
            $activeTab = 'providers';
            $newBlock = [
                'class' => 'ConfigurableHttpProvider',
                'options' => [
                    'id' => 'custom-' . count($config['providers']),
                    'enabled' => true,
                    'url' => 'https://api.ezrate.me/v1/rates',
                    'method' => 'GET',
                    'query' => ['symbols' => 'g18,mesghal,emami,bahar,nim,rob,gerami'],
                    'headers' => [],
                    'auth' => ['type' => 'header', 'name' => 'X-API-Key', 'key' => ''],
                    'map' => ['gold18' => ['path' => 'data.symbol=g18.sell', 'unit' => 'toman']],
                    'max_age_seconds' => 600,
                    'min_interval_seconds' => 300,
                    'daily_cap' => 300,
                ],
            ];
            $newBlock['options'] = applySmartKeyOrCurl($newBlock['options'], (string)($_POST['custom_curl'] ?? ''));
            array_unshift($config['providers'], $newBlock);
            writeConfigAtomic($configFile, $config);
            $config = require $configFile;
            $msg = 'سرویس جدید اضافه شد ✓';
        } elseif ($action === 'delete_provider') {
            $activeTab = 'providers';
            $idx = (int)($_POST['provider_index'] ?? -1);
            if (isset($config['providers'][$idx]) && ($config['providers'][$idx]['class'] ?? '') !== 'ManualProvider') {
                array_splice($config['providers'], $idx, 1);
                writeConfigAtomic($configFile, $config);
                $config = require $configFile;
                $msg = 'سرویس حذف شد ✓';
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

$providerTitles = [
    'markaznerkh' => ['name' => 'مرکزنرخ (MarkazNerkh.ir)', 'desc' => 'پیش‌فرض اول — طلای ۱۸ عیار و سکه امامی'],
    'ezrate' => ['name' => 'ایزی‌ریت (EZRate.me)', 'desc' => 'همه نرخ‌ها: طلا ۱۸، مظنه و هر ۵ نوع سکه'],
];
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
  .wrap { max-width: 480px; margin: 0 auto; }

  .top-bar {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 14px;
  }
  .brand { display: flex; align-items: center; gap: 10px; }
  .brand-icon {
    width: 36px; height: 36px; border-radius: 11px;
    background: linear-gradient(135deg, var(--gold), var(--gold-strong));
    color: #241300; display: inline-flex; align-items: center; justify-content: center;
  }
  .brand h1 { font-size: 16px; margin: 0; font-weight: 800; }
  .brand span { font-size: 11.5px; color: var(--muted); }

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
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 10px;
  }
  .card-title { font-size: 14px; font-weight: 800; color: var(--gold); }
  .card-sub { font-size: 11px; color: var(--muted); margin-top: 2px; }

  .badge {
    font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 99px;
  }
  .badge.on { background: rgba(52,211,153,.16); color: var(--ok); }
  .badge.off { background: rgba(255,255,255,.07); color: var(--muted); }

  label { display: block; font-size: 12px; font-weight: 700; margin-bottom: 5px; color: #E5DDF2; }
  input, textarea {
    width: 100%; background: var(--surface-2);
    border: 1px solid var(--border); border-radius: 11px;
    color: #fff; font-family: inherit; font-size: 15px; font-weight: 600;
    padding: 10px 12px; outline: none; text-align: center;
  }
  input[dir="ltr"], textarea[dir="ltr"] {
    text-align: left; font-family: ui-monospace, Consolas, monospace; font-size: 12.5px;
  }
  input:focus, textarea:focus { border-color: var(--gold-strong); }

  .row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
  .btn {
    width: 100%; min-height: 44px; border: none; border-radius: 12px;
    background: linear-gradient(135deg, var(--gold), var(--gold-strong));
    color: #241300; font-family: inherit; font-size: 13.5px; font-weight: 800;
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
    background: var(--surface-2); padding: 9px 12px; border-radius: 11px;
    margin-bottom: 10px; font-size: 12.5px;
  }
  .chk { display: inline-flex; align-items: center; gap: 7px; cursor: pointer; font-weight: 700; margin: 0; }
  .chk input { width: 17px; height: 17px; accent-color: var(--gold-strong); margin: 0; }

  .msg {
    border-radius: 12px; padding: 11px 13px; font-size: 12.5px; margin-bottom: 12px; line-height: 1.6;
  }
  .msg.ok { background: rgba(52,211,153,.15); color: var(--ok); border: 1px solid rgba(52,211,153,.3); }
  .msg.err { background: rgba(244,121,107,.15); color: var(--err); border: 1px solid rgba(244,121,107,.3); }

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

  <!-- هدر ساده -->
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
        دریافت فوری نرخ
      </button>
    </form>
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
      سرویس‌های API
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

  <!-- تب ۲: سرویس‌های API (ساده و خلوت) -->
  <div id="panel-providers" class="tab-panel <?= $activeTab === 'providers' ? 'active' : '' ?>">

    <?php foreach (($config['providers'] ?? []) as $idx => $provSpec):
        if (($provSpec['class'] ?? '') !== 'ConfigurableHttpProvider') {
            continue;
        }
        $opts = is_array($provSpec['options'] ?? null) ? $provSpec['options'] : [];
        $pid = (string)($opts['id'] ?? ('api-' . ($idx + 1)));
        $enabled = !empty($opts['enabled']);
        $authKey = (string)($opts['auth']['key'] ?? '');
        $masked = maskKey($authKey);
        $meta = $providerTitles[$pid] ?? ['name' => 'سرویس ' . $pid, 'desc' => (string)($opts['url'] ?? '')];
    ?>
      <div class="card">
        <form method="post">
          <input type="hidden" name="token" value="<?= $esc($given) ?>" />
          <input type="hidden" name="provider_index" value="<?= $idx ?>" />

          <div class="card-head">
            <div>
              <div class="card-title"><?= $idx === 0 ? '⭐ ' : '' ?><?= $esc($meta['name']) ?></div>
              <div class="card-sub"><?= $esc($meta['desc']) ?></div>
            </div>
            <span class="badge <?= $enabled ? 'on' : 'off' ?>"><?= $enabled ? 'فعال' : 'خاموش' ?></span>
          </div>

          <div class="switch-row">
            <label class="chk">
              <input type="checkbox" name="api_enabled" <?= $enabled ? 'checked' : '' ?> />
              <span>این سرویس روشن باشد</span>
            </label>
            <?php if ($idx > 0): ?>
              <label class="chk" style="color:var(--gold)">
                <input type="checkbox" name="make_primary" value="1" />
                <span>انتخاب به‌عنوان منبع اول</span>
              </label>
            <?php else: ?>
              <span style="font-size:11px;color:var(--gold)">اولویت اول</span>
            <?php endif; ?>
          </div>

          <label>
            کلید API یا دستور curl
            <span style="font-weight:400;color:var(--muted)">(فعلی: <?= $esc($masked ?: 'ثبت نشده') ?>)</span>
          </label>
          <input name="api_auth_key" dir="ltr" placeholder="کلید جدید یا دستور curl را اینجا Paste کن (خالی = بدون تغییر)" />

          <div class="row" style="margin-top:10px">
            <button type="submit" name="action" value="save_api" class="btn">ذخیره</button>
            <button type="submit" name="action" value="test_api" class="btn ghost">تست اتصال</button>
          </div>

          <details class="adv">
            <summary>تنظیمات فنی (آدرس، پارامترها و پاسخ خام)</summary>
            <div style="margin-top:8px">
              <label>آدرس API</label>
              <input name="api_url" dir="ltr" value="<?= $esc($opts['url'] ?? '') ?>" />
              <label style="margin-top:6px">پارامترهای Query</label>
              <input name="api_query" dir="ltr" value="<?= $esc(http_build_query(is_array($opts['query'] ?? null) ? $opts['query'] : [])) ?>" />
              <label style="margin-top:6px">نگاشت فیلدها (Map JSON)</label>
              <textarea name="api_map" dir="ltr" style="min-height:60px"><?= $esc(json_encode($opts['map'] ?? new stdClass(), JSON_UNESCAPED_SLASHES)) ?></textarea>
              <div style="display:flex;gap:8px;margin-top:8px">
                <button type="submit" name="action" value="test_api_raw" class="btn ghost sm">نمایش پاسخ خام (RAW)</button>
                <?php if (!isset($providerTitles[$pid])): ?>
                  <button type="submit" name="action" value="delete_provider" class="btn ghost sm" style="color:var(--err);border-color:var(--err)">حذف این سرویس</button>
                <?php endif; ?>
              </div>
            </div>
          </details>
        </form>
      </div>
    <?php endforeach; ?>

    <!-- افزودن سرویس دیگر فقط در صورت نیاز -->
    <details class="card" style="padding:12px 15px">
      <summary style="cursor:pointer;font-size:13px;font-weight:700;color:var(--gold)">+ افزودن سرویس API دیگر با دستور curl</summary>
      <form method="post" style="margin-top:10px">
        <input type="hidden" name="token" value="<?= $esc($given) ?>" />
        <input type="hidden" name="action" value="add_custom_provider" />
        <input name="custom_curl" dir="ltr" placeholder='curl "https://..." -H "Authorization: ..."' required />
        <button type="submit" class="btn ghost" style="margin-top:8px">افزودن سرویس</button>
      </form>
    </details>

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
