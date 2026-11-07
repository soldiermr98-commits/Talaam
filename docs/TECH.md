# TECH.md — مستند فنی «طلام»

## ۱) نمای معماری


┌───────────────────────────── Android WebView (Capacitor) / مرورگر ─────────────────────────────┐
│                                                                                               │
│  UI (React + RTL) ── useApp() Context ── lib/prices ── lib/storage ── Capacitor Preferences   │
│        │                             │                        └── localStorage (خواندن همگام)  │
│        │                             ├── fetch (۵s timeout) ──► PRICES_URL (فایل JSON)         │
│        │                             │                            ▲                            │
│  lib/calc (توابع خالص + تست)          │                            │ cron/دستی                  │
│        │                             │                   ┌────────┴────────┐                   │
│  lib/numbers (پارس/حروف)              │                   │ server/ worker   │                   │
│        │                             │                   │ server/ php+cron │                   │
│  lib/share ── html-to-image ──► PNG ──┘                   │ (منبع نرخ: TODO) │                   │
│        └── Capacitor Share/Filesystem (اندروید)           └──────────────────┘                   │
│  Service Worker (public/sw.js) — کش stale-while-revalidate برای آفلاین                        │
└───────────────────────────────────────────────────────────────────────────────────────────────┘


## ۲) ساختار پوشه و مسئولیت‌ها

| مسیر | مسئولیت |
|---|---|
| `src/config/index.ts` | همه ثابت‌ها: PRICES_URL، MOCK_MODE، تایم‌اوت‌ها، درصدهای پیش‌فرض، آستانه‌ها، ثابت سکه‌ها، ADS_ENABLED، لینک فروشگاه‌ها |
| `src/lib/calc.ts` | توابع محاسباتی خالص (بدون UI، بدون state) — همه خروجی‌ها تومان و عدد صحیح |
| `src/lib/numbers.ts` | نرمال‌سازی ارقام، قالب‌بندی، عدد به حروف فارسی |
| `src/lib/prices.ts` | اعتبارسنجی schema، fetch با timeout، کش، زمان نسبی |
| `src/lib/storage.ts` | Capacitor Preferences + localStorage؛ خواندن همگام برای کول‌استارت |
| `src/lib/share.ts` | خروجی PNG فاکتور (html-to-image) + Share/Filesystem اندروید + Web Share/دانلود وب |
| `src/lib/ad/*` | رابط AdProvider + Noop + استاب Tapsell/Yektanet |
| `src/state/AppContext.tsx` | مرکز state: تنظیمات، نرخ مؤثر، ورودی‌های هر تب، تم، آنلاین/آفلاین |
| `src/components/*` | UI: TopBar، PriceStrip، Navigation، NumberInputs، ResultCard، Sheets، GlassScrollbar، AdBanner |
| `src/screens/tabs/*` | چهار تب محاسبه + `InvoicePreview.tsx` |
| `src/styles/*` | توکن‌های طراحی (global.css) + فونت‌face محلی (fonts.css) |
| `public/` | manifest، sw.js، prices.mock.json، آیکون‌ها |
| `scripts/prepare-brand-icons.mjs` | تولید همه آیکون‌ها/اسپلش از `assets/brand/icon-master.png` (PNG codec دستی، بدون وابستگی) |
| `server/` | نمونه Cloudflare Worker و PHP برای سرو فایل نرخ (منطق منبع = TODO) |
| `store/` | متون پنل بازار/مایکت + صفحه سیاست حفظ حریم خصوصی |
| `.github/workflows/android.yml` | CI: تست + بیلد وب + APK دیباگ/رلیز + AAB و آپلود آرتیفکت |
| `android/` | پروژه Capacitor (minSdk 24، targetSdk 36، package `talam.app`) |

## ۳) جریان داده نرخ‌ها

1. **کول‌استارت**: `AppContext` در همان initializer ها با `storageGetJSONSync` (localStorage) قیمت/settings/override/inputs را می‌خواند → اولین رندر با نرخ قبلی، بدون spinner.
2. **هماهنگ‌سازی**: بعد از mount، `loadCachedPrices` (Preferences) خوانده می‌شود؛ اگر localStorage خالی بود همان اعمال می‌شود.
3. **دریافت پس‌زمینه**: `refreshPrices()` → `fetch(PRICES_URL)` با `AbortController` تایم‌اوت ۵s (`FETCH_TIMEOUT_MS`) و `cache:'no-store'`.
4. **خطا**: هر throw (شبکه/HTTP/JSON/schema) بی‌صدا catch می‌شود — UI به کش قبلی برمی‌گردد. هیچ NaN به UI نمی‌رسد چون `validatePriceData` داده نامعتبر را کامل رد می‌کند.
5. **موفقیت**: اعتبارسنجی → `setPrices` + `savePrices` (هر دو لایه ذخیره) → پاک شدن override دستی (رفتار spec: معتبر تا fetch موفق بعدی) → `updatedAt` جدید.
6. **override دستی**: کاربر از نوار نرخ مقدار می‌دهد (`OVERRIDE_STORAGE_KEY`)؛ `gold18` مؤثر = override ?? fetched؛ دکمه «بازگشت به نرخ خودکار» override را حذف و fetch دستی می‌زند.
7. **«بروزرسانی X دقیقه پیش»**: `relativeTime()` با تیک ۳۰ ثانیه؛ >۶ ساعت کلاس `stale` (کهربایی).

