<?php
/**
 * نمونه پیکربندی — این فایل را به «config.php» کپی کن (config.php در .gitignore است)
 * و مقادیر placeholder را پر کن. هرگز config.php را کامیت نکن.
 */
return [
    // منطقه زمانی برای updated_at خروجی (ISO8601 با +03:30)
    'timezone' => 'Asia/Tehran',

    // ترتیب امتحان منابع — اولین منبعِ معتبر منتشر می‌شود
    // استاب‌های Navasan/Brsapi تا تکمیل endpoint/کلید، همیشه RuntimeException می‌دهند
    'providers' => [
        ['class' => 'NavasanStyleProvider', 'options' => ['endpoint' => 'TODO-verify-official-docs', 'api_key' => 'REPLACE_ME', 'unit' => 'toman']],
        ['class' => 'BrsapiStyleProvider', 'options' => ['endpoint' => 'TODO-verify-official-docs', 'api_key' => 'REPLACE_ME', 'unit' => 'toman']],
        ['class' => 'ManualProvider', 'options' => ['path' => __DIR__ . '/data/override.json']],
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
        // هشدار وقتی همه منابع بیش از N دقیقه خراب‌اند — قالب تلگرام (UNVERIFIED)؛ خالی = خاموش
        'alert_webhook_url' => '',
        'alert_after_minutes' => 15,
    ],

    // توکن برای اجرای HTTP (وب‌کرون): fetch_prices.php?token=… — خالی = فقط CLI
    'http_trigger_token' => '',

    'paths' => [
        'last_good' => __DIR__ . '/data/last_good.json',
        'publish' => __DIR__ . '/public/prices.json',
        'state' => __DIR__ . '/data/state.json',
        'override' => __DIR__ . '/data/override.json',
    ],
];
