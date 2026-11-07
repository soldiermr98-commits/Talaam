# طلام — محاسبه‌گر طلا 💎

اپلیکیشن ساده، سریع و آفلاین‌محور محاسبه طلا برای اندروید (کافه‌بازار و مایکت) و PWA.

- **طلای نو**: وزن + اجرت (درصد یا مبلغ ثابت هر گرم) + سود طلافروش + مالیات (فقط بر اجرت و سود)
- **دست دوم و آب‌شده**: انتخاب عیار (۱۸/۱۴/۲۱/۲۲/۲۴)، خرید/فروش با حاشیه، مبدل مظنه
- **حباب سکه**: ارزش ذاتی، مبلغ و درصد حباب، مقایسه قطعات
- **چند درصد حساب کرده؟**: محاسبه معکوس اجرت و سود فروشنده با حکم منصفانه/کمی گران/گران
- **فاکتور تصویری**: پیش‌فاکتور ۱۰۸۰px با تاریخ شمسی و کد پیگیری + اشتراک‌گذاری/ذخیره
- **مبدل واحدها**: گرم، سوت، مثقال صرافی، مثقال شرعی، نخود
- آفلاین کامل بعد از اولین اجرا، ارقام فارسی/انگلیسی، تم تیره/روشن/سیستمی، فونت وزیرمتن باندل‌شده

## سرور نرخ‌ها

خط تولید PHP + cron در `server/` (تست‌شده با ۱۵ تست) — راهنمای کامل استقرار و جدول تصمیم منبع: [server/README_SERVER.md](server/README_SERVER.md)

## فناوری‌ها

Vite + React 18 + TypeScript + CSS Variables (بدون Tailwind)، PWA (service worker دستی)، Capacitor 8 برای اندروید (minSdk 24)، ذخیره‌سازی Capacitor Preferences + localStorage، Vitest برای تست.

بدون وابستگی به سرویس‌های گوگل؛ همه فونت‌ها و آیکون‌ها باندل محلی هستند.

## راه‌اندازی توسعه

```bash
npm install
npm run dev        # http://localhost:5173
npm test           # ۳۴ تست Vitest (calc، numbers)
npm run build      # خروجی PWA در dist/
npm run preview    # پیش‌نمایش بیلد تولیدی
```

نیازمندی‌ها: Node 18+ (توسعه)، JDK 17+ (بیلد اندروید)، Android SDK.

## نرخ‌ها (PRICES_URL)

اپ یک فایل JSON ثابت را می‌خواند — `src/config/index.ts`:

```ts
export const MOCK_MODE = true;                  // تا وقتی endpoint واقعی نیست true بماند
export const PRICES_URL = '/prices.mock.json';  // برای انتشار واقعی آدرس endpoint خودت را بده
```

Schema:

```json
{
  "updated_at": "2026-01-01T12:00:00+03:30",
  "gold18": 0,
  "mazaneh": 0,
  "coins": { "emami": 0, "bahar": 0, "nim": 0, "rob": 0, "gerami": 0 }
}
```

رفتار: اول نرخ کش‌شده فوراً نمایش داده می‌شود، بعد در پس‌زمینه fetch با تایم‌اوت ۵ ثانیه؛ خطا کاملاً بی‌صدا نادیده گرفته می‌شود. نرخ دستی کاربر تا «موفقیت fetch بعدی» یا «بازگشت به نرخ خودکار» معتبر می‌ماند. اگر نرخ older از ۶ ساعت باشد با رنگ هشدار ملایم نشان داده می‌شود.

نمونه‌های سرور در پوشه `server/` (Cloudflare Worker + PHP) با محل مشخص `TODO` برای منطق دریافت نرخ واقعی.

## بیلد PWA

```bash
npm run build
```

خروجی در `dist/` — کل حجم JS حدود ۷۲KB gzip + فونت‌های woff2. service worker پس از اولین بازدید همه‌چیز را کش می‌کند (آفلاین کامل).

## بیلد در گیت‌هاب (بدون Android Studio)

روی هر پوش به `main`، ورک‌فلو `.github/workflows/android.yml` اجرا می‌شود:

