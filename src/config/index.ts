// پیکربندی مرکزی اپ — پیش از انتشار مقادیر را بررسی کن

export const APP_VERSION = '1.0.0';
export const APP_ID = 'talam.app';

/**
 * Mock فقط برای `npm run dev` است. بیلد تولیدی (release) هرگز از mock استفاده نمی‌کند:
 * اگر کش و شبکه هم نباشد، اپ حالت «نرخ را دستی وارد کن» را نشان می‌دهد.
 */
export const MOCK_MODE: boolean = import.meta.env.DEV === true;

/** آدرس فایل نرخ — متصل به هاست اختصاصی طلام */
const ENV_PRICES_URL = (import.meta.env.VITE_PRICES_URL as string | undefined) ?? '';
export const PRICES_URL: string =
  ENV_PRICES_URL !== ''
    ? ENV_PRICES_URL
    : MOCK_MODE
      ? '/prices.mock.json'
      : 'https://hojre24shop.ir/Talaam/api/public/prices.json';

export const FETCH_TIMEOUT_MS = 5_000; // تایم‌اوت دریافت نرخ‌ها
export const STALE_WARNING_MS = 6 * 60 * 60 * 1000; // بعد از ۶ ساعت نرخ «قدیمی» تلقی می‌شود

export interface Settings {
  theme: 'system' | 'dark' | 'light';
  digits: 'fa' | 'en';
  taxPct: number;
  profitPct: number;
  secondHandMarginPct: number;
}

export const DEFAULT_SETTINGS: Settings = {
  theme: 'system',
  digits: 'fa',
  taxPct: 10,
  profitPct: 7,
  secondHandMarginPct: 5,
};

/** آستانه‌های حکم «چند درصد حساب کرده؟» (درصد اجرت ضمنی) */
export const VERDICT_THRESHOLDS = { fairMax: 12, expensiveMax: 20 };

/** آستانه‌های سطح حباب سکه (درصد) */
export const BUBBLE_THRESHOLDS = { mediumMin: 5, highMin: 15 };

/** گزینه‌های سریع اجرت ساخت */
export const MAKING_QUICK_CHIPS = [7, 12, 14, 18];
export const KARAT_OPTIONS = [18, 14, 21, 22, 24];

/** مشخصات سکه‌ها — پیش از انتشار با استانداردهای رسمی چک شود */
export const COIN_SPECS = {
  emami: { key: 'emami', label: 'سکه امامی', weight: 8.133, purity: 0.9 },
  bahar: { key: 'bahar', label: 'بهار آزادی', weight: 8.133, purity: 0.9 },
  nim: { key: 'nim', label: 'نیم سکه', weight: 4.0665, purity: 0.9 },
  rob: { key: 'rob', label: 'ربع سکه', weight: 2.03325, purity: 0.9 },
  gerami: { key: 'gerami', label: 'سکه گرمی', weight: 1.0166, purity: 0.9 },
} as const;

/** حداکثر رقم اعشار وزن */
export const MAX_WEIGHT_DECIMALS = 3;

/** مرزهای منطقی نرخ (تومان) — مقادیر خارج از این بازه از منبع رد می‌شوند */
export const PRICE_PLAUSIBILITY = {
  gold18Min: 100_000, // زیر ۱۰۰ هزار تومان برای گرم ۱۸ عیار قطعاً غلط است
  gold18Max: 2_000_000_000,
  mazanehRatioMin: 2, // نسبت mazaneh/gold18 معمولاً ≈ 4.33
  mazanehRatioMax: 10,
  coinMaxGold18Ratio: 100, // هر سکه حداکثر ۱۰۰ برابر نرخ گرم ۱۸
};

// ───────── تبلیغات (پیش‌فرض خاموش) ─────────
export const ADS_ENABLED = false;
export const AD_PROVIDER: 'tapsell' | 'yektanet' = 'tapsell';
export const AD_BANNER_ID = 'TODO-banner-id'; // شناسه بنر واقعی اینجا قرار بگیرد

// ───────── لینک‌های فروشگاه‌ها ─────────
export const BAZAAR_APP_URL = `https://cafebazaar.ir/app/${APP_ID}`;
export const BAZAAR_RATE_URL = `https://cafebazaar.ir/app/${APP_ID}?rate`;
export const MYKET_APP_URL = `https://myket.ir/app/${APP_ID}`;
export const SHARE_APP_TEXT = `محاسبه‌گر طلا «طلام» — ساده، سریع و آفلاین:\n${BAZAAR_APP_URL}`;
