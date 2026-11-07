# README_SERVER.md — سرور نرخ‌های طلام (PHP + cron)

خط تولیدی سمت سرور که هر ~۵ دقیقه از منابع نرخ می‌گیرد، اعتبارسنجی و sanity-check می‌کند و یک فایل استاتیک `prices.json` منتشر می‌کند. اپ فقط همین فایل را می‌خواند و هیچ‌وقت مستقیم به APIهای ثالث وصل نمی‌شود.

## ساختار

```
server/
├── fetch_prices.php          # نقطه ورود: cron | --dry-run | --diagnose | وب‌کرون با توکن
├── config.example.php        # الگوی پیکربندی → کپی به config.php (git-ignored)
├── config.php                # ⚠️ محلی — هرگز کامیت نشود
├── src/PricePipeline.php     # هسته: merge جزئی، validation، jump-guard، انتشار اتمیک، stale
├── src/ProviderFactory.php   # رجیستری منابع از config
├── providers/
│   ├── ProviderInterface.php           # رابط مشترک
│   ├── BaseProvider.php                # HTTP با تایم‌اوت/retry + تبدیل ریال→تومان
│   ├── ConfigurableHttpProvider.php    # ⭐ آداپتور همه‌کاره — همه‌چیز از config
│   ├── ManualProvider.php              # override دستی با انقضا (آخرین fallback)
│   ├── TemplateProvider.php            # قالب آداپتور سفارشی
│   ├── NavasanStyleProvider.php        # ⚠️ UNVERIFIED استاب
│   └── BrsapiStyleProvider.php         # ⚠️ UNVERIFIED استاب
├── public/
│   ├── prices.json           # فایل منتشرشده (اپ همین را می‌خواند)
│   └── .htaccess             # Content-Type / Cache-Control: max-age=60 / CORS / gzip
├── data/                     # محلی — از وب مسدود (.htaccess)
│   ├── last_good.json        # آخرین داده معتبر
│   ├── state.json            # شمارنده‌ها، منبع هر فیلد، وضعیت هشدار
│   ├── override.json         # override اضطراری (نمونه: override.json.example)
│   └── logs/                 # app.log (چرخشی) + rejected.log
├── tests/run_tests.php       # ۲۴ تست — php tests/run_tests.php
└── cloudflare-worker.js      # گزینه ثانویه (توصیه‌شده نیست برای ایران)
```

## دستورها

```bash
php fetch_prices.php               # اجرای کامل و انتشار
php fetch_prices.php --dry-run     # fetch+validate بدون انتشار (خروجی فقط نمایشی)
php fetch_prices.php --diagnose    # سلامت هر منبع: reachable، latency، فیلدهای پارس‌شده، validation، rate-limit — بدون هیچ secret
php tests/run_tests.php            # ۲۴ تست
```

## مرجع کانفیگ ConfigurableHttpProvider

افزودن/تعویض منبع = فقط ویرایش `config.php`. هیچ تغییری در کد یا اپ لازم نیست.

| گزینه | اجباری | توضیح |
|---|---|---|
| `id` | ✔ | شناسه کوتاه؛ در `source` خروجی می‌آید |
| `url` | ✔ | آدرس https API |
| `map` | ✔ | نگاشت فیلدها: `'gold18' => ['path' => 'data.gold.18k.price', 'unit' => 'toman'\|'rial', 'multiplier' => 1.0]` — فیلدهای مجاز: `gold18`, `mazaneh`, `coins.emami/bahar/nim/rob/gerami` (همه اختیاری جز حداقل یکی) |
| `method` | | GET (پیش‌فرض) یا POST |
| `query` / `headers` | | پارامتر/هدرهای سفارشی |
| `auth` | | `{type: 'query'\|'header'\|'bearer', name: 'api_key', key: '…' یا key_env: 'ENV_NAME'}` — کلید فقط از config/env |
| `enabled` | | پیش‌فرض true؛ تا تکمیل تأیید false بگذار |
| `max_age_seconds` | | حداکثر عمر داده کش این منبع برای استفاده هنگام skip |
| `min_interval_seconds` | | حداقل فاصله فراخوانی واقعی (احترام به rate limit) |
| `daily_cap` | | سقف روزانه؛ نزدیک سقف فاصله خودکار ۲ برابر؛ رسیدن به سقف → skip |
| `timeout/retries/backoff` | | از بخش `http` config ارث می‌برد |

- **پارس عدد**: ارقام فارسی/عربی، جداکننده هزارگان (٬ , ، فاصله) و ممیز (٫ .) خودکار پاک می‌شوند
- **مسیر نقطه‌ای**: `data.gold.18k.price`؛ ایندکس آرایه: `items.0.price`؛ کلید انجمنی: `coins.18k`
- **داده جزئی مجاز**: منبع میتواند فقط بعضی فیلدها را بدهد؛ pipeline در ترتیب اولویت merge میکند و `source` خروجی مثل `a+b` میشود؛ منبعِ هر فیلد در `data/state.json` (کلید `field_source`) ثبت میشود
- **freshness**: داده کش منبع فقط تا `max_age_seconds` استفاده میشود؛ هرگز با داده قدیمی‌تر mix نمیشود