1. تب **Actions** در ریپو → اجرای «Build Android APK»
2. بعد از سبز شدن، از بخش **Artifacts** دانلود کن:
   - `talam-apk` → شامل `app-debug.apk` (قابل نصب مستقیم روی گوشی) و `app-release-*.apk`
   - `talam-aab` → فایل AAB برای فروشگاه‌ها
3. اجرای دستی هم از دکمه **Run workflow** ممکن است.

امضای رلیز (اختیاری): این ۴ secret را در تنظیمات ریپو اضافه کن تا خروجی release با کلید خودت امضا شود:
`ANDROID_KEYSTORE_BASE64`، `ANDROID_KEYSTORE_PASSWORD`، `ANDROID_KEY_ALIAS`، `ANDROID_KEY_PASSWORD`.
تا وقتی تنظیم نشوند، release با debug-key امضا می‌شود (برای تست نصب مشکلی ندارد).

## بیلد APK و AAB با Capacitor

```bash
npm run cap:sync     # build + کپی web assets به android
npm run apk:debug    # APK دیباگ: android/app/build/outputs/apk/debug/app-debug.apk
npm run apk          # APK رلیز (امضاشده بعد از تنظیم signing)
npm run aab          # AAB برای کافه‌بازار/مایکت: android/app/build/outputs/bundle/release/
```

> ⚠️ **بیلد اندروید به JDK 21 نیاز دارد** (پلاگین‌های Capacitor 8 با toolchain 21 کامپایل می‌شوند).
> اگر JAVA_HOME قدیمی است، یا JDK 21 نصب کن یا مسیرش را در `android/gradle.properties` بده:
>
> ```properties
> org.gradle.java.home=C:\\Program Files\\Java\\jdk-21
> ```
>
> همچنین مسیر Android SDK باید در `android/local.properties` باشد:
>
> ```properties
> sdk.dir=C:/Users/<user>/AppData/Local/Android/Sdk
> ```

اندروید استودیو می‌تواند جایگزین کل این مسیر باشد: `npm run cap:open` و بعد Build از خود IDE.

### امضا (Signing)

1. ساخت keystore (یک بار، و **هرگز** کامیت نکن):

```bash
keytool -genkey -v -keystore talam-release.keystore -alias talam \
  -keyalg RSA -keysize 2048 -validity 10000
```

2. فایل `android/keystore.properties` بساز (در .gitignore است):

```properties
storeFile=../../talam-release.keystore
storePassword=****
keyAlias=talam
keyPassword=****
```

3. در `android/app/build.gradle` داخل `android { }` اضافه کن:

```groovy
def keystoreProperties = new Properties()
def keystorePropertiesFile = rootProject.file("keystore.properties")
if (keystorePropertiesFile.exists()) {
    keystoreProperties.load(new FileInputStream(keystorePropertiesFile))
}

signingConfigs {
    release {
        storeFile file(keystoreProperties['storeFile'])
        storePassword keystoreProperties['storePassword']
        keyAlias keystoreProperties['keyAlias']
        keyPassword keystoreProperties['keyPassword']
    }
}
buildTypes {
    release {
        signingConfig signingConfigs.release
    }
}
```

4. `npm run apk` یا `npm run aab`

## چک‌لیست انتشار کافه‌بازار / مایکت

- [ ] `MOCK_MODE=false` و `PRICES_URL` واقعی + تست endpoint
- [ ] مقادیر `COIN_SPECS` (وزن سکه‌ها) و `BUBBLE_THRESHOLDS` و `VERDICT_THRESHOLDS` بازبینی شود
- [ ] `appId` نهایی در `capacitor.config.ts` (پیش‌فرض `ir.talam.app`)
- [ ] APK/AAB امضاشده با keystore شخصی (keystore را نگه‌داری برای آپدیت‌ها!)
- [ ] آیکون ۵۱۲ و اسکرین‌شات‌های حداقل ۲ صفحه برای پنل فروشگاه
- [ ] متن معرفی + دسته‌بندی «ابزارها/مالی»
- [ ] تست نصب روی گوشی واقعی و بررسی دیپلینک امتیازدهی: `https://cafebazaar.ir/app/ir.talam.app?rate`
- [ ] سیاست حریم خصوصی (اپ هیچ داده‌ای جمع نمی‌کند و کاملاً آفلاین است)
- [ ] تبلیغات: `ADS_ENABLED` پیش‌فرض خاموش؛ فقط بنر کوچک پایین، هیچ اینترstitial

