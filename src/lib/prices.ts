// لایه نرخ‌ها: کش، دریافت با تایم‌اوت ۵ ثانیه، اعتبارسنجی و زمان نسبی

import { FETCH_TIMEOUT_MS, PRICES_URL, PRICE_PLAUSIBILITY, STALE_WARNING_MS } from '../config';
import { toPersianDigits } from './numbers';
import { storageGetJSON, storageSetJSON } from './storage';

export interface CoinPrices {
  emami: number;
  bahar: number;
  nim: number;
  rob: number;
  gerami: number;
}

export interface PriceData {
  updated_at: string; // ISO
  gold18: number; // تومان بر گرم
  mazaneh: number; // تومان بر مثقال ۱۷ عیار
  coins: CoinPrices; // قیمت بازار سکه‌ها
  stale?: boolean; // true = سرور نتوانسته تازه‌سازی کند و last good را می‌فرستد
  source?: string; // شناسه کوتاه منبع (مثلاً 'manual' یا 'ezrate')
  source_label?: string; // نام نمایشی پروایدر (مثلاً 'ایزی‌ریت (EZRate)')
}

export const PRICE_KEYS = ['emami', 'bahar', 'nim', 'rob', 'gerami'] as const;

export const PRICES_STORAGE_KEY = 'prices';
export const OVERRIDE_STORAGE_KEY = 'priceOverride';

/** اعتبارسنجی و نرمال‌سازی داده نرخ؛ ساختار نامعتبر → null (هرگز NaN از این تابع بیرون نمی‌رود) */
export function validatePriceData(x: unknown): PriceData | null {
  if (!x || typeof x !== 'object') return null;
  const o = x as Record<string, unknown>;
  const gold18 = Number(o.gold18);
  const mazaneh = Number(o.mazaneh);
  // مرزهای منطقی — نرخ خراب از منبع نباید به UI برسد
  if (!Number.isFinite(gold18) || gold18 < PRICE_PLAUSIBILITY.gold18Min || gold18 > PRICE_PLAUSIBILITY.gold18Max) {
    return null;
  }
  const coinsSrc = (o.coins ?? {}) as Record<string, unknown>;
  const coins = {} as CoinPrices;
  for (const k of PRICE_KEYS) {
    const v = Number(coinsSrc[k]);
    // سکه نامعتبر یا غیرمنطقی (بیش از سقف نسبت به گرم ۱۸) → صفر = «دستی وارد کن»
    coins[k] =
      Number.isFinite(v) && v > 0 && v <= gold18 * PRICE_PLAUSIBILITY.coinMaxGold18Ratio ? Math.round(v) : 0;
  }
  // نسبت مظنه باید نزدیک ۴.۳۳ باشد؛ وگرنه از روی gold18 محاسبه می‌شود
  let mazanehOut: number;
  if (Number.isFinite(mazaneh) && mazaneh > 0) {
    const ratio = mazaneh / gold18;
    mazanehOut =
      ratio >= PRICE_PLAUSIBILITY.mazanehRatioMin && ratio <= PRICE_PLAUSIBILITY.mazanehRatioMax
        ? Math.round(mazaneh)
        : Math.round(gold18 * 4.3318);
  } else {
    mazanehOut = Math.round(gold18 * 4.3318);
  }
  return {
    updated_at: typeof o.updated_at === 'string' ? o.updated_at : new Date().toISOString(),
    gold18: Math.round(gold18),
    mazaneh: mazanehOut,
    coins,
    stale: o.stale === true,
    source: typeof o.source === 'string' && o.source !== '' ? o.source : undefined,
    source_label: typeof o.source_label === 'string' && o.source_label !== '' ? o.source_label : undefined,
  };
}

/** خواندن آخرین نرخ‌های ذخیره‌شده */
export async function loadCachedPrices(): Promise<PriceData | null> {
  const data = await storageGetJSON<PriceData>(PRICES_STORAGE_KEY);
  return data ? validatePriceData(data) : null;
}

export async function savePrices(data: PriceData): Promise<void> {
  await storageSetJSON(PRICES_STORAGE_KEY, data);
}

/** دریافت یک آدرس نرخ با تایم‌اوت */
async function fetchSinglePriceUrl(url: string, timeoutMs: number): Promise<PriceData> {
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), timeoutMs);
  try {
    const res = await fetch(url, { signal: ctrl.signal, cache: 'no-store' });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const json: unknown = await res.json();
    const data = validatePriceData(json);
    if (!data) throw new Error('invalid price data');
    return data;
  } finally {
    clearTimeout(timer);
  }
}

/** دریافت نرخ‌ها با تایم‌اوت؛ در حالت پیش‌فرض مسیرهای جایگزین زیرپوشه هم بررسی می‌شوند */
export async function fetchPrices(url: string = PRICES_URL, timeoutMs = FETCH_TIMEOUT_MS): Promise<PriceData> {
  if (url !== PRICES_URL) {
    return fetchSinglePriceUrl(url, timeoutMs);
  }
  const candidates = [
    url,
    './api/public/prices.json',
    './server/public/prices.json',
    './prices.json',
  ];
  let lastErr: unknown;
  for (const candidate of candidates) {
    try {
      const sep = candidate.includes('?') ? '&' : '?';
      return await fetchSinglePriceUrl(`${candidate}${sep}t=${Date.now()}`, timeoutMs);
    } catch (e) {
      lastErr = e;
    }
  }
  throw lastErr instanceof Error ? lastErr : new Error('fetch failed');
}

/** زمان نسبی فارسی: «۱۰ دقیقه پیش» + پرچم قدیمی‌بودن (>۶ ساعت) */
export function relativeTime(iso: string, now = Date.now()): { text: string; stale: boolean } {
  const t = new Date(iso).getTime();
  if (!Number.isFinite(t)) return { text: 'نامشخص', stale: true };
  const diffMin = Math.max(0, Math.floor((now - t) / 60000));
  let text: string;
  if (diffMin < 1) text = 'همین حالا';
  else if (diffMin < 60) text = `${toPersianDigits(String(diffMin))} دقیقه پیش`;
  else if (diffMin < 1440) text = `${toPersianDigits(String(Math.floor(diffMin / 60)))} ساعت پیش`;
  else text = `${toPersianDigits(String(Math.floor(diffMin / 1440)))} روز پیش`;
  return { text, stale: now - t > STALE_WARNING_MS };
}
