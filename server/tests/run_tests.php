<?php
/**
 * تست‌های pipeline نرخ — بدون وابستگی به PHPUnit (هارنس ساده CLI)
 * اجرا: php tests/run_tests.php
 * Exit: 0 = همه پاس، 1 = شکست
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$serverDir = dirname(__DIR__);
require $serverDir . '/providers/ProviderInterface.php';
require $serverDir . '/providers/BaseProvider.php';
require $serverDir . '/providers/TemplateProvider.php';
require $serverDir . '/providers/NavasanStyleProvider.php';
require $serverDir . '/providers/BrsapiStyleProvider.php';
require $serverDir . '/providers/ManualProvider.php';
require $serverDir . '/src/PricePipeline.php';

// ─── هارنس ───
$GLOBALS['t'] = ['pass' => 0, 'fail' => 0, 'failures' => []];
function t(string $name, callable $fn): void
{
    try {
        $fn();
        $GLOBALS['t']['pass']++;
        echo "  ok  $name\n";
    } catch (Throwable $e) {
        $GLOBALS['t']['fail']++;
        $GLOBALS['t']['failures'][] = $name . ' — ' . $e->getMessage();
        echo "FAIL  $name — " . $e->getMessage() . "\n";
    }
}
function assertSame2($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new Exception($msg . ' expected=' . var_export($expected, true) . ' got=' . var_export($actual, true));
    }
}
function assertTrue2(bool $cond, string $msg = ''): void
{
    if (!$cond) {
        throw new Exception($msg);
    }
}

// ─── فیکسچرها ───
class FakeProvider implements ProviderInterface
{
    /** @var string */
    private $id;
    /** @var mixed */
    private $result;

    public function __construct(string $id, $result)
    {
        $this->id = $id;
        $this->result = $result;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function fetch(): array
    {
        if ($this->result instanceof Throwable) {
            throw $this->result;
        }
        return $this->result;
    }
}

function validData(float $gold18 = 8000000.0, ?float $mazaneh = null, array $coins = []): array
{
    $m = $mazaneh ?? $gold18 * 4.3318;
    return [
        'gold18' => $gold18,
        'mazaneh' => (int)round($m),
        'coins' => array_merge(['emami' => 0, 'bahar' => 0, 'nim' => 0, 'rob' => 0, 'gerami' => 0], $coins),
    ];
}

function makeConfig(string $dir, array $overrides = []): array
{
    $base = [
        'timezone' => 'Asia/Tehran',
        'providers' => [],
        'thresholds' => [
            'max_jump_pct' => 5.0,
            'jump_confirm_runs' => 3,
            'mazaneh_ratio' => 4.3318,
            'mazaneh_tolerance' => 0.5,
            'gold18_min' => 100000,
            'gold18_max' => 2000000000,
            'coin_max_gold18_ratio' => 100,
        ],
        'http' => ['timeout_seconds' => 5, 'retries' => 2, 'retry_backoff_ms' => 1],
        'cron' => [
            'lock_path' => $dir . '/lock',
            'log_path' => $dir . '/logs/app.log',
            'log_max_bytes' => 524288,
            'log_keep' => 3,
            'rejected_log' => $dir . '/logs/rejected.log',
            'alert_webhook_url' => '',
            'alert_after_minutes' => 15,
        ],
        'paths' => [
            'last_good' => $dir . '/last_good.json',
            'publish' => $dir . '/publish/prices.json',
            'state' => $dir . '/state.json',
            'override' => $dir . '/override.json',
        ],
    ];
    foreach ($overrides as $k => $v) {
        $base[$k] = is_array($v) && isset($base[$k]) && is_array($base[$k]) ? array_merge($base[$k], $v) : $v;
    }
    return $base;
}

function tmpDir(): string
{
    $d = sys_get_temp_dir() . '/talam-test-' . uniqid();
    if (!is_dir($d)) {
        mkdir($d, 0777, true);
    }
    return $d;
}

function deleteDir(string $d): void
{
    if (!is_dir($d)) {
        return;
    }
    foreach (array_diff(scandir($d), ['.', '..']) as $f) {
        $p = $d . '/' . $f;
        is_dir($p) ? deleteDir($p) : @unlink($p);
    }
    @rmdir($d);
}

function readJson(string $path): array
{
    return json_decode((string)file_get_contents($path), true);
}

// ─── تست‌ها ───
echo "== talam price pipeline tests (PHP " . PHP_VERSION . ") ==\n";

