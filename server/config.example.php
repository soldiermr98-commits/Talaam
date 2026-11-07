<?php
/**
 * نمونه پیکربندی — این فایل را به «config.php» کپی کن (config.php در .gitignore است)
 * و مقادیر placeholder را پر کن. هرگز config.php را کامیت نکن و کلیدها را در چت/ریپو نفرست.
 *
 * افزودن/تعویض منبع = فقط ویرایش همین فایل؛ هیچ تغییری در کد یا اپ لازم نیست.
 */
return [
    'timezone' => 'Asia/Tehran',

    /*
     * ترتیب منابع = اولویت merge جزئی: هر فیلد از اولین منبعِ معتبری که آن را بدهد پر میشود؛
     * منابع بعدی فقط فیلدهای خالی را پر میکنند و به‌عنوان تأیید متقاطع jump-guard هم کار میکنند.
     *
     * دو نوع آداپتور آماده:
     *   ConfigurableHttpProvider — کاملاً کانفیگ‌محور (بخش «افزودن منبع در ۵ دقیقه» در README_SERVER.md)
     *   ManualProvider          — فایل override.json دستی با انقضا (همیشه به‌عنوان آخرین fallback نگه داشته شود)
     *
     * استاب‌های قدیمی (NavasanStyleProvider / BrsapiStyleProvider / TemplateProvider) نمونه‌ی
     * UNVERIFIED هستند — به‌صورت کامنت زیر نگه داشته شدهاند تا بعد از تأیید مستندات رسمی فعال شوند.
     */
    'providers' => [

        // ── نمونه ۱: منبع HTTP کاملاً کانفیگ‌محور (نام و آدرس عمداً ساختگی است) ──
        [
            'class' => 'ConfigurableHttpProvider',
            'options' => [
                'id' => 'example-provider',
                'enabled' => false, // تا وقتی endpoint/کلید واقعی تأیید نشده false بماند
                'url' => 'https://api.example.invalid/v1/prices',
                'method' => 'GET',
                'query' => ['format' => 'json', 'lang' => 'fa'],
                'headers' => ['Accept' => 'application/json'],
                'auth' => [
                    'type' => 'query',       // query | header | bearer
                    'name' => 'api_key',
                    'key_env' => 'TALAM_EXAMPLE_KEY', // کلید فقط از env خوانده میشود
                ],
                'map' => [
                    'gold18' => ['path' => 'data.gold.18k.price', 'unit' => 'toman'],
                    'mazaneh' => ['path' => 'data.gold.mesghal', 'unit' => 'toman'],
                    'coins.emami' => ['path' => 'data.coins.emami', 'unit' => 'toman'],
                    'coins.bahar' => ['path' => 'data.coins.bahar', 'unit' => 'toman'],
                    'coins.nim' => ['path' => 'data.coins.nim', 'unit' => 'toman'],
                    'coins.rob' => ['path' => 'data.coins.rob', 'unit' => 'toman'],
                    'coins.gerami' => ['path' => 'data.coins.gerami', 'unit' => 'toman'],
                ],
                'max_age_seconds' => 900,       // داده کش این منبع حداکثر ۱۵ دقیقه معتبر است
                'min_interval_seconds' => 240,  // حداکثر هر ۴ دقیقه واقعا صدا بزن (cron هر ۵ دقیقه)
                'daily_cap' => 200,             // سقف فراخوانی روزانه؛ نزدیک سقف فاصله ۲ برابر
            ],
        ],

        // ── نمونه ۲: منبع دوم برای پر کردن فیلدهای خالی + تأیید متقاطع jump-guard ──
        [
            'class' => 'ConfigurableHttpProvider',
            'options' => [
                'id' => 'example-provider-2',
                'enabled' => false,
                'url' => 'https://api2.example.invalid/gold',
                'auth' => ['type' => 'bearer', 'key_env' => 'TALAM_EXAMPLE2_KEY'],
                'map' => [
                    'gold18' => ['path' => 'result.18k', 'unit' => 'rial'], // ریال → تومان (÷۱۰)
                    'coins.emami' => ['path' => 'result.coins.emami', 'unit' => 'rial', 'multiplier' => 1],
                ],
                'max_age_seconds' => 900,
                'min_interval_seconds' => 300,
                'daily_cap' => 100,
            ],
        ],

        // ── آخرین fallback: override دستی اضطراری (همیشه آخر بماند) ──
        ['class' => 'ManualProvider', 'options' => ['path' => __DIR__ . '/data/override.json']],

        /*
        // ── استاب‌های UNVERIFIED — بعد از تأیید مستندات رسمی سرویس، فعال شوند ──
        ['class' => 'NavasanStyleProvider', 'options' => ['endpoint' => 'TODO-verify-official-docs', 'api_key' => 'REPLACE_ME', 'unit' => 'toman']],
        ['class' => 'BrsapiStyleProvider', 'options' => ['endpoint' => 'TODO-verify-official-docs', 'api_key' => 'REPLACE_ME', 'unit' => 'toman']],
        */
    ],

    'thresholds' => [
        'max_jump_pct' => 5.0,        // حداکثر تغیر مجاز نسبت به last_good در یک سیکل (٪)
        'jump_confirm_runs' => 3,     // یا N اجر متوالی همسان (±۱٪) → پذیرش پرش
        'mazaneh_ratio' => 4.3318,    // نسبت مورد انتظار mazaneh/gold18
        'mazaneh_tolerance' => 0.5,   // تلورانس نسبت (±)
        'gold18_min' => 100000,       // مرز منطقی پایین (تومان بر گرم)
        'gold18_max' => 2000000000,   // مرز منطقی بالا
        'coin_max_gold18_ratio' => 100, // هر سکه حداکثر این ضریب از گرم ۱۸
    ],

    'http' => [
        'timeout_seconds' => 5,       // تایم‌اوت هر درخواست به منبع
        'retries' => 2,               // تعداد retry داخل یک اجرا
        'retry_backoff_ms' => 400,    // backoff افزایشی بین retryها
    ],

    'cron' => [
        'lock_path' => __DIR__ . '/data/lock',
        'log_path' => __DIR__ . '/data/logs/app.log',
        'log_max_bytes' => 524288,    // چرخش لاگ
        'log_keep' => 3,
        'rejected_log' => __DIR__ . '/data/logs/rejected.log',
        // هشدار وقتی همه منابع بیش از N دقیقه خراباند — قالب تلگرام (UNVERIFIED)؛ خالی = خاموش
        'alert_webhook_url' => '',
        'alert_after_minutes' => 15,
    ],

    // توکن برای اجرای HTTP (وبکرون): fetch_prices.php?token=… — خالی = فقط CLI
    'http_trigger_token' => '',
    // توکن ویرایشگر اضطراری نرخ از گوشی: admin.php?token=… — خالی = خاموش
    'admin_token' => '',

    'paths' => [
        'last_good' => __DIR__ . '/data/last_good.json',
        'publish' => __DIR__ . '/public/prices.json',
        'state' => __DIR__ . '/data/state.json',
        'override' => __DIR__ . '/data/override.json',
    ],
];
