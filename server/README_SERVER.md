# README_SERVER.md — سرور نرخ‌های طلام (PHP + cron)

خط تولیدی سمت سرور که هر ~۵ دقیقه از منابع نرخ می‌گیرد، اعتبارسنجی و sanity-check می‌کند و یک فایل استاتیک `prices.json` منتشر می‌کند. اپ فقط همین فایل را می‌خواند و هیچ‌وقت مستقیم به APIهای ثالث وصل نمی‌شود.

## ساختار

```
server/
├── fetch_prices.php          # نقطه ورود cron (CLI + وب‌کرون با توکن)
├── config.example.php        # الگوی پیکربندی → کپی به config.php (git-ignored)
├── config.php                # ⚠️ محلی — هرگز کامیت نشود
├── src/PricePipeline.php     # هسته: زنجیره منابع، validation، jump-guard، انتشار اتمیک
├── providers/
│   ├── ProviderInterface.php # رابط مشترک
│   ├── BaseProvider.php      # HTTP با تایم‌اوت/retry + تبدیل ریال→تومان
│   ├── TemplateProvider.php  # قالب ساخت آداپتور جدید (کپی کن)
│   ├── NavasanStyleProvider.php  # ⚠️ UNVERIFIED استاب
│   ├── BrsapiStyleProvider.php   # ⚠️ UNVERIFIED استاب
│   └── ManualProvider.php    # فایل override دستی با انقضا
├── public/
│   ├── prices.json           # فایل منتشرشده (اپ همین را می‌خواند)
│   └── .htaccess             # Content-Type / Cache-Control: max-age=60 / CORS / gzip
├── data/                     # محلی — از وب مسدود (.htaccess)
│   ├── last_good.json        # آخرین داده معتبر
│   ├── state.json            # شمارنده اجرهای متوالی + وضعیت هشدار
│   ├── override.json         # فایل override اضطراری (نمونه: override.json.example)
│   └── logs/                 # app.log (چرخشی) + rejected.log
├── tests/run_tests.php       # تست‌ها — php tests/run_tests.php
└── cloudflare-worker.js      # گزینه ثانویه (توصیه‌شده نیست برای ایران)
```

## استقرار روی هاست اشتراکی (cPanel/DirectAdmin)

1. **آپلود**: پوشه `server/` را آپلود کن. بهتر است **بیرون از web root** باشد (مثلاً `~/talam-server/`)؛ اگر داخل public_html است، `data/.htaccess` و `public/.htaccess` باید آپلود شوند (هستند) و PHP در حال اجرا باشد.
2. **کانفیگ**: `config.example.php` → کپی به `config.php` → پر کن (منابع/کلیدها/توکن وب‌کرون).
3. **دسترسی‌ها (permissions)**: پوشه‌ها `755`، فایل‌های PHP/JSON `644`؛ `data/` باید قابل‌نوشتن توسط PHP باشد (معمولاً 755 کافی است؛ اگر سخت‌گیری، 775).
4. **اولین اجرا (تست)**:
   ```
   php /home/USER/talam-server/fetch_prices.php
   ```
   باید JSON با `"status":"ok"` چاپ کند و `public/prices.json` آپدیت شود. اگر `status:"error"` بود، `data/logs/app.log` را ببین.
5. **cron دقیق** (cPanel → Cron Jobs → هر ۵ دقیقه):
   ```
   */5 * * * * php /home/USER/talam-server/fetch_prices.php >> /home/USER/talam-server/data/logs/cron.log 2>&1
   ```
   exit code: 0=ok، 2=stale، 3=error، 4=locked.
6. **وب‌کرون (بدون CLI)**: در config.php یک توکن تصادفی بگذار (`http_trigger_token`) و از سرویس cron آنلاین این URL را هر ۵ دقیقه بزن:
   `https://YOUR-HOST/server/fetch_prices.php?token=YOUR_TOKEN`
7. **HTTPS**: در cPanel «Force HTTPS Redirect» را فعال کن. اپ روی WebView با androidScheme=https اجرا می‌شود و fetch به http ممکن است مسدود شود.
8. **تست از گوشی**: `https://YOUR-HOST/server/public/prices.json` را در مرورگر گوشی باز کن — JSON باید ببینی. در DevTools (یا با curl -I) چک کن: `Content-Type: application/json` و `Cache-Control: max-age=60`.
9. **چرخش کلیدها**: کلید جدید را از پنل منبع بگیر → در `config.php` جایگزین کن → یک اجرا دستی بزن → کلید قدیمی را در پنل منبع باطل کن. تاریخچه کلیدها را در فایل محلی نگه دار، نه در چت/ریپو.

## Schema خروجی (همان چیزی که پارسر اپ انتظار دارد)

```json
{
  "updated_at": "2026-11-07T14:29:00+03:30",
  "source": "manual",
  "stale": false,
  "gold18": 8100000,
  "mazaneh": 35087580,
  "coins": { "emami": 88500000, "bahar": 86500000, "nim": 44200000, "rob": 23100000, "gerami": 12050000 }
}
```

- واحد: **تومان** (اگر منبع ریال می‌دهد، آداپتور با `toToman($v,'rial')` تبدیل می‌کند — ÷۱۰)
- `stale:true` = سرور نتوانسته تازه‌سازی کند؛ مقادیر همان last_good هستند و `updated_at` قدیمی حفظ شده است (هیچ‌وقت داده جعلی تازه ساخته نمی‌شود)
- اپ: `stale=true` → هشدار ملایم «نرخ قدیمی است» کنار «بروزرسانی X دقیقه پیش»