## افزودن منبع در ۵ دقیقه (مثال کارشده با منبع ساختگی)

1. مستندات رسمی منبع را بخوان: endpoint، پارامتر کلید، نام فیلدها، واحد (ریال/تومان)، محدودیت نرخ
2. در `config.php` یک بلوک اضافه کن (بالاتر از ManualProvider):

```php
['class' => 'ConfigurableHttpProvider', 'options' => [
    'id' => 'my-source',
    'url' => 'https://api.mysource.tld/gold',
    'auth' => ['type' => 'bearer', 'key' => 'کلید-واقعی-از-پنل-منبع'],
    'map' => [
        'gold18' => ['path' => 'rates.gold_18k', 'unit' => 'rial'],   // ریال → تومان خودکار
        'coins.emami' => ['path' => 'rates.coin_emami', 'unit' => 'toman'],
    ],
    'min_interval_seconds' => 300,
    'daily_cap' => 300,
]],
```

3. تست: `php fetch_prices.php --diagnose` (بدون انتشار) → فیلدهای پارس‌شده و validation را ببین → `--dry-run` → در صورت درستی اجرای واقعی
4. آپ فقط `/prices.json` را می‌خواند — هیچ تغییری در اپ لازم نیست

## داشبورد (admin.php) — نرخ‌ها + تنظیمات API بدون رفتن به هاست

`admin.php?token=ADMIN_TOKEN` دو بخش دارد:
- **نرخ‌ها**: فرم موبایلی (گرم/مظنه/۵ سکه) → ذخیره + انتشار فوری
- **تنظیمات API**: ویرایش اولین بلوک ConfigurableHttpProvider (enabled، URL، Query/Headers JSON، auth type/name/key، map JSON، min_interval، daily_cap) + «تست اتصال» + «نمایش RAW پاسخ» برای تنظیم مسیرهای Map — بدون رفتن به هاست
- کلید فعلی بهصورت ماسک نمایش داده میشود؛ خالی بگذاری = بدون تغییر

(اگر روزی خواستی همین کار را با ویرایش فایل بکنی: config.php را مستقیم ویرایش کن — هر دو مسیر معتبرند.)

## ویرایش نرخ از گوشی (admin.php) — Plan B عملیاتی

نیازی به API خارجی نیست: یک صفحه ادمین موبایلی روی همان سرور که نرخ را می‌گیری و انتشار فوری می‌کند.

1. در `config.php`: `'admin_token' => 'یک-رشته-تصادفی'` (خالی = خاموش)
2. از گوشی: `https://YOUR-HOST/server/admin.php?token=YOUR_TOKEN`
3. نرخ‌ها را بزن → «ذخیره و انتشار فوری» → `override.json` نوشته و pipeline همان لحظه منتشر می‌کند (اعتبار ۲۴ ساعت)
4. اپ‌ها در fetch بعدی (حداکثر ۵ دقیقه) می‌گیرند

این همان Plan B جدول PROVIDER DECISION است: روزی یک دقیقه کار دستی، صفر ریسک حقوقی، صفر هزینه. برای نرخ لایو خودکار، بلوک ConfigurableHttpProvider را با سرویس API واقعی پر کن.

## نسخه‌بندی schema (قاعده تکاملی)

- `schema_version: 1` در خروجی؛ تغییرات **فقط افزودنی** (فیلد جدید) مجاز است و اپ ناشناخته‌ها را نادیده می‌گیرد (تست‌شده)
- تغییر **شکننده** (تغییر نام/نوع فیلد موجود) → فایل جدید `prices.v2.json`؛ نسخه‌های قدیمی اپ با `prices.json` به کار ادامه میدهند
- اپ پارسر را برای `schema_version` جاافتاده هم tolerant نگه میدارد (تست: `src/lib/__tests__/audit.test.ts`)

## per-field freshness

`updated_at_by_field` در خروجی (افزودنی) زمان هر فیلد را جدا گزارش میکند — چون merge جزئی ممکن است فیلدها از منابع مختلف باشند. ارزان بود و پیاده شد.

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
۲۴ تست: هسته (آپدیت نرمال، fallback، مقادیر نامعتبر، خطای ۱۰x ریال، پرش ۲۰٪ رد→پذیرش با N اجر، تأیید منبع دوم، همه‌خراب=stale، بدون last_good=error، اتمیک، lock، override+انقضا، نسبت مظنه، ترتیب، هشدار، استاب‌ها) + آداپتور کانفیگ‌محور (validation کانفیگ، مسیر تو در تو و ایندکس آرایه، پاکسازی ارقام فارسی، ریال→تومان و multiplier، merge جزئی دو منبع با source "a+b"، rate-limit skip با کش، daily cap، diagnose بدون secret، dry-run، Factory با کلاس ناشناخته) + سازگاری schema_version در اپ (تست TypeScript).

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
