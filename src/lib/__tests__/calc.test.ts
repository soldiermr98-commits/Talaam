import { describe, expect, it } from 'vitest';
import {
  calcCoinBubble,
  calcNewGold,
  calcReverse,
  calcSecondHand,
  convertUnits,
  mazanehToGram18,
  pricePerGramForKarat,
} from '../calc';
import { COIN_SPECS } from '../../config';

describe('calcNewGold — طلای نو', () => {
  it('مثال spec: وزن ۴.۲۵، نرخ ۸ میلیون، اجرت ۱۵٪، سود ۷٪، مالیات ۱۰٪', () => {
    const res = calcNewGold({
      weight: 4.25,
      gold18: 8_000_000,
      makingMode: 'percent',
      makingPercent: 15,
      profitPct: 7,
      taxPct: 10,
    });
    expect(res.raw).toBe(34_000_000);
    expect(res.making).toBe(5_100_000);
    expect(res.profit).toBe(2_737_000);
    expect(res.tax).toBe(783_700);
    expect(res.total).toBe(42_620_700);
  });

  it('حالت اجرت ثابت (تومان بر گرم)', () => {
    const res = calcNewGold({
      weight: 3,
      gold18: 1_000_000,
      makingMode: 'fixed',
      makingFixedPerGram: 200_000,
      profitPct: 7,
      taxPct: 10,
    });
    expect(res.raw).toBe(3_000_000);
    expect(res.making).toBe(600_000);
    expect(res.profit).toBe(252_000);
    expect(res.tax).toBe(85_200);
    expect(res.total).toBe(3_937_200);
  });

  it('وزن صفر → همه صفر', () => {
    const res = calcNewGold({ weight: 0, gold18: 8_000_000, makingMode: 'percent', makingPercent: 15, profitPct: 7, taxPct: 10 });
    expect(res.total).toBe(0);
  });
});

describe('calcSecondHand — دست دوم و آب‌شده', () => {
  it('نرخ هر گرم بر اساس عیار: ۲۴ عیار', () => {
    expect(pricePerGramForKarat(8_000_000, 24)).toBeCloseTo(10_666_666.67, 0);
    expect(pricePerGramForKarat(8_000_000, 14)).toBeCloseTo(6_222_222.22, 0);
  });

  it('خرید از طلافروش (خام + حاشیه)', () => {
    const res = calcSecondHand({ weight: 1, gold18: 8_000_000, karat: 24, mode: 'buy', marginPct: 5 });
    expect(res.pricePerGram).toBe(10_666_667);
    expect(res.raw).toBe(10_666_667);
    expect(res.margin).toBe(533_333);
    expect(res.total).toBe(11_200_000);
  });

  it('فروش به طلافروش (خام − حاشیه)', () => {
    const res = calcSecondHand({ weight: 1, gold18: 8_000_000, karat: 24, mode: 'sell', marginPct: 5 });
    expect(res.total).toBe(10_133_334);
  });
});

describe('mazanehToGram18 — تبدیل مظنه', () => {
  it('مظنه ۳۴ میلیون ≈ ۷٬۸۴۹٬۰۰۰ برای هر گرم ۱۸ عیار', () => {
    const perGram = mazanehToGram18(34_000_000);
    expect(Math.round(perGram / 1000) * 1000).toBe(7_849_000);
  });
});

describe('calcReverse — چند درصد حساب کرده؟', () => {
  it('مثال spec: وزن ۴.۲۵، نرخ ۸ میلیون، مبلغ ۴۲٬۶۲۰٬۷۰۰ → اجرت ۱۵٪', () => {
    const res = calcReverse({ weight: 4.25, gold18: 8_000_000, finalPrice: 42_620_700, profitPct: 7, taxPct: 10 });
    expect(res.making).toBe(5_100_000);
    expect(res.makingPct).not.toBeNull();
    expect(Math.abs((res.makingPct ?? 0) - 15)).toBeLessThan(0.01);
    expect(res.verdict).toBe('slightly-high'); // بین ۱۲ تا ۲۰
    expect(res.premiumPct).toBeGreaterThan(0);
  });

  it('حکم منصفانه زیر ۱۲٪، کمی گران ۱۲–۲۰٪ و گران بالای ۲۰٪', () => {
    // اجرت ضمنی ≈ ۲٪ → منصفانه
    const fair = calcReverse({ weight: 1, gold18: 1_000_000, finalPrice: 1_100_000, profitPct: 7, taxPct: 10 });
    expect(fair.verdict).toBe('fair');
    // اجرت ضمنی ≈ ۱۵٪ → کمی گران
    const mid = calcReverse({ weight: 1, gold18: 1_000_000, finalPrice: 1_253_550, profitPct: 7, taxPct: 10 });
    expect(mid.verdict).toBe('slightly-high');
    // اجرت ضمنی ≈ ۲۵٪ → گران
    const high = calcReverse({ weight: 1, gold18: 1_000_000, finalPrice: 1_371_250, profitPct: 7, taxPct: 10 });
    expect(high.verdict).toBe('expensive');
  });

  it('مبلغ کمتر از ارزش خام → below-raw', () => {
    const res = calcReverse({ weight: 1, gold18: 1_000_000, finalPrice: 900_000, profitPct: 7, taxPct: 10 });
    expect(res.verdict).toBe('below-raw');
    expect(res.making).toBeNull();
  });
});

describe('calcCoinBubble — حباب سکه', () => {
  const { weight, purity } = COIN_SPECS.emami;

  it('ارزش ذاتی و حباب با نرخ ۸ میلیون', () => {
    const res = calcCoinBubble({ marketPrice: 88_000_000, gold18: 8_000_000, weight, purity });
    // 8.133 × 0.9 ÷ 0.75 × 8,000,000 = 78,076,800
    expect(res.intrinsic).toBe(78_076_800);
    expect(res.bubble).toBe(9_923_200);
    expect(res.level).toBe('medium');
  });

  it('حباب بالای ۱۵٪ → سطح زیاد', () => {
    const res = calcCoinBubble({ marketPrice: 100_000_000, gold18: 8_000_000, weight, purity });
    expect(res.bubblePct).toBeGreaterThan(15);
    expect(res.level).toBe('high');
  });

  it('حباب کم از ۵٪ → سطح کم', () => {
    const res = calcCoinBubble({ marketPrice: 78_500_000, gold18: 8_000_000, weight, purity });
    expect(res.level).toBe('low');
  });
});

describe('convertUnits — مبدل واحدها', () => {
  it('هر گرم = ۱۰۰ سوت', () => {
    const out = convertUnits(1, 'gram');
    expect(out['soot']).toBeCloseTo(100, 6);
  });

  it('مثقال صرافی ≈ ۲۴ نخود', () => {
    const out = convertUnits(1, 'mesghal-sarrafi');
    expect(out['nokhod']).toBeCloseTo(24.0016, 3);
  });

  it('نرخ مثقال صرافی بر حسب گرم', () => {
    const out = convertUnits(1, 'mesghal-sarrafi');
    expect(out['gram']).toBeCloseTo(4.6083, 6);
  });

  it('مثقال شرعی', () => {
    const out = convertUnits(1, 'mesghal-sharii');
    expect(out['gram']).toBeCloseTo(3.456, 6);
  });
});
