<?php
/**
 * طلام — داشبورد ادمین از مرورگر/گوشی (بدون نیاز به رفتن به هاست)
 *
 * فعال‌سازی: در config.php مقدار 'admin_token' را یک رشته تصادفی بگذار (خالی = خاموش)
 * آدرس:      https://YOUR-HOST/server/admin.php?token=YOUR_TOKEN
 *
 * بخش‌ها:
 *   1) نرخ‌ها      → ذخیره override.json (اعتبار ۲۴ ساعت) + انتشار فوری
 *   2) تنظیمات API → ویرایش اولین ConfigurableHttpProvider (url/query/auth/map) + تست اتصال
 *                    با نمایش RAW پاسخ هنگام خطای mapping — بدون رفتن به هاست
 *
 * امنیت: توکن timing-safe؛ هیچ secret در خروجی تست (کلید ماسک میشود)؛ config.php با var_export بازنویسی میشود.
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
require $serverDir . '/src/ProviderFactory.php';
require $serverDir . '/src/PricePipeline.php';

$configFile = $serverDir . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    exit('server not configured — config.php missing');
}
/** @var array<string,mixed> $config */
$config = require $configFile;

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
$view = $_POST['action'] ?? 'rates';

/** ذخیره اتمیک آرایه به مسیر */
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

/** اولین بلوک ConfigurableHttpProvider از config (یا null) */
function firstHttpProviderIndex(array $config): ?int
{
    foreach (($config['providers'] ?? []) as $i => $p) {
        if (($p['class'] ?? '') === 'ConfigurableHttpProvider') {
            return (int)$i;
        }
    }
    return null;
}

function maskKey(string $k): string
{
    $k = trim($k);
    if ($k === '') {
        return '';
    }
    return strlen($k) <= 8 ? '***' : substr($k, 0, 4) . '***' . substr($k, -4);
}

$lastGoodPath = (string)($paths['last_good'] ?? '');
$lastGood = is_array(json_decode((string)@file_get_contents($lastGoodPath), true)) ? json_decode((string)file_get_contents($lastGoodPath), true) : null;

