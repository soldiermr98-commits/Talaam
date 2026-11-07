<?php
/**
 * نمونه PHP برای سرو کردن prices.json
 * روی هاست خودت بگذار و آدرسش را در src/config به PRICES_URL بده.
 * TODO: منطق دریافت نرخ از منبع واقعی (API اتحادیه/سایت‌های نرخ) را اینجا پیاده کن.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=300');

// TODO: پر کردن مقادیر از API/DB — مثال:
// $src = json_decode(file_get_contents('https://YOUR-SOURCE/api/gold'), true);

$prices = [
    'updated_at' => date('c'), // ISO 8601 با منطقه زمانی سرور
    'gold18'  => 0,   // تومان بر گرم ۱۸ عیار
    'mazaneh' => 0,   // تومان بر مثقال ۱۷ عیار تهران
    'coins' => [
        'emami'  => 0, // سکه امامی — بازار
        'bahar'  => 0, // بهار آزادی — بازار
        'nim'    => 0, // نیم سکه
        'rob'    => 0, // ربع سکه
        'gerami' => 0, // سکه گرمی
    ],
];

echo json_encode($prices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
