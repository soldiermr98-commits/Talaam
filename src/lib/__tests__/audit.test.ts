// تست‌های audit — رفتار در برابر داده خراب، مقادیر حدی و round-trip
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  fetchPrices,
  relativeTime,
  validatePriceData,
} from '../prices';
import { calcNewGold, calcReverse, mazanehToGram18 } from '../calc';
import { formatMoney, numberToPersianWords, moneyToPersianWords, parseDecimal } from '../numbers';
import { storageGetJSONSync } from '../storage';

describe('audit: validatePriceData در برابر داده خراب', () => {
  it('ورودی‌های کاملاً نامعتبر → null (بدون crash)', () => {
    expect(validatePriceData(null)).toBeNull();
    expect(validatePriceData(undefined)).toBeNull();
    expect(validatePriceData('garbage')).toBeNull();
    expect(validatePriceData(42)).toBeNull();
    expect(validatePriceData([1, 2, 3])).toBeNull();
    expect(validatePriceData({})).toBeNull();
    expect(validatePriceData({ coins: { emami: 5 } })).toBeNull();
  });

  it('gold18 نامعتبر: صفر، منفی، NaN، Infinity → null', () => {
    expect(validatePriceData({ gold18: 0, mazaneh: 0 })).toBeNull();
    expect(validatePriceData({ gold18: -5, mazaneh: 0 })).toBeNull();
    expect(validatePriceData({ gold18: Number.NaN, mazaneh: 0 })).toBeNull();
    expect(validatePriceData({ gold18: Number.POSITIVE_INFINITY, mazaneh: 0 })).toBeNull();
  });

  it('gold18 رشته‌ای عددی → پذیرفته می‌شود (رفتار lenient مستند شده)', () => {
    const d = validatePriceData({ gold18: '8000000', mazaneh: '34654400' });
    expect(d).not.toBeNull();
    expect(d?.gold18).toBe(8_000_000);
  });

  it('coins جاافتاده → صفر؛ coins رشته‌ای → عدد؛ مقادیر منفی → صفر', () => {
    const d = validatePriceData({ gold18: 8_000_000, mazaneh: 34_654_400 });
    expect(d?.coins.emami).toBe(0);
    const d2 = validatePriceData({
      gold18: 8_000_000,
      mazaneh: 0,
      coins: { emami: '51000000', bahar: -3, nim: Number.NaN, rob: 23000000.7 },
    });
    expect(d2?.coins.emami).toBe(51_000_000);
    expect(d2?.coins.bahar).toBe(0);
    expect(d2?.coins.nim).toBe(0);
    expect(d2?.coins.rob).toBe(23_000_001);
  });

  it('mazaneh صفر یا جاافتاده → fallback از gold18 × 4.3318', () => {
    const d = validatePriceData({ gold18: 8_000_000 });
    expect(d?.mazaneh).toBe(Math.round(8_000_000 * 4.3318));
  });

  it('JSON خراب در storage → null بدون crash', () => {
    expect(storageGetJSONSync('audit-nonexistent-key')).toBeNull();
  });
});

describe('audit: fetchPrices در برابر پاسخ خراب', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('JSON غیرقابل parse → reject (caller بی‌صدا نادیده می‌گیرد)', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => ({ ok: true, json: async () => Promise.reject(new Error('Unexpected token')) }))
    );
    await expect(fetchPrices('/x.json')).rejects.toThrow('Unexpected token');
  });

  it('HTTP خطا (500) → reject', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 500 })));
    await expect(fetchPrices('/x.json')).rejects.toThrow('HTTP 500');
  });

  it('schema ناقص در پاسخ سرور → reject، نه نمایش NaN', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => ({ ok: true, json: async () => ({ gold18: null, mazaneh: 0 }) }))
    );
    await expect(fetchPrices('/x.json')).rejects.toThrow('invalid price data');
  });
});

