<?php
/**
 * طلام — نقطه ورود cron برای تازهسازی نرخها
 *
 * CLI:      php fetch_prices.php
 * dry-run:  php fetch_prices.php --dry-run     (fetch+validate بدون انتشار)
 * diagnose: php fetch_prices.php --diagnose    (سلامت هر منبع، بدون secret)
 * وبکرون:   https://…/server/fetch_prices.php?token=YOUR_TOKEN
 *
 * Exit code: 0=ok  2=stale  3=error  4=locked
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
require $serverDir . '/src/PricePipeline.php';
require $serverDir . '/src/ProviderFactory.php';

// ─── config: اختیاری — بدون آن هم با پیشفرض markaznerkh کار میکند ───
$configFile = $serverDir . '/config.php';
if (file_exists($configFile)) {
    /** @var array<string,mixed> $config */
    $config = require $configFile;
} else {
    $config = DefaultConfig::get($serverDir);
}

// ─── آرگومان‌های CLI ───
$dryRun = in_array('--dry-run', $argv ?? [], true);
$diagnose = in_array('--diagnose', $argv ?? [], true);

// ─── حالت HTTP (وبکرون) — توکن اجباری، مقایسه timing-safe ───
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

// ─── ساخت providers از config (registry) — config بد فوراً و با پیام واضح خطا میدهد ───
try {
    $providers = ProviderFactory::createAll($config);
} catch (Throwable $e) {
    $msg = 'provider config error: ' . $e->getMessage() . "\n";
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg);
    } else {
        http_response_code(500);
        echo $msg;
    }
    exit(3);
}

$pipeline = new PricePipeline($config, $providers);
if ($dryRun) {
    $pipeline->setDryRun(true);
}

if ($diagnose) {
    echo json_encode($pipeline->diagnose(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
    exit(0);
}

$result = $pipeline->run();

echo json_encode(['status' => $result['status'], 'published' => $result['published']], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;

exit(['ok' => 0, 'stale' => 2, 'locked' => 4, 'error' => 3][$result['status']] ?? 3);