## مستندات

- [AUDIT.md](AUDIT.md) — بررسی فنی مستقل، یافته‌ها و اصلاحات
- [docs/BRIEF.md](docs/BRIEF.md) — بریف محصول
- [docs/TECH.md](docs/TECH.md) — معماری، فرمول‌ها، اتصال منبع نرخ
- [docs/MARKETING.md](docs/MARKETING.md) — کیت مارکتینگ و ASO
- [docs/PRIVACY.md](docs/PRIVACY.md) — سیاست حفظ حریم خصوصی
- [docs/CHANGELOG.md](docs/CHANGELOG.md) — تغییرات نسخه‌ها
- [docs/RELEASE_CHECKLIST.md](docs/RELEASE_CHECKLIST.md) — چک‌لیست انتشار

## ساختار پوشه

```
src/
  config/        # PRICES_URL، توکن‌های ثابت، آستانه‌ها، مشخصات سکه‌ها
  lib/
    calc.ts      # توابع محاسباتی خالص (کاملاً تست‌شده)
    numbers.ts   # نرمال‌سازی ارقام، قالب‌بندی، عدد به حروف فارسی
    prices.ts    # کش/fetch/اعتبارسنجی نرخ‌ها
    storage.ts   # Preferences + localStorage
    share.ts     # خروجی تصویر فاکتور و اشتراک‌گذاری
    ad/          # AdProvider (noop/tapsell/yektanet) — خاموش به‌صورت پیش‌فرض
  components/    # کامپوننت‌های UI
  screens/       # تب‌ها و فاکتور
  state/         # Context مرکزی اپ
  styles/        # توکن‌های طراحی و استایل
public/          # manifest، sw.js، prices.mock.json، آیکون‌ها
server/          # نمونه Cloudflare Worker و PHP
scripts/         # ساخت آیکون‌ها (بدون وابستگی)
android/         # پروژه Capacitor
```

## فرضیات و چیزهایی که قبل از انتشار باید چک شود

1. **فرمول‌ها**: اجرت/سود/مالیات مطابق spec (مالیات فقط بر اجرت+sود). مجموع اجزای گردشده = مبلغ نهایی.
2. **ضریب مظنه**: `4.3318 = 4.6083 × (705/750)` — مثقال صرافی ۴.۶۰۸۳ گرم، خلوص ۱۷ عیار ۷۰۵.
3. **وزن سکه‌ها**: امامی/بهار ۸.۱۳۳g، نیم ۴.۰۶۶۵g، ربع ۲.۰۳۳۲۵g، گرمی ۱.۰۱۶۶g — همه با خلوص ۹۰۰ (پیش از انتشار با استاندارد رسمی چک شود).
4. **آستانه‌ها**: حکم اجرت (۱۲٪/۲۰٪) و سطح حباب (۵٪/۱۵٪) در config قابل تغییرند؛ درصد پیش‌فرض مالیات ۱۰ (در طراحی «۹٪» دیده می‌شد — طبق spec مقدار پیش‌فرض ۱۰ گذاشته شد و از تنظیمات قابل تغییر است).
5. **جداکننده اعشار**: ویرگول `,` به‌عنوان جداکننده هزارگان تفسیر می‌شود (نه اعشار)؛ اعشار فقط `.` و `٫`.
6. **ذخیره در گالری**: در v1 از شیت اشتراک‌گذاری اندروید استفاده می‌شود (کاربر همان‌جا ذخیره در گالری را دارد). نوشتن مستقیم در MediaStore نیازمند پلااگین نیتیو اضافه است.
7. **آنس جهانی/دلار آزاد**: طبق spec نمایش داده نمی‌شود؛ schema نرخ‌ها فقط گرم ۱۸، مظنه و سکه‌هاست.
8. **JDK**: بیلد اندروید به JDK 21 نیاز دارد (روی این سیستم Java 15 بود؛ JDK 21 به‌صورت محلی در `tools/` دانلود و بیلد با آن انجام شد — `tools/` در .gitignore است).