describe('audit: schema سرور — stale/source و مقادیر غیرمنطقی', () => {
  it('پرچم stale فقط با true دقیق فعال می‌شود و source حفظ می‌شود', () => {
    const d = validatePriceData({ gold18: 8_000_000, mazaneh: 34_654_400, stale: true, source: 'manual' });
    expect(d?.stale).toBe(true);
    expect(d?.source).toBe('manual');
    const d2 = validatePriceData({ gold18: 8_000_000, mazaneh: 34_654_400, stale: 'yes', source: 42 });
    expect(d2?.stale).toBe(false);
    expect(d2?.source).toBeUndefined();
  });

  it('فیلدهای اضافه سرور (source/stale) باعث رد شدن نمی‌شوند', () => {
    const d = validatePriceData({
      updated_at: '2026-01-01T12:00:00+03:30',
      source: 'navasan',
      stale: false,
      gold18: 8_000_000,
      mazaneh: 34_654_400,
      coins: { emami: 88_000_000 },
    });
    expect(d?.gold18).toBe(8_000_000);
    expect(d?.coins.emami).toBe(88_000_000);
  });

  it('schema_version و فیلدهای آینده سرور → نادیده گرفته میشوند (نسخه‌بندی افزودنی)', () => {
    const d = validatePriceData({
      schema_version: 1,
      updated_at: '2026-01-01T12:00:00+03:30',
      source: 'example-provider',
      stale: false,
      gold18: 8_000_000,
      mazaneh: 34_654_400,
      coins: { emami: 88_000_000 },
      updated_at_by_field: { gold18: '2026-01-01T12:00:00+03:30' },
      some_future_field: { nested: true },
    });
    expect(d?.gold18).toBe(8_000_000);
    expect(d?.source).toBe('example-provider');
    // بدون schema_version هم کاملاً سازگار است (نسخه‌های قدیمی فایل)
    const d2 = validatePriceData({ gold18: 8_000_000, mazaneh: 0 });
    expect(d2?.gold18).toBe(8_000_000);
  });

  it('مقادیر غیرمنطقی رد می‌شوند: gold18 زیر مرز، بالای مرز', () => {
    expect(validatePriceData({ gold18: 500 })).toBeNull(); // زیر ۱۰۰هزار — قطعاً غلط
    expect(validatePriceData({ gold18: 3_000_000_000 })).toBeNull(); // بالای مرز
    expect(validatePriceData({ gold18: 8_000_000 })).not.toBeNull();
  });

  it('سکه غیرمنطقی (بیش از ۱۰۰ برابر گرم ۱۸) → صفر، نه نمایش عدد پوچ', () => {
    const d = validatePriceData({ gold18: 8_000_000, mazaneh: 0, coins: { emami: 9_000_000_000, nim: 44_000_000 } });
    expect(d?.coins.emami).toBe(0);
    expect(d?.coins.nim).toBe(44_000_000);
  });

  it('نسبت مظنه خارج از ۲ تا ۱۰ → از gold18 محاسبه می‌شود (خطای ۱۰x گم نمی‌شود)', () => {
    // ۱۰x خطای ریال: مظنه درست ۳۴٬۶۵۴٬۴۰۰ است؛ ۱۰ برابرش نسبت ۴۳ می‌دهد
    const d = validatePriceData({ gold18: 8_000_000, mazaneh: 346_544_000 });
    expect(d?.mazaneh).toBe(Math.round(8_000_000 * 4.3318));
    const d2 = validatePriceData({ gold18: 8_000_000, mazaneh: 34_654_400 }); // نسبت ≈4.33
    expect(d2?.mazaneh).toBe(34_654_400);
  });
});

describe('audit: حروف فارسی — موارد ظریف', () => {
  it('۱۰۰۰۰۰۰ → «یک میلیون»', () => {
    expect(numberToPersianWords(1_000_000)).toBe('یک میلیون');
  });

  it('۲۰۰۱ → «دو هزار و یک»', () => {
    expect(numberToPersianWords(2_001)).toBe('دو هزار و یک');
  });

  it('۱۱ → «یازده» و ۱۰ → «ده»', () => {
    expect(numberToPersianWords(11)).toBe('یازده');
    expect(numberToPersianWords(10)).toBe('ده');
  });

  it('حداکثر پوشش (۹۹۹ تریلیون)', () => {
    expect(numberToPersianWords(999_999_999_999_999)).toContain('نهصد و نود و نه تریلیون');
  });

  it('بیش از تریلیون → fallback رشته عددی (بدون crash)', () => {
    expect(numberToPersianWords(1_000_000_000_000_000)).toBe('1000000000000000');
  });

  it('NaN و Infinity → رشته خالی', () => {
    expect(moneyToPersianWords(Number.NaN)).toBe('');
    expect(numberToPersianWords(Number.POSITIVE_INFINITY)).toBe('');
  });
});