## قواعد validation و sanity (همه تست‌شده)

- همه مقادیر finite، مثبت، صحیح (گرد)
- `gold18` داخل بازه منطقی (`gold18_min/max`)
- هر سکه ≤ ۱۰۰× گرم ۱۸؛ ترتیب امامی ≥ نیم ≥ ربع
- نسبت `mazaneh/gold18` = 4.3318 ± 0.5 (خارج از آن → رد)
- **jump-guard**: تغیر بیش از ۵٪ نسبت به last_good رد می‌شود، مگر: (الف) منبع دوم همسان در همان سیکل، یا (ب) ۳ اجر متوالی همسان (±۱٪). ردشدگی‌ها در `rejected.log`
- انتشار **اتمیک** (temp + rename) — اپ هیچ‌وقت فایل نیمه‌نوشته نمی‌خواند
- lock فایل برای جلوگیری از cronهای هم‌زمان
- لاگ چرخشی (۵۰۰KB، ۳ نسخه) + هشدار اختیاری (تلگرام/email placeholder) وقتی همه منابع >۱۵ دقیقه خراب‌اند

## تست‌ها

```bash
php tests/run_tests.php
```
۱۵ تست: آپدیت نرمال، fallback بعد از timeout، مقادیر نامعتبر، خطای ۱۰x ریال، پرش ۲۰٪ (رد → پذیرش بعد از N اجر)، تأیید منبع دوم، همه منابع خراب (stale)، همه خراب بدون last_good (error، هیچ فایل جعلی)، نوشتن اتمیک، lock، override دستی و انقضای آن، نسبت مظنه، ترتیب سکه‌ها، هشدار، رفتار استاب‌های unverified.

## PROVIDER DECISION — جدول تصمیم منبع نرخ

| گزینه | چه چیزی باید verify شود | ریسک شرایط استفاده/تجاری | هزینه | پایداری | وضعیت |
|---|---|---|---|---|---|
| سرویس‌های API نرخ ایرانی به سبک Navasan | endpoint دقیق، نام فیلدها، واحد خروجی (ریال/تومان)، محدودیت نرخ، مجوز استفاده در اپ تجاری | **UNVERIFIED** — باید ToS رسمی خوانده شود؛ برخی این سرویس‌ها استفاده تجاری را محدود می‌کنند | معمولاً پلن رایگان محدود + پلن ماهانه (تأییدنشده) | متوسط؛ وابسته به Uptime سرویس | استاب نوشته شد — نیاز به حساب و تأیید شما |
| سرویس‌های API به سبک BrsApi | همان موارد بالا | **UNVERIFIED** | مشابه بالا | متوسط | استاب نوشته شد — نیاز به حساب و تأیید شما |
| ورودی دستی روزانه (override.json) | فقط عدد درست را از منبع قابل‌اعتماد خودت بگیر (مثلاً قیمت اعلامی مغازه/اتحادیه که دسترسی داری) | هیچ — داده خودت | صفر | صددرصد به‌شرط انضباط روزانه | **فعال و تست‌شده** — برای شروع توصیه می‌شود |
| اسکرپ سایت‌های نرخ | مجوز scraping در ToS، ساختار HTML شکننده | بالا — اکثراً منع می‌کنند | صفر | پایین | پیاده نشده — توصیه نمی‌شود مگر اجازه صریح |
| APIهای بین‌المللی طلا (XAU/USD) | نرخ ریالی داخلی نمی‌دهند؛ نیاز به تبدیل بازار آزاد | اغلب تحریم‌محور برای ایران — پرداخت مشکل | دلاری | بالا برای انس، بی‌ربط به بازار داخلی | خارج از دامنه v1 |

**استراتژی پیشنهادی**: شروع با **ManualProvider** (override.json روزانه — صفر ریسک حقوقی، صفر هزینه) → در صورت گرفتن حساب از یک سرویس API، آداپتورش را پر کن و اول در زنجیره بگذار → همیشه ManualProvider را به‌عنوان fallback آخر نگه دار. **Plan B اگر همه APIها رفتند**: همان override.json دستی — خط تولید بدون تغییر کار می‌کند.

> هر موردی که در جدول «تأییدنشده» است را قبل از اتکا خودت با مستندات رسمی سرویس چک کن — من عمداً هیچ endpoint یا قیمت واقعی‌ای را حدس نزده‌ام.

## عیب‌یابی

| مشکل | کجا ببین | راه‌حل |
|---|---|---|
| `status:"error"` و فایل منتشر نمی‌شود | `data/logs/app.log` | همه منابع رد شدند و last_good هم نبود — یک override.json معتبر بگذار یا منبع را درست کن |
| `status:"stale"` مکرر | `data/logs/rejected.log` | منبع پرش بزرگ می‌دهد یا مقادیر نامعتبر — آداپتور را چک کن؛ اگر واقعی است با ۳ اجر متوالی می‌پذیرد یا منبع دوم اضافه کن |
| `status:"locked"` مکرر | `data/lock` | cron قبلی تمام نشده (منبع کند است) — تایم‌اوت را کم کن یا فاصله cron را بیشتر کن |
| اپ «نرخ قدیمی است» می‌دهد | `public/prices.json` → `updated_at` | cron اجرا نمی‌شود؛ لاگ cron هاست را چک کن |
| JSON خراب در مرورگر | هدرها | `.htaccess` آپلود نشده یا mod_headers خاموش است |