// ۱) آپدیت نرمال
t('normal update: اولین اجرا منتشر می‌شود با stale=false و source درست', function () {
    $dir = tmpDir();
    $cfg = makeConfig($dir);
    $p = new PricePipeline($cfg, [new FakeProvider('fake1', validData(8000000))]);
    $r = $p->run();
    assertSame2('ok', $r['status']);
    $pub = readJson($cfg['paths']['publish']);
    assertSame2(false, $pub['stale']);
    assertSame2('fake1', $pub['source']);
    assertSame2(8000000, $pub['gold18']);
    assertSame2('integer', gettype($pub['gold18']));
    assertTrue2((bool)preg_match('/\+03:30$/', $pub['updated_at']), 'updated_at must be ISO8601 +03:30: ' . $pub['updated_at']);
    assertSame2($pub['updated_at'], readJson($cfg['paths']['last_good'])['updated_at']);
    assertSame2([], array_filter(glob($dir . '/**/*.tmp.*'), 'is_string'), 'no temp files left');
    deleteDir($dir);
});

// ۲) تایم‌اوت منبع اول → fallback به منبع دوم
t('provider timeout then fallback به منبع دوم', function () {
    $dir = tmpDir();
    $cfg = makeConfig($dir);
    $p = new PricePipeline($cfg, [
        new FakeProvider('down', new RuntimeException('HTTP timeout after retries')),
        new FakeProvider('backup', validData(8100000)),
    ]);
    $r = $p->run();
    assertSame2('ok', $r['status']);
    assertSame2('backup', $r['published']['source']);
    assertSame2(8100000, $r['published']['gold18']);
    deleteDir($dir);
});

// ۳) مقادیر نامعتبر رد می‌شوند
t('invalid values رد و منبع بعدی استفاده می‌شود', function () {
    $dir = tmpDir();
    $cfg = makeConfig($dir);
    $bad1 = ['gold18' => 0, 'mazaneh' => 0, 'coins' => []];
    $bad2 = ['gold18' => 'abc', 'mazaneh' => 0, 'coins' => []];
    $p = new PricePipeline($cfg, [
        new FakeProvider('bad1', $bad1),
        new FakeProvider('bad2', $bad2),
        new FakeProvider('good', validData(8000000)),
    ]);
    $r = $p->run();
    assertSame2('ok', $r['status']);
    assertSame2('good', $r['published']['source']);
    assertTrue2(file_exists($cfg['cron']['rejected_log']), 'rejected log must exist');
    deleteDir($dir);
});

// ۴) خطای ۱۰x واحد (ریال به‌جای تومان) → رد؛ تبدیل درست ریتال هم تست می‌شود
t('10x unit error (rial instead of toman) رد می‌شود و stale سرو می‌شود', function () {
    $dir = tmpDir();
    $cfg = makeConfig($dir);
    $good = new FakeProvider('good', validData(8000000));
    $p1 = new PricePipeline($cfg, [$good]);
    $first = $p1->run();
    assertSame2('ok', $first['status']);

    // منبع مقدار را ریال گزارش کرده و ۱۰x ارسال شده (۸۰,۰۰۰,۰۰۰ به‌جای ۸٬۰۰۰٬۰۰۰)
    $tenX = new FakeProvider('tenx', ['gold18' => 80000000.0, 'mazaneh' => 346544000.0, 'coins' => []]);
    $p2 = new PricePipeline($cfg, [$tenX]);
    $r = $p2->run();
    assertSame2('stale', $r['status']);
    assertSame2(true, $r['published']['stale']);
    assertSame2($first['published']['updated_at'], $r['published']['updated_at'], 'stale must keep original updated_at');
    assertSame2(8000000, readJson($cfg['paths']['last_good'])['gold18'], 'last_good must not change');

    // تبدیل درست ریتال → تومان در BaseProvider
    $ref = new ReflectionMethod('BaseProvider', 'toToman');
    $ref->setAccessible(true);
    $stub = new class ([] , []) extends BaseProvider {
        public function id(): string { return 'x'; }
        public function fetch(): array { return []; }
    };
    assertSame2(8000000, $ref->invoke($stub, 80000000, 'rial'));
    assertSame2(8000000, $ref->invoke($stub, 8000000, 'toman'));
    deleteDir($dir);
});

