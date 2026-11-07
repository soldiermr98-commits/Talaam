# راهنمای استقرار روی hojre24shop.ir/Talaam — قدم به قدم

بسته‌های آماده در پوشه `deploy/`:
- `talam-web.zip` — نسخه تحت وب اپ (PWA) → آپلود در `public_html/Talaam/`
- `talam-api.zip` — سرور نرخ (fetch، admin، pipeline) → آپلود در `public_html/Talaam/api/`
- APK ها: `talam-v1.0.0-test.apk` و `talam-v1.0.0-release.apk` — این‌ها روی گوشی نصب می‌شوند و **آدرس سرور داخلشان قبلاً تنظیم شده** (`https://hojre24shop.ir/Talaam/api/public/prices.json`)

آدرس‌های نهایی بعد از استقرار:
| چی | آدرس |
|---|---|
| وب‌اپ (بدون نصب) | `https://hojre24shop.ir/Talaam/` |
| فایل نرخ (اپ این را می‌خواند) | `https://hojre24shop.ir/Talaam/api/public/prices.json` |
| ادمین نرخ از گوشی | `https://hojre24shop.ir/Talaam/api/admin.php?token=توکن-شما` |

---

## پیش‌نیازها (در پنل هاست چک کن)

1. **PHP نسخه ۷.۴ یا بالاتر** — cPanel → «Select PHP Version» → 8.1 یا 8.3 بگذار
2. **SSL فعال** — cPanel → «SSL/TLS Status» → Run AutoSSL (Let's Encrypt رایگان). بدون HTTPS اپ به سرور وصل نمی‌شود
3. **دسترسی Cron** — cPanel → «Cron Jobs» (اگر نبود، از وب‌کرون رایگان cron-job.org استفاده می‌کنیم — قدم ۷)

---

## قدم ۱ — آپلود بسته‌ها

1. cPanel → **File Manager** → وارد `public_html` شو
2. پوشه جدید بساز به اسم `Talaam`
3. دو فایل زیپ `talam-web.zip` و `talam-api.zip` را (از `deploy/` روی کامپیوترت) آپلود کن داخل `public_html/Talaam/`
4. روی هر زیپ راست‌کلیک → **Extract** (هر دو)

ساختار نهایی باید این باشد:
```
public_html/Talaam/
├── index.html, assets/, icons/, manifest.webmanifest, sw.js   ← وب‌اپ
└── api/
    ├── fetch_prices.php, admin.php, config.example.php
    ├── providers/, src/, public/prices.json, data/, tests/
    └── README_SERVER.md
```

## قدم ۲ — ساخت config.php

1. در File Manager داخل `api/` فایل `config.example.php` را کپی کن و نامش را `config.php` بگذار
2. **Edit** کن و فقط این‌ها را پر کن:
   - `'admin_token' => 'یک-رشته-تصادفی-خودت'` → برای ورود به ادمین از گوشی
   - `'http_trigger_token' => 'یک-رشته-تصادفی-دیگر'` → برای وب‌کرون (اگر cron هاست نبود)
   - بقیه فعلاً دست نزن (ManualProvider فعال است)
3. Save

⚠️ `config.php` هیچ‌وقت برای کسی نفرست و کامیت نکن.

## قدم ۳ — تست فوری (قبل از cron)

از گوشی یا مرورگر:
1. `https://hojre24shop.ir/Talaam/` → وب‌اپ باید باز شود («نرخ را وارد کن»)
2. `https://hojre24shop.ir/Talaam/api/admin.php?token=توکن-شما` → فرم نرخ
3. نرخ‌ها را بزن → «ذخیره و انتشار فوری»
4. `https://hojre24shop.ir/Talaam/api/public/prices.json` → نرخ‌های جدید باید دیده شود ✓

اگر خطای 500 دیدی: PHP version را چک کن و `api/data/` باید قابل نوشتن باشد (File Manager → Permissions → 755).

## قدم ۴ — cron هر ۵ دقیقه

cPanel → **Cron Jobs** → Add New → `*/5 * * * *` و این فرمان (به‌جای USERNAME نام کاربری هاستت — در همان صفحه Cron Jobs بالای فرمان‌ها نوشته شده):

```
/usr/local/bin/php /home/USERNAME/public_html/Talaam/api/fetch_prices.php >> /home/USERNAME/public_html/Talaam/api/data/logs/cron.log 2>&1
```

اگر مسیر php فرق کرد، در پنل «Select PHP Version» یا از پشتیبانی هاست بپرس. بعد از ۵-۱۰ دقیقه `data/logs/cron.log` و `data/logs/app.log` را چک کن.

**اگر cron نداشت**: در cron-job.org رایگان یک job بساز که هر ۵ دقیقه این URL را صدا بزند:
```
https://hojre24shop.ir/Talaam/api/fetch_prices.php?token=مقدار-http_trigger_token
```

## قدم ۵ — نصب اپ روی گوشی

- `talam-v1.0.0-test.apk` (یا release) را روی گوشی نصب کن — این بیلد **مستقیماً به hojre24shop.ir وصل است**
- با اینترنت: نرخ‌های admin را می‌گیرد؛ بی‌اینترنت: آخرین نرخ؛ بدون هر دو: نرخ دستی
- هر تغییری در admin → حداکثر ۵ دقیقه بعد در اپ

## قدم ۶ — (بعداً، اختیاری) وصل کردن API واقعی

وقتی خواستی نرخ خودکار از یک سرویس بگیری:
1. در سرویس انتخابی ثبت‌نام + کلید بگیر + مستنداتش را بخوان
2. در `api/config.php` بلوک `ConfigurableHttpProvider` را (نمونه کامنت‌شده در `config.example.php`) با endpoint/کلید/مسیر فیلدها پر کن و `enabled => true`
3. تست: `php fetch_prices.php --diagnose` (SSH) یا موقتاً از مرورگر با وب‌کرون
4. اولویت را بالاتر از Manual بگذار؛ Manual بماند برای روزهای خرابی
(شرح کامل گزینه‌ها: `api/README_SERVER.md`)

---

## امنیت — این‌ها را جدی بگیر

- `admin_token` و `http_trigger_token` رشته‌های تصادفی طولانی باشند و برای کسی نفرست
- کلیدهای API سرویس‌ها فقط در `config.php` (git-ignored)؛ هرگز در چت/ریپو/اسکرین‌شات
- `data/` به‌صورت پیش‌فرض با htaccess مسدود است — پاکش نکن
- keystore را بکاپ گرفته‌ای؟ (یادآوری همیشگی)

## عیب‌یابی سریع

| مشکل | چک کن |
|---|---|
| 500 روی admin/fetch | نسخه PHP + وجود `api/config.php` |
| prices.json آپدیت نمی‌شود | `api/data/logs/app.log` + `cron.log`؛ cron درست ست است؟ |
| اپ «نرخ قدیمی است» می‌دهد | normal — یعنی سرور تازه نکرده؛ cron را چک کن |
| اپ به سرور وصل نمی‌شود | SSL معتبر است؟ آدرس دقیقاً `…/api/public/prices.json`؟ |