// ─── POST handling ───
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save_rates') {
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
                ? 'نرخ‌ها ذخیره و منتشر شد ✓ (source: ' . ($result['published']['source'] ?? '?') . ')'
                : 'ذخیره شد؛ وضعیت انتشار: ' . $result['status'];
            $lastGood = $result['published'] ?? $lastGood;
        } elseif ($action === 'save_api') {
            $i = firstHttpProviderIndex($config);
            if ($i === null) {
                throw new RuntimeException('هیچ بلوک ConfigurableHttpProvider در config.php نیست — اول از config.example اضافه کن');
            }
            $opts = $config['providers'][$i]['options'] ?? [];
            $json = static function (string $raw, string $what): array {
                $d = json_decode(trim($raw) !== '' ? $raw : '{}', true);
                if (!is_array($d)) {
                    throw new RuntimeException("$what: JSON نامعتبر");
                }
                return $d;
            };
            $query = $json((string)($_POST['api_query'] ?? ''), 'Query');
            $headers = $json((string)($_POST['api_headers'] ?? ''), 'Headers');
            $map = $json((string)($_POST['api_map'] ?? ''), 'Map');
            $allowed = ['gold18', 'mazaneh', 'coins.emami', 'coins.bahar', 'coins.nim', 'coins.rob', 'coins.gerami'];
            foreach ($map as $f => $spec) {
                if (!in_array((string)$f, $allowed, true) || !is_array($spec) || empty($spec['path'])) {
                    throw new RuntimeException("Map: فیلد '$f' نامعتبر است (path اجباری؛ فیلدهای مجاز: " . implode(', ', $allowed) . ')');
                }
            }
            $authType = in_array($_POST['api_auth_type'] ?? '', ['', 'query', 'header', 'bearer'], true) ? (string)$_POST['api_auth_type'] : '';
            $newKey = trim((string)($_POST['api_auth_key'] ?? ''));
            $oldKey = (string)($opts['auth']['key'] ?? '');
            if ($newKey === '') {
                $newKey = $oldKey; // خالی = بدون تغییر
            }
            $opts['id'] = trim((string)($opts['id'] ?? ($_POST['api_id'] ?? 'api-source')));
            $opts['enabled'] = isset($_POST['api_enabled']);
            $opts['url'] = trim((string)($_POST['api_url'] ?? ''));
            if ($opts['url'] === '' || !preg_match('#^https?://#i', $opts['url'])) {
                throw new RuntimeException('URL نامعتبر است');
            }
            $opts['method'] = 'GET';
            $opts['query'] = $query;
            $opts['headers'] = $headers;
            $opts['auth'] = ['type' => $authType, 'name' => trim((string)($_POST['api_auth_name'] ?? 'api_key')), 'key' => $newKey];
            $opts['map'] = $map;
            if (isset($_POST['api_min_interval'])) {
                $opts['min_interval_seconds'] = max(0, (int)$_POST['api_min_interval']);
            }
            if (isset($_POST['api_daily_cap'])) {
                $opts['daily_cap'] = max(0, (int)$_POST['api_daily_cap']);
            }
            $config['providers'][$i]['options'] = $opts;
            // اعتبارسنجی با ساخت نمونه — خطای کانفیگ قبل از ذخیره ظاهر میشود
            new ConfigurableHttpProvider($opts, $config['http'] ?? []);
            @copy($configFile, $configFile . '.bak');
            writeConfigAtomic($configFile, $config);
            $config = require $configFile;
            $msg = 'تنظیمات API ذخیره شد ✓';
        } elseif ($action === 'test_api') {
            $i = firstHttpProviderIndex($config);
            if ($i === null) {
                throw new RuntimeException('بلوک API در config.php نیست');
            }
            // اگر فرم پر شده باشد از مقادیر فرم تست میکنیم وگرنه از config فعلی
            if (isset($_POST['api_url'])) {
                $opts = $config['providers'][$i]['options'] ?? [];
                $json = static function (string $raw, string $what): array {
                    $d = json_decode(trim($raw) !== '' ? $raw : '{}', true);
                    return is_array($d) ? $d : [];
                };
                $opts['enabled'] = isset($_POST['api_enabled']);
                $opts['url'] = trim((string)$_POST['api_url']);
                $opts['query'] = $json((string)$_POST['api_query'], 'q');
                $opts['headers'] = $json((string)$_POST['api_headers'], 'h');
                $authType = in_array($_POST['api_auth_type'] ?? '', ['', 'query', 'header', 'bearer'], true) ? (string)$_POST['api_auth_type'] : '';
                $newKey = trim((string)($_POST['api_auth_key'] ?? ''));
                $opts['auth'] = [
                    'type' => $authType,
                    'name' => trim((string)($_POST['api_auth_name'] ?? 'api_key')),
                    'key' => $newKey !== '' ? $newKey : (string)($opts['auth']['key'] ?? ''),
                ];
                $opts['map'] = $json((string)$_POST['api_map'], 'map');
                $provider = new ConfigurableHttpProvider($opts, $config['http'] ?? []);
            } else {
                $provider = ProviderFactory::create($config['providers'][$i] ?? [], $config['http'] ?? []);
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
            $msg = 'اتصال موفق (' . $lat . 'ms) — فیلدهای پارسشده: ' . json_encode($fields, JSON_UNESCAPED_SLASHES);
            $msgType = 'ok';
        } elseif ($action === 'test_api_raw') {
            // نمایش RAW پاسخ برای تنظیم map — بدون key
            $i = firstHttpProviderIndex($config);
            $opts = $config['providers'][$i]['options'] ?? [];
            [$url] = (new ReflectionMethod('ConfigurableHttpProvider', 'buildRequest'))->invoke(new ConfigurableHttpProvider($opts, $config['http'] ?? []));
            $raw = (new ReflectionMethod('ConfigurableHttpProvider', 'httpGet'))->invoke(new ConfigurableHttpProvider($opts, $config['http'] ?? []), $url);
            $masked = preg_replace('/(Bearer\s+|api_key=|api-key=)([A-Za-z0-9_\-]{6})[A-Za-z0-9_\-]+/i', '$1$2***', (string)$raw) ?? '';
            $msg = 'RAW پاسخ (ماسک‌شده): ' . substr($masked, 0, 700);
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $msgType = 'err';
    }
}

