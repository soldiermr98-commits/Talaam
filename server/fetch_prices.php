<?php
/**
 * طلام — نقطه ورود cron برای تازه‌سازی نرخ‌ها
 *
 * CLI:      php fetch_prices.php
 * وب‌کرون:  https://…/server/fetch_prices.php?token=YOUR_TOKEN   (توکن در config.php — خالی = غیرفعال)
 *
 * Exit code: 0=ok  2=stale  3=error  4=locked
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

$serverDir = __DIR__;

require $serverDir . '/providers/ProviderInterface.php';
require $serverDir . '/providers/BaseProvider.php';
require $serverDir . '/providers/TemplateProvider.php';
require $serverDir . '/providers/NavasanStyleProvider.php';
require $serverDir . '/providers/BrsapiStyleProvider.php';
require $serverDir . '/providers/ManualProvider.php';
require $serverDir . '/src/PricePipeline.php';

// ─── config (git-ignored) ───
$configFile = $serverDir . '/config.php';
if (!file_exists($configFile)) {
    $msg = "config.php not found — copy config.example.php to config.php and fill values\n";
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg);
    } else {
        http_response_code(500);
        echo 'server not configured';
    }
    exit(3);
}
/** @var array<string,mixed> $config */
$config = require $configFile;

// ─── حالت HTTP (وب‌کرون) — توکن اجباری، مقایسه timing-safe ───
if (PHP_SAPI !== 'cli') {
    $token = (string)($config['http_trigger_token'] ?? '');
    $given = (string)($_GET['token'] ?? '');
    if ($token === '' || !hash_equals($token, $given)) {
        http_response_code(403);
        echo "forbidden\n";
        exit(3);
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// ─── ساخت providers از config (ترتیب = اولویت) ───
$providers = [];
foreach (($config['providers'] ?? []) as $p) {
    $class = (string)($p['class'] ?? '');
    if ($class === '' || !class_exists($class)) {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "unknown provider class: $class — skipped\n");
        }
        continue;
    }
    $providers[] = new $class(is_array($p['options'] ?? null) ? $p['options'] : [], is_array($config['http'] ?? null) ? $config['http'] : []);
}

$pipeline = new PricePipeline($config, $providers);
$result = $pipeline->run();

echo json_encode(['status' => $result['status'], 'published' => $result['published']], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;

exit(['ok' => 0, 'stale' => 2, 'locked' => 4, 'error' => 3][$result['status']] ?? 3);