## ۴) Schema فایل نرخ + قواعد اعتبارسنجی

```json
{
  "updated_at": "2026-01-01T12:00:00+03:30",
  "gold18": 8000000,
  "mazaneh": 34654400,
  "coins": { "emami": 88000000, "bahar": 86000000, "nim": 44000000, "rob": 23000000, "gerami": 12000000 }
}


| فیلد | نوع | قاعده |
|---|---|---|
| `updated_at` | رشته ISO | جاافتاده → زمان فعلی سرور/دستگاه |
| `gold18` | عدد > 0 (رشته عددی هم پذیرفته می‌شود — lenient) | نامعتبر → کل payload رد؛ اپ به کش/دستی برمی‌گردد |
| `mazaneh` | عدد > 0 | صفر/جاافتاده → `round(gold18 × 4.3318)` |
| `coins.*` | عدد ≥ 0 | نامعتبر → 0 (UI حالت «دستی وارد کن») |

اعتبارسنجی: `src/lib/prices.ts` → `validatePriceData` (تست کامل: `src/lib/__tests__/audit.test.ts`).

## ۵) فرمول‌ها (با مثال عددی = همان تست‌ها)

### طلای نو (`calcNewGold`)

raw     = weight × gold18
making  = percent mode: raw × m/100   |   fixed mode: weight × mFixed
profit  = (raw + making) × p/100
tax     = (making + profit) × t/100      ← فقط بر اجرت و سود
total   = round(raw) + round(making) + round(profit) + round(tax)

مثال: w=4.25، p18=8,000,000، m=15%، p=7%، t=10% → 34,000,000 / 5,100,000 / 2,737,000 / 783,700 / **42,620,700**

### معکوس‌خوانی (`calcReverse`)

R = weight × gold18 ;  P = مبلغ اعلامی
premium% = (P − R)/R × 100        ← بدون فرض (عدد اصلی نمایش)
M = (P − R(1 + p + t·p)) / (1 + p + t·p + t)   ← اجرت ضمنی (با فرض p,t)
making% = M/R × 100
حکم: making% ≤ 12 منصفانه | 12–20 کمی گران | >20 گران | P<R → پیام خنثی

مثال: 4.25g، ۸M، P=42,620,700 → M=5,100,000 → making%=۱۵ → «کمی گران»؛ premium=۲۵.۴٪.
**round-trip تست شده**: خروجی مستقیم (حتی با اجرت ثابت) به معکوس داده می‌شود و همان اجرت برمی‌گردد.

### دست دوم (`calcSecondHand`)

perGram = gold18 × k / 18
raw = weight × perGram ;  margin = raw × margin%/100
buy:  total = raw + margin    sell:  total = raw − margin


### مظنه (`mazanehToGram18`)

gram18 = mazaneh / 4.3318        (4.6083g مثقال × 705/750 خلوص ۱۷→۱۸)
34,000,000 → ≈ 7,849,000 تومان/گرم


### حباب سکه (`calcCoinBubble`)

pureGold  = weight × purity            (امامی/بهار: 8.133g × 0.9)
intrinsic = pureGold / 0.75 × gold18   (0.75 = خلوص ۱۸ عیار)
bubble = market − intrinsic ;  bubble% = bubble/intrinsic × 100
سطح: <5% کم | 5–15% متوسط | >15% زیاد

⚠️ ثابت‌های وزن/خلوص نیاز به تأیید جواهر دارد (AUDIT F-16).

### مبدل واحدها (`convertUnits`)
گرم=1، سوت=0.01g، مثقال صرافی=4.6083g (۲۴ نخود)، مثقال شرعی=3.456g، نخود=0.192g — نیاز به تأیید.

## ۶) مرجع پیکربندی (`src/config/index.ts`)

| کلید | مقدار فعلی | برای تغییر |
|---|---|---|
| `APP_ID` / appId | `talam.app` | `config/index.ts:4` + `capacitor.config.ts` + `android/app/build.gradle` + `strings.xml` (پس از انتشار قابل تغییر نیست!) |
| `MOCK_MODE` | `true` | خط 12 — بعد از وصل منبع واقعی `false` |
| `PRICES_URL` | `/prices.mock.json` | خط 13 — آدرس endpoint واقعی |
| `FETCH_TIMEOUT_MS` | 5000 | خط 15 |
| `STALE_WARNING_MS` | ۶ ساعت | خط 16 |
| `DEFAULT_SETTINGS` (tax/profit/margin) | 10/7/5 | خط‌های 24-30 — کاربر از شیت تنظیمات هم می‌تواند تغییر دهد |
| `VERDICT_THRESHOLDS` | fair 12 / expensive 20 | خط 33 |
| `BUBBLE_THRESHOLDS` | medium 5 / high 15 | خط 34 |
| `COIN_SPECS` | وزن/خلوص ۵ سکه | خط‌های 40-48 — ⚠️ نیاز به تأیید |
| `ADS_ENABLED` / `AD_PROVIDER` / `AD_BANNER_ID` | false / tapsell / TODO | خط‌های 56-58 |
| `BAZAAR_RATE_URL` / `MYKET_APP_URL` | از APP_ID ساخته می‌شود | خط‌های 62-64 |

## ۷) خط تولید نرخ سمت سرور (PHP + cron) — پیاده‌سازی کامل

هدف: هر ~۵ دقیقه یک فایل استاتیک `prices.json` از منبع(های) نرخ ساخته و منتشر شود؛ اپ فقط همین فایل را می‌خواند و هرگز مستقیم به API ثالث وصل نمی‌شود.

```
منبع ۱ (API) ─┐
منبع ۲ (API) ─┼─► providers/*.php ─► validate+sanity ─► jump-guard ─► atomic write ─► public/prices.json
override دستی ┘   (تومان، نرمال)     (schema/بازه/        (≤۵٪ یا تأیید با    (temp+rename)          ▲
                                      ترتیب/نسبت مظنه)     منبع دوم یا N اجر)                    همه شکست؟
                                                                                                 stale=true ┘
اپ (Capacitor/PWA) ── fetch (۵s timeout) ──► https://…/server/public/prices.json ──► کش محلی ──► UI
```

- **کد**: `server/fetch_prices.php` (CLI + `--dry-run` + `--diagnose` + وب‌کرون با توکن timing-safe)، `server/src/PricePipeline.php`، `server/src/ProviderFactory.php`، `server/providers/*` — پیکربندی: `server/config.example.php` → `config.php` (git-ignored)
- **آداپتور کانفیگ‌محور**: `ConfigurableHttpProvider` — افزودن/تعویض منبع فقط با بلوک config (map مسیر نقطه‌ای، auth query/header/bearer از env، unit rial/toman + multiplier، پاکسازی ارقام فارسی/عربی، داده جزئی با merge و source مثل "a+b"، min_interval + daily_cap با کاهش خودکار سرعت). مرجع کامل + «افزودن منبع در ۵ دقیقه» + مهاجرت schema: `server/README_SERVER.md`
- **نسخه‌بندی schema**: `schema_version: 1` فقط افزودنی؛ تغییر شکننده = فایل جدید prices.v2.json (اپ ناشناخته‌ها را نادیده می‌گیرد — تست شده)
- **استقرار، تست از گوشی، چرخش کلید، عیب‌یابی و جدول PROVIDER DECISION**: `server/README_SERVER.md`
- **تست‌ها**: `php server/tests/run_tests.php` → ۲۴ تست واقعاً اجرا شده (آپدیت نرمال، fallback بعد از timeout، مقادیر نامعتبر، خطای ۱۰x ریال، پرش ۲۰٪ رد→پذیرش پس از ۳ اجر متوالی، تأیید منبع دوم، همه‌خراب=stale با updated_at اصلی، بدون last_good=error بدون فایل جعلی، نوشتن اتمیک، lock، override دستی و انقضا، نسبت مظنه، ترتیب سکه‌ها، هشدار، استاب‌های unverified)
- **schema نهایی**: فیلدهای `source` و `stale` اضافه شد — اپ `stale=true` را می‌فهمد و «نرخ قدیمی است» را کنار «بروزرسانی X دقیقه پیش» نشان می‌دهد (`src/components/PriceStrip.tsx`)
- **امنیت**: secrets فقط در config.php (git-ignored)؛ `data/` از وب مسدود؛ توکن وب‌کرون با `hash_equals`؛ استاب‌ها بدون کلید RuntimeException می‌دهند — هیچ داده جعلی

### تفاوت mock و release در اپ

- `MOCK_MODE = import.meta.env.DEV` — **بیلد release هرگز mock نمی‌گیرد** (`src/config/index.ts:8-15`)؛ `PRICES_URL` در release پیش‌فرض `/prices.json` و با `VITE_PRICES_URL` قابل تغییر است
- فایل `prices.mock.json` با پلااگین vite از `dist/` حذف می‌شود (اثبات: grep باندل → صفر ارجاع به mock)
- release بدون کش/شبکه → حالت «نرخ را دستی وارد کن» — اثبات واقعی روی preview: پاک‌سازی کامل storage → `hasMadeUpPrice:false` و `editorOpen:true`

## ۸) بیلد و انتشار

```bash
npm install            # Node 22+ برای CLI کاپacitor 8
npm run dev            # توسعه
npm test               # ۵۹ تست
npm run build          # بیلد وب → dist/
npm run cap:sync       # کپی web به android
npm run apk:debug      # APK دیباگ (تست نصب)
npm run apk            # APK رلیز امضاشده (نیاز به keystore.properties)
npm run aab            # AAB برای پنل مارکت‌ها
node scripts/prepare-brand-icons.mjs   # بازسازی آیکون/اسپلش از assets/brand/icon-master.png
```

- **JDK 21** لازم است (`org.gradle.java.home` یا JAVA_HOME). Android SDK در `android/local.properties`.
- **CI**: هر پوش به main → `.github/workflows/android.yml` → آرتیفکت `talam-apk` و `talam-aab`.
- **امضا**: `talam-release.keystore` + `android/keystore.properties` (هر دو gitignore). **بکاپ keystore حیاتی است.** CI با ۴ secret امضا می‌کند؛ بدون آن debug-key.
- **versioning**: `versionCode` باید هر انتشار +1 شود؛ `versionName` سمانتیک مثل 1.0.1.
- **PWA**: `dist/` را روی هر هاست static بگذار؛ SW همه‌چیز را کش می‌کند. ⚠️ با هر تغییر محتوایی که SW هم لود می‌کند، `CACHE` در `public/sw.js` را بامپ کن (`talam-v1` → `v2`).

## ۹) استراتژی منبع نرخ — جدول تصمیم کامل در `server/README_SERVER.md` (بخش PROVIDER DECISION)

| گزینه | وضعیت |
|---|---|
| ManualProvider (override.json روزانه) | **فعال و تست‌شده** — برای شروع توصیه می‌شود (صفر ریسک حقوقی/هزینه) |
| سرویس‌های API ایرانی (سبک Navasan/BrsApi) | استاب UNVERIFIED — endpoint/شرایط/هزینه باید توسط شما از مستندات رسمی تأیید شود |
| اسکرپ سایت‌های نرخ | توصیه نمی‌شود (ریسک ToS) |
| fallback chain نهایی | API → ManualProvider → last_good با stale → ورودی دستی کاربر |

هر endpoint/قیمتی که اینجا نام نبرده‌ایم عمداً حذف شده تا «تأییدنشده» جعل نشود.

## ۱۰) تست‌ها و چک‌لیست دستی

- `npm test` → ۵۹ تست: فرمول‌ها، پارس، حروف، schema خراب، زمان نسبی، round-trip.
- **چک‌لیست دستی قبل از هر انتشار** (روی گوشی): نصب APK → کول‌استارت < ۱s (حسی) → حالت هواپیما: نرخ قبلی + ورودی دستی → تغییر تم → تب‌های ۴گانه → ساخت فاکتور → «ذخیره تصویر» → شیت اشتراک‌گذاری اندروید → اسکرول بدون پرش → صفحه ۳۶۰px → RTL کامل → صفحه تنظیمات.

## ۱۱) محدودیت‌ها و دِین فنی

- ESLint تنظیم نشده؛ R8/minify خاموش (APK ۳.۸۳MB با فضای خالی کمی)؛ caret تایپ مبلغ به انتها می‌پرد؛ تست‌های instrumented قالب Capacitor پکیج قدیمی دارند؛ «ذخیره مستقیم در گالری» نیاز به پلااگین MediaStore دارد (فعلاً از شیت Share)؛ SW cache version باید با انتشار بامپ شود؛ ثابت‌های سکه/آستانه‌ها تأیید مستقل نشده‌اند.

## ۱۲) افزودن قابلیت‌های v2 بدون بازنویسی

- **گاوصندوق من**: ماژول `src/lib/portfolio.ts` (محلی، همان الگوی storage) + یک تب ششم در `Navigation.tsx`؛ state در AppContext جدا.
- **ویجت نرخ**: نیاز به Module Widget اندروید نیتیو (اتمیک) — داده را از یک فایل JSON داخل assets آپدیت‌شده با `cap sync` یا از همان Worker می‌خواند؛ وب‌لایه دست‌نخورده.
- **هشدار نرخ**: Background task/cron سمت سرویس + Local Notifications (پلااگین رسمی `@capacitor/local-notifications`) — UI تنظیمات با یک row.
- **VIP**: خرید درون‌برنامه بازار/مایکت → قفل/باز کردن با یک flag در storage؛ AdBanner را gate کن.