// ۵) پرش ناگهانی ۲۰٪ → رد در ۲ اجر اول، پذیرش در اجر سوم (N اجر متوالی)
t('sudden 20% jump رد میشود؛ پس از N اجر متوالی پذیرفته میشود', function () {
    $dir = tmpDir();
    $cfg = makeConfig($dir);
    // seed: اول last_good = ۸ میلیون
    $seed = new PricePipeline($cfg, [new FakeProvider('good', validData(8000000))]);
    assertSame2('ok', $seed->run()['status']);

    $p = new PricePipeline($cfg, [new FakeProvider('jumper', validData(9600000))]);
    $r1 = $p->run();
    assertSame2('stale', $r1['status'], 'run1: 20% jump must be rejected');

    $r2 = $p->run();
    assertSame2('stale', $r2['status'], 'run2: still below confirm runs');

    $r3 = $p->run();
    assertSame2('ok', $r3['status'], 'run3: accepted after 3 consecutive runs');
    assertSame2(9600000, $r3['published']['gold18']);
    deleteDir($dir);
});

// ۶) تأیید با منبع دوم در همان سیکل
t('20% jump با تأیید منبع دوم بلافاصله پذیرفته می‌شود', function () {
    $dir = tmpDir();
    $cfg = makeConfig($dir);
    $p = new PricePipeline($cfg, [
        new FakeProvider('a', validData(9600000)),
        new FakeProvider('b', validData(9600000)),
    ]);
    $r = $p->run();
    assertSame2('ok', $r['status']);
    assertSame2('a', $r['published']['source']);
    deleteDir($dir);
});

// ۷) همه منابع خراب → stale با updated_at اصلی
t('all providers down → stale با updated_at اصلی و بدون ساخت داده تازه', function () {
    $dir = tmpDir();
    $cfg = makeConfig($dir);
    $okP = new PricePipeline($cfg, [new FakeProvider('good', validData(8000000))]);
    $first = $okP->run();
    assertSame2('ok', $first['status']);

    $down = new PricePipeline($cfg, [
        new FakeProvider('d1', new RuntimeException('down1')),
        new FakeProvider('d2', new RuntimeException('down2')),
    ]);
    $r = $down->run();
    assertSame2('stale', $r['status']);
    assertSame2(true, $r['published']['stale']);
    assertSame2($first['published']['updated_at'], $r['published']['updated_at']);
    assertSame2(8000000, $r['published']['gold18']);
    assertSame2(false, readJson($cfg['paths']['last_good'])['stale'], 'last_good خودش stale نمیشود');
    deleteDir($dir);
});

// ۸) همه خراب + last_good موجود نیست → error و هیچ فایلی منتشر نمی‌شود
t('all down + no last good → error, هیچ فایل mock/ساختگی منتشر نمیشود', function () {
    $dir = tmpDir();
    $cfg = makeConfig($dir);
    $p = new PricePipeline($cfg, [new FakeProvider('d', new RuntimeException('down'))]);
    $r = $p->run();
    assertSame2('error', $r['status']);
    assertSame2(null, $r['published']);
    assertSame2(false, file_exists($cfg['paths']['publish']), 'no published file');
    assertSame2(false, file_exists($cfg['paths']['last_good']), 'no last_good file');
    deleteDir($dir);
});

// ۹) حفاظت فایل نیمه‌نوشته (اتمیک) + لاگ ردشدگی
t('atomic write: بدون فایل tmp باقی‌مانده و فایل مقصد سالم', function () {
    $dir = tmpDir();
    $cfg = makeConfig($dir);
    $p = new PricePipeline($cfg, [new FakeProvider('good', validData(8000000))]);
    $p->run();
    $tmps = array_merge(
        glob($dir . '/*.tmp.*') ?: [],
        glob($dir . '/publish/*.tmp.*') ?: []
    );
    assertSame2([], $tmps, 'no tmp leftovers');
    assertTrue2(json_decode((string)file_get_contents($cfg['paths']['publish']), true) !== null, 'published JSON valid');
    deleteDir($dir);
});

// ۱۰) لاک: اجرای همزمان دوم اجرا نمی‌شود
t('lock: run دوم در حالت قفل، publish نمیکند', function () {
    $dir = tmpDir();
    $cfg = makeConfig($dir);
    $good = new FakeProvider('good', validData(8000000));
    $p = new PricePipeline($cfg, [$good]);
    $p->run(); // داده اولیه

    // قفل خارجی بگیر
    $lockFp = fopen($cfg['cron']['lock_path'], 'c');
    flock($lockFp, LOCK_EX);

    $jumped = new PricePipeline($cfg, [new FakeProvider('jumper', validData(9600000))]);
    $r = $jumped->run();
    assertSame2('locked', $r['status']);
    assertSame2(8000000, readJson($cfg['paths']['publish'])['gold18'], 'publish untouched while locked');

    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    // پس از آزادسازی، گارد پرش فعال است: پرش ۲۰٪ در اجرهای ۱ و ۲ رد و در اجر ۳ (متوالی) پذیرفته میشود
    $r2 = $jumped->run();
    assertSame2('stale', $r2['status'], 'after release: jump rejected (consecutive 1/3)');
    $r3 = $jumped->run();
    assertSame2('stale', $r3['status'], 'consecutive 2/3');
    $r4 = $jumped->run();
    assertSame2('ok', $r4['status'], 'consecutive 3/3 → accepted');
    deleteDir($dir);
});