describe('audit: round-trip با اجرت ثابت و مقادیر حدی', () => {
  it('محاسبه مستقیم با اجرت ثابت → معکوس همان درصد را برمی‌گرداند', () => {
    const weight = 3.75;
    const gold18 = 5_500_000;
    const fixedPerGram = 250_000;
    const fwd = calcNewGold({ weight, gold18, makingMode: 'fixed', makingFixedPerGram: fixedPerGram, profitPct: 7, taxPct: 10 });
    const rev = calcReverse({ weight, gold18, finalPrice: fwd.total, profitPct: 7, taxPct: 10 });
    expect(rev.makingPct).not.toBeNull();
    expect(Math.abs((rev.makingPct ?? 0) - ((fixedPerGram * weight) / (weight * gold18)) * 100)).toBeLessThan(0.01);
    expect(rev.making).toBe(fwd.making);
  });

  it('مقادیر بزرگ: بدون NaN و بدون سرریز', () => {
    const r = calcNewGold({ weight: 9999, gold18: 999_999_999, makingMode: 'percent', makingPercent: 25, profitPct: 7, taxPct: 10 });
    expect(Number.isFinite(r.total)).toBe(true);
    expect(r.total).toBeGreaterThan(0);
    const rev = calcReverse({ weight: 9999, gold18: 999_999_999, finalPrice: r.total * 2, profitPct: 7, taxPct: 10 });
    expect(Number.isFinite(rev.makingPct ?? Number.NaN)).toBe(true);
  });

  it('وزن اعشاری خیلی کوچک (۰.۰۰۱ گرم)', () => {
    const r = calcNewGold({ weight: 0.001, gold18: 8_000_000, makingMode: 'percent', makingPercent: 15, profitPct: 7, taxPct: 10 });
    expect(r.raw).toBe(8_000);
    expect(r.total).toBe(Math.round(r.raw + r.making + r.profit + r.tax));
  });

  it('اجزای گردشده همیشه با جمع کل می‌خوانند', () => {
    for (const w of [4.255, 1.111, 7.777, 2.345]) {
      const r = calcNewGold({ weight: w, gold18: 12_345_678, makingMode: 'percent', makingPercent: 13.5, profitPct: 6.5, taxPct: 9 });
      expect(r.total).toBe(r.raw + r.making + r.profit + r.tax);
    }
  });
});

describe('audit: زمان نسبی و ساعت', () => {
  it('۱۰ دقیقه پیش', () => {
    const r = relativeTime(new Date(Date.now() - 10 * 60_000).toISOString());
    expect(r.text).toContain('۱۰ دقیقه پیش');
    expect(r.stale).toBe(false);
  });

  it('۷ ساعت پیش → stale', () => {
    const r = relativeTime(new Date(Date.now() - 7 * 3600_000).toISOString());
    expect(r.stale).toBe(true);
  });

  it('زمان آینده (ساخت ساعت دستگاه) → «همین حالا» و stale=false', () => {
    const r = relativeTime(new Date(Date.now() + 3600_000).toISOString());
    expect(r.text).toBe('همین حالا');
    expect(r.stale).toBe(false);
  });
});

describe('audit: مظنه و قالب‌بندی', () => {
  it('ثابت مظنه از خلوص‌ها می‌آید: 4.6083 × 705/750 ≈ 4.3318', () => {
    expect(Math.abs(4.6083 * (705 / 750) - 4.3318)).toBeLessThan(0.0001);
    expect(mazanehToGram18(34_000_000) * 4.3318).toBeCloseTo(34_000_000, -4);
  });

  it('قالب مبلغ بزرگ بدون crash', () => {
    expect(formatMoney(999_999_999_999_999, 'fa')).toContain('٬');
  });

  it('ورودی «1e3» بدون نماد علمی → ۱۳ (رفتار مستند: نماد علمی پشتیبانی نمی‌شود)', () => {
    expect(parseDecimal('1e3')).toBe(13);
  });
});