// ─── مقادیر فعلی برای فرم ───
$i = firstHttpProviderIndex($config);
$api = $i !== null ? ($config['providers'][$i]['options'] ?? []) : null;
$apiKeyMasked = $api ? maskKey((string)($api['auth']['key'] ?? '')) : '';
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
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>طلام — داشبورد</title>
<style>
  body { font-family: Vazirmatn, Tahoma, sans-serif; background: #1E1030; color: #fff; margin: 0; padding: 20px 16px 40px; }
  .wrap { max-width: 460px; margin: 0 auto; }
  h1 { font-size: 18px; text-align: center; }
  h2 { font-size: 15px; margin: 26px 0 10px; display: flex; align-items: center; gap: 8px; }
  h2::after { content: ''; flex: 1; height: 1px; background: rgba(255,255,255,.1); }
  .sub { text-align: center; color: #8d7fa8; font-size: 12px; margin-bottom: 18px; }
  .card { background: #2E1A4A; border: 1px solid rgba(255,255,255,.07); border-radius: 18px; padding: 14px 16px; margin-bottom: 12px; }
  label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 8px; }
  input, textarea, select { width: 100%; box-sizing: border-box; background: #1A0F2E; border: 1px solid rgba(255,255,255,.07); border-radius: 13px; color: #fff; font-family: inherit; font-size: 17px; font-weight: 600; text-align: center; padding: 12px; outline: none; }
  textarea { text-align: right; direction: ltr; font-size: 12px; font-weight: 400; min-height: 64px; font-family: monospace; }
  input:focus, textarea:focus, select:focus { border-color: #D4A843; }
  .row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  button { width: 100%; min-height: 52px; border: none; border-radius: 999px; background: linear-gradient(135deg, #E8C97D, #D4A843); color: #241300; font-family: inherit; font-size: 15px; font-weight: 800; cursor: pointer; }
  button.ghost { background: transparent; border: 1px solid rgba(212,168,67,.5); color: #E8C97D; }
  .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; }
  .msg { border-radius: 12px; padding: 12px 14px; font-size: 12.5px; margin-bottom: 12px; word-break: break-word; }
  .ok { background: rgba(52,211,153,.15); color: #34D399; }
  .err { background: rgba(244,121,107,.15); color: #F4796B; }
  .note { color: #8d7fa8; font-size: 11.5px; line-height: 1.9; margin-top: 12px; }
  a { color: #E8C97D; }
  .chk { display: flex; align-items: center; gap: 8px; font-size: 13px; }
  .chk input { width: auto; }
  .mini { font-size: 11px; color: #8d7fa8; margin: 4px 0 0; text-align: right; }
</style>
</head>
<body>
<div class="wrap">
  <h1>⚡ داشبورد طلام</h1>
  <div class="sub">مدیریت نرخ‌ها و منبع API — بدون رفتن به هاست</div>

  <?php if ($msg !== ''): ?><div class="msg <?= $msgType === 'ok' ? 'ok' : 'err' ?>"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

  <h2>نرخ‌ها</h2>
  <form method="post">
    <input type="hidden" name="token" value="<?= $esc($given) ?>" />
    <input type="hidden" name="action" value="save_rates" />
    <div class="card">
      <label>گرم طلای ۱۸ عیار (تومان) *</label>
      <input name="gold18" inputmode="decimal" value="<?= $esc($current['gold18'] ?? '') ?>" required />
    </div>
    <div class="card">
      <label>مظنه تهران — مثقال ۱۷ عیار (تومان)</label>
      <input name="mazaneh" inputmode="decimal" value="<?= $esc($current['mazaneh'] ?? '') ?>" />
    </div>
    <div class="card">
      <label>سکه‌ها (قیمت بازار، تومان)</label>
      <div class="row">
        <div><label>امامی</label><input name="coins[emami]" inputmode="decimal" value="<?= $esc($current['coins']['emami'] ?? '') ?>" /></div>
        <div><label>بهار</label><input name="coins[bahar]" inputmode="decimal" value="<?= $esc($current['coins']['bahar'] ?? '') ?>" /></div>
        <div><label>نیم</label><input name="coins[nim]" inputmode="decimal" value="<?= $esc($current['coins']['nim'] ?? '') ?>" /></div>
        <div><label>ربع</label><input name="coins[rob]" inputmode="decimal" value="<?= $esc($current['coins']['rob'] ?? '') ?>" /></div>
        <div><label>گرمی</label><input name="coins[gerami]" inputmode="decimal" value="<?= $esc($current['coins']['gerami'] ?? '') ?>" /></div>
      </div>
    </div>
    <button type="submit">ذخیره و انتشار فوری</button>
  </form>

  <h2>تنظیمات API</h2>
  <?php if ($api === null): ?>
    <div class="card note">بلوک ConfigurableHttpProvider در config.php یافت نشد. از config.example.php بخش providers را کپی کن.</div>
  <?php else: ?>
  <form method="post">
    <input type="hidden" name="token" value="<?= $esc($given) ?>" />
    <div class="card">
      <div class="chk">
        <input type="checkbox" name="api_enabled" id="api_enabled" <?= !empty($api['enabled']) ? 'checked' : '' ?> />
        <label for="api_enabled" style="margin:0">منبع فعال باشد</label>
      </div>
      <label style="margin-top:12px">URL</label>
      <input name="api_url" dir="ltr" value="<?= $esc($api['url'] ?? '') ?>" />
      <label style="margin-top:12px">Query (JSON)</label>
      <textarea name="api_query" dir="ltr"><?= $esc(json_encode($api['query'] ?? new stdClass(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?></textarea>
      <label style="margin-top:12px">Headers (JSON)</label>
      <textarea name="api_headers" dir="ltr"><?= $esc(json_encode($api['headers'] ?? new stdClass(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?></textarea>
      <div class="row" style="margin-top:12px">
        <div>
          <label>Auth type</label>
          <select name="api_auth_type">
            <?php foreach (['' => 'بدون', 'query' => 'Query param', 'header' => 'Header', 'bearer' => 'Bearer'] as $k => $lbl): ?>
              <option value="<?= $k ?>" <?= strtolower((string)($api['auth']['type'] ?? '')) === $k ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Auth name</label>
          <input name="api_auth_name" dir="ltr" value="<?= $esc($api['auth']['name'] ?? 'api_key') ?>" />
        </div>
      </div>
      <label style="margin-top:12px">Auth key — فعلی: <?= $esc($apiKeyMasked ?: '—') ?> (خالی = بدون تغییر)</label>
      <input name="api_auth_key" dir="ltr" placeholder="برای تغییر کلید جدید را بگذار" />
      <label style="margin-top:12px">Map (JSON) — مسیر هر فیلد در پاسخ</label>
      <textarea name="api_map" dir="ltr" style="min-height:110px"><?= $esc(json_encode($api['map'] ?? new stdClass(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?></textarea>
      <div class="row" style="margin-top:12px">
        <div><label>min interval (s)</label><input name="api_min_interval" inputmode="numeric" dir="ltr" value="<?= $esc($api['min_interval_seconds'] ?? 240) ?>" /></div>
        <div><label>daily cap</label><input name="api_daily_cap" inputmode="numeric" dir="ltr" value="<?= $esc($api['daily_cap'] ?? 0) ?>" /></div>
      </div>
    </div>
    <div class="row2">
      <button type="submit" name="action" value="test_api" class="ghost">تست اتصال</button>
      <button type="submit" name="action" value="save_api">ذخیره تنظیمات</button>
    </div>
    <div class="row2">
      <button type="submit" name="action" value="test_api_raw" class="ghost">نمایش RAW پاسخ</button>
    </div>
    <p class="mini">«نمایش RAW» خروجی خام منبع را ماسک‌شده نشان می‌دهد تا مسیرهای Map را با آن تنظیم کنی.</p>
  </form>
  <?php endif; ?>

  <p class="note">
    خروجی عمومی: <a href="public/prices.json?t=<?= time() ?>">public/prices.json</a>
    — اپ‌ها حداکثر ۵ دقیقه بعد (یا با بستن و باز کردن) می‌گیرند.
  </p>
</div>
</body>
</html>
