<?php
/**
 * طلام — ویرایشگر اضطراری نرخ از مرورگر/گوشی (بدون نیاز به API خارجی)
 *
 * فعال‌سازی: در config.php مقدار 'admin_token' را یک رشته تصادفی بگذار (خالی = خاموش)
 * آدرس:      https://YOUR-HOST/server/admin.php?token=YOUR_TOKEN
 *
 * کار: نرخ را می‌گیری → در data/override.json ذخیره می‌کند (با انقضای ۲۴ ساعته) →
 * pipeline را اجرا می‌کند → public/prices.json همان لحظه آپدیت می‌شود.
 * امنیت: توکن timing-safe؛ هیچ secret در خروجی؛ توصیه: این فایل را بعد از هر استفاده در
 * مرورگر خصوصی باز کن و دایرکتوری را به کسی نده.
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
    exit('server not configured');
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
$lastGood = is_array(json_decode((string)@file_get_contents((string)($paths['last_good'] ?? '')), true)) ? json_decode((string)file_get_contents((string)($paths['last_good'] ?? '')), true) : null;

// ─── ذخیره (POST) ───
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $gold = $_POST['gold18'] ?? '';
    $mazaneh = $_POST['mazaneh'] ?? '';
    $coinsIn = is_array($_POST['coins'] ?? null) ? $_POST['coins'] : [];
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
    $gold18 = $clean($gold);
    if ($gold18 === null || $gold18 <= 0) {
        $msg = 'نرخ گرم ۱۸ عیار نامعتبر است';
        $msgType = 'err';
    } else {
        $coins = ['emami' => 0, 'bahar' => 0, 'nim' => 0, 'rob' => 0, 'gerami' => 0];
        foreach ($coins as $k => $v) {
            $c = $clean($coinsIn[$k] ?? '');
            $coins[$k] = $c !== null && $c > 0 ? $c : 0;
        }
        $mazaneh = $clean($mazaneh);
        $payload = [
            'expires_at' => date('c', time() + 86400), // اعتبار ۲۴ ساعت
            'gold18' => $gold18,
            'mazaneh' => $mazaneh !== null && $mazaneh > 0 ? $mazaneh : (int)round($gold18 * 4.3318),
            'coins' => $coins,
        ];
        $tmp = $overridePath . '.tmp.' . getmypid();
        @mkdir(dirname($overridePath), 0775, true);
        if (file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) === false || !rename($tmp, $overridePath)) {
            $msg = 'نوشتن فایل override ناموفق بود (دسترسی پوشه data را چک کن)';
            $msgType = 'err';
        } else {
            // اجرای خط تولید برای انتشار فوری
            $providers = ProviderFactory::createAll($config);
            $pipeline = new PricePipeline($config, $providers);
            $result = $pipeline->run();
            $msg = $result['status'] === 'ok'
                ? 'ذخیره و منتشر شد ✓ (source: ' . ($result['published']['source'] ?? '?') . ')'
                : 'ذخیره شد اما انتشار: ' . $result['status'];
            $msgType = 'ok';
            $lastGood = $result['published'] ?? $lastGood;
        }
    }
}

// ─── مقادیر فرم از override یا last good ───
$current = null;
if (file_exists($overridePath)) {
    $o = json_decode((string)file_get_contents($overridePath), true);
    if (is_array($o) && (!isset($o['expires_at']) || strtotime((string)$o['expires_at']) > time())) {
        $current = $o;
    }
}
if ($current === null && is_array($lastGood)) {
    $current = [
        'gold18' => $lastGood['gold18'] ?? '',
        'mazaneh' => $lastGood['mazaneh'] ?? '',
        'coins' => $lastGood['coins'] ?? [],
    ];
}
$esc = static fn ($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>طلام — ویرایش نرخ</title>
<style>
  body { font-family: Vazirmatn, Tahoma, sans-serif; background: #1E1030; color: #fff; margin: 0; padding: 20px 16px 40px; }
  .wrap { max-width: 430px; margin: 0 auto; }
  h1 { font-size: 18px; text-align: center; }
  .sub { text-align: center; color: #8d7fa8; font-size: 12px; margin-bottom: 18px; }
  .card { background: #2E1A4A; border: 1px solid rgba(255,255,255,.07); border-radius: 18px; padding: 14px 16px; margin-bottom: 12px; }
  label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 8px; }
  input { width: 100%; box-sizing: border-box; background: #1A0F2E; border: 1px solid rgba(255,255,255,.07); border-radius: 13px; color: #fff; font-family: inherit; font-size: 20px; font-weight: 700; text-align: center; padding: 12px; outline: none; }
  input:focus { border-color: #D4A843; }
  .row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  button { width: 100%; min-height: 52px; border: none; border-radius: 999px; background: linear-gradient(135deg, #E8C97D, #D4A843); color: #241300; font-family: inherit; font-size: 15px; font-weight: 800; cursor: pointer; }
  .msg { border-radius: 12px; padding: 12px 14px; font-size: 13px; margin-bottom: 12px; }
  .ok { background: rgba(52,211,153,.15); color: #34D399; }
  .err { background: rgba(244,121,107,.15); color: #F4796B; }
  .note { color: #8d7fa8; font-size: 11.5px; line-height: 1.9; margin-top: 14px; }
  a { color: #E8C97D; }
</style>
</head>
<body>
<div class="wrap">
  <h1>🔧 ویرایش نرخ — طلام</h1>
  <div class="sub">این نرخ تا ۲۴ ساعت اعتبار دارد؛ بعد از آن اپ به منبع/نرخ دستی کاربر برمی‌گردد</div>

  <?php if ($msg !== ''): ?><div class="msg <?= $msgType === 'ok' ? 'ok' : 'err' ?>"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

  <form method="post">
    <input type="hidden" name="token" value="<?= $esc($given) ?>" />
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

  <p class="note">
    بعد از ذخیره، اپ‌ها در fetch بعدی (حداکثر ۵ دقیقه) این نرخ‌ها را می‌گیرند؛
    یا اپ را ببند و باز کن تا فوری بیاید.
    <br />فایل منتشرشده: <a href="public/prices.json?t=<?= time() ?>">public/prices.json</a>
    <br />«فیلد خالی = صفر» یعنی اپ آن سکه را «دستی وارد کن» نشان می‌دهد.
  </p>
</div>
</body>
</html>