// ۱۱) override دستی با انقضا
t('manual override با expires_at → منتشر میشود؛ منقضی → نادیده', function () {
    $dir = tmpDir();
    $cfg = makeConfig($dir);
    $cfg['paths']['override'] = $dir . '/override.json';

    file_put_contents($cfg['paths']['override'], json_encode([
        'expires_at' => date('c', time() + 3600),
        'gold18' => 7777777,
        'coins' => ['emami' => 88000000],
    ]));
    $p = new PricePipeline($cfg, [new ManualProvider(['path' => $cfg['paths']['override']])]);
    $r = $p->run();
    assertSame2('ok', $r['status']);
    assertSame2('manual', $r['published']['source']);
    assertSame2(7777777, $r['published']['gold18']);
    assertSame2(88000000, $r['published']['coins']['emami']);
    assertSame2(0, $r['published']['coins']['nim']);

    // منقضی کن
    file_put_contents($cfg['paths']['override'], json_encode([
        'expires_at' => date('c', time() - 60),
        'gold18' => 7777777,
    ]));
    // منبع throw میشود، ولی last_good از انتشار قبلی موجود است → stale با همان داده
    $r2 = $p->run();
    assertSame2('stale', $r2['status'], 'expired override → serve last good as stale');
    assertSame2(true, $r2['published']['stale']);
    assertSame2(7777777, $r2['published']['gold18'], 'no fabricated data — last good preserved');
    deleteDir($dir);
});

// ۱۲) نسبت مظنه خارج از تلورانس → رد
t('mazaneh/gold18 ratio خارج از تلورانس رد میشود', function () {
    $dir = tmpDir();
    $cfg = makeConfig($dir);
    $p = new PricePipeline($cfg, [new FakeProvider('bad-ratio', ['gold18' => 8000000.0, 'mazaneh' => 40000000.0, 'coins' => []])]);
    $r = $p->run();
    assertSame2('error', $r['status'], 'ratio 5.0 vs 4.33±0.5 → rejected, no last good → error');
    deleteDir($dir);
});

// ۱۳) sanity ترتیب سکه‌ها
t('ordering sanity: emami < nim رد میشود', function () {
    $dir = tmpDir();
    $cfg = makeConfig($dir);
    $p = new PricePipeline($cfg, [new FakeProvider('bad-order', validData(8000000, null, ['emami' => 40000000, 'nim' => 50000000]))]);
    $r = $p->run();
    assertSame2('error', $r['status']);
    deleteDir($dir);
});

// ۱۴) هشدار همه-منابع-خراب
t('alert: بعد از همه خرابی‌ها هشدار در لاگ می‌افتد (webhook خالی)', function () {
    $dir = tmpDir();
    $cfg = makeConfig($dir, ['cron' => ['alert_after_minutes' => 0]]);
    $p = new PricePipeline($cfg, [new FakeProvider('d', new RuntimeException('down'))]);
    $p->run();
    $p->run(); // دفعه دوم: all_down_since موجود → alert
    $log = (string)file_get_contents($cfg['cron']['log_path']);
    assertTrue2(strpos($log, 'ALERT (no webhook configured)') !== false, 'alert must be logged');
    deleteDir($dir);
});

// ۱۵) استاب‌های UNVERIFIED بدون config → RuntimeException (نه سکوت)
t('unverified stubs بدون پیکربندی throw میکنند (نه داده جعلی)', function () {
    foreach (['NavasanStyleProvider', 'BrsapiStyleProvider'] as $cls) {
        $stub = new $cls(['endpoint' => 'TODO-verify', 'api_key' => 'REPLACE_ME']);
        try {
            $stub->fetch();
            throw new Exception("$cls must throw before fetching");
        } catch (RuntimeException $e) {
            assertTrue2(strpos($e->getMessage(), "not configured") !== false || strpos($e->getMessage(), 'TODO') !== false, "$cls threw: " . $e->getMessage());
        }
    }
    deleteDir(tmpDir());
});

// ─── جمع‌بندی ───
echo "\n== RESULT: {$GLOBALS['t']['pass']} passed, {$GLOBALS['t']['fail']} failed ==\n";
foreach ($GLOBALS['t']['failures'] as $f) {
    echo "  FAILED: $f\n";
}
exit($GLOBALS['t']['fail'] > 0 ? 1 : 0);
