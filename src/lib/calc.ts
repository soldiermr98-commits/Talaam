// توابع محاسباتی خالص — همه مبالغ تومان و در انتها گرد شده به عدد صحیح
// این فایل کاملاً پوشش تست دارد؛ اعداد مثال‌های تست از spec می‌آیند.

export const MESQAL_SARRAFI_GRAMS = 4.6083; // مثقال صرافی (۲۴ نخود)
export const MESQAL_SHARII_GRAMS = 3.456; // مثقال شرعی
export const NOKHOD_GRAMS = 0.192; // نخود
export const K17_PURITY = 705 / 1000; // خلوص ۱۷ عیار تهران
export const K18_PURITY = 750 / 1000; // خلوص ۱۸ عیار
/** هر مثقال صرافی معادل چند گرم طلای ۱۸ عیار است */
export const MAZANEH_TO_GRAM18 = 4.3318; // 4.6083 × (705/750)

export type CoinKey = 'emami' | 'bahar' | 'nim' | 'rob' | 'gerami';
export type MakingMode = 'percent' | 'fixed';
export type SecondHandMode = 'buy' | 'sell';

const r = (x: number) => Math.round(x);

// ───────────────────────── طلای نو ─────────────────────────

export interface NewGoldInput {
  weight: number; // گرم
  gold18: number; // تومان بر گرم ۱۸ عیار
  makingMode: MakingMode;
  makingPercent?: number; // حالت درصدی
  makingFixedPerGram?: number; // حالت مبلغ ثابت به ازای هر گرم
  profitPct: number; // سود طلافروش
  taxPct: number; // مالیات بر ارزش افزوده — فقط بر اجرت و سود
}

export interface NewGoldResult {
  raw: number; // ارزش طلای خام
  making: number; // اجرت ساخت
  profit: number; // سود طلافروش
  tax: number; // مالیات
  total: number; // مبلغ نهایی
}

export function calcNewGold(input: NewGoldInput): NewGoldResult {
  const { weight, gold18, profitPct, taxPct } = input;
  const raw = weight * gold18;
  const making =
    input.makingMode === 'percent'
      ? (raw * (input.makingPercent ?? 0)) / 100
      : weight * (input.makingFixedPerGram ?? 0);
  const profit = ((raw + making) * profitPct) / 100;
  const tax = ((making + profit) * taxPct) / 100;
  const rawR = r(raw);
  const makingR = r(making);
  const profitR = r(profit);
  const taxR = r(tax);
  return { raw: rawR, making: makingR, profit: profitR, tax: taxR, total: rawR + makingR + profitR + taxR };
}

// ───────────────────────── دست دوم و آب‌شده ─────────────────────────

export interface SecondHandInput {
  weight: number;
  gold18: number;
  karat: number;
  mode: SecondHandMode; // خرید از طلافروش (raw+margin) یا فروش به طلافروش (raw-margin)
  marginPct: number; // حاشیه سود / پورسانت
}

export interface SecondHandResult {
  pricePerGram: number; // نرخ هر گرم عیار k
  raw: number;
  margin: number;
  total: number;
}

export function pricePerGramForKarat(gold18: number, karat: number): number {
  return (gold18 * karat) / 18;
}

export function calcSecondHand(input: SecondHandInput): SecondHandResult {
  const pricePerGram = pricePerGramForKarat(input.gold18, input.karat);
  const raw = input.weight * pricePerGram;
  const margin = (raw * input.marginPct) / 100;
  const rawR = r(raw);
  const marginR = r(margin);
  const total = input.mode === 'buy' ? rawR + marginR : rawR - marginR;
  return { pricePerGram: r(pricePerGram), raw: rawR, margin: marginR, total };
}

/** تبدیل مظنه (تومان بر مثقال ۱۷ عیار) به نرخ هر گرم ۱۸ عیار */
export function mazanehToGram18(mazaneh: number): number {
  return mazaneh / MAZANEH_TO_GRAM18;
}

// ───────────────────────── چند درصد حساب کرده؟ ─────────────────────────

export type Verdict = 'fair' | 'slightly-high' | 'expensive' | 'below-raw';

export interface ReverseInput {
  weight: number;
  gold18: number;
  finalPrice: number; // مبلغ نهایی اعلامی فروشنده
  profitPct: number;
  taxPct: number;
  fairMaxPct?: number;
  expensiveMaxPct?: number;
}

export interface ReverseResult {
  raw: number; // ارزش خام طلا
  making: number | null; // اجرت ضمنی (تومان)
  makingPct: number | null; // اجرت ضمنی (درصد)
  premiumPct: number; // اضافه روی قیمت خام طلا — بدون هیچ فرضی
  extraPaid: number; // مبلغ اضافه پرداختی
  verdict: Verdict;
}

export function calcReverse(input: ReverseInput): ReverseResult {
  const R = input.weight * input.gold18;
  const P = input.finalPrice;
  const p = input.profitPct / 100;
  const t = input.taxPct / 100;
  const premiumPct = R > 0 ? ((P - R) / R) * 100 : 0;
  const extraPaid = P - R;
  if (R <= 0 || P < R) {
    return { raw: r(R), making: null, makingPct: null, premiumPct, extraPaid, verdict: 'below-raw' };
  }
  // P = R(1 + p + t·p) + M(1 + p + t + t·p)  →  استخراج M
  const denom = 1 + p + t * p + t;
  const M = (P - R * (1 + p + t * p)) / denom;
  const makingPct = R > 0 ? (M / R) * 100 : 0;
  const fairMax = input.fairMaxPct ?? 12;
  const expensiveMax = input.expensiveMaxPct ?? 20;
  let verdict: Verdict = 'fair';
  if (makingPct > expensiveMax) verdict = 'expensive';
  else if (makingPct > fairMax) verdict = 'slightly-high';
  return { raw: r(R), making: r(M), makingPct, premiumPct, extraPaid, verdict };
}

// ───────────────────────── حباب سکه ─────────────────────────

export interface CoinSpec {
  key: CoinKey;
  label: string;
  weight: number; // گرم
  purity: number; // خلوص (۰ تا ۱)
}

export interface CoinBubbleInput {
  marketPrice: number; // قیمت بازار سکه
  gold18: number;
  weight: number;
  purity: number;
}

export interface CoinBubbleResult {
  pureGold: number; // وزن طلای خالص
  intrinsic: number; // ارزش ذاتی
  bubble: number; // مبلغ حباب
  bubblePct: number; // درصد حباب
  level: 'low' | 'medium' | 'high';
}

export interface BubbleThresholds {
  mediumMin: number; // بالاتر از این درصد = متوسط
  highMin: number; // بالاتر از این درصد = زیاد
}

export function calcCoinBubble(
  input: CoinBubbleInput,
  thresholds: BubbleThresholds = { mediumMin: 5, highMin: 15 }
): CoinBubbleResult {
  const pureGold = input.weight * input.purity;
  const intrinsic = (pureGold / 0.75) * input.gold18;
  const bubble = input.marketPrice - intrinsic;
  const bubblePct = intrinsic > 0 ? (bubble / intrinsic) * 100 : 0;
  let level: CoinBubbleResult['level'] = 'low';
  if (bubblePct > thresholds.highMin) level = 'high';
  else if (bubblePct > thresholds.mediumMin) level = 'medium';
  return { pureGold, intrinsic: r(intrinsic), bubble: r(bubble), bubblePct, level };
}

// ───────────────────────── مبدل واحدها ─────────────────────────

export interface UnitDef {
  key: string;
  label: string;
  grams: number;
  hint?: string;
}

export const UNITS: UnitDef[] = [
  { key: 'gram', label: 'گرم', grams: 1 },
  { key: 'soot', label: 'سوت', grams: 0.01, hint: 'هر گرم = ۱۰۰ سوت' },
  { key: 'mesghal-sarrafi', label: 'مثقال صرافی', grams: MESQAL_SARRAFI_GRAMS, hint: '۲۴ نخود' },
  { key: 'mesghal-sharii', label: 'مثقال شرعی', grams: MESQAL_SHARII_GRAMS },
  { key: 'nokhod', label: 'نخود', grams: NOKHOD_GRAMS },
];

/** تبدیل مقدار از یک واحد به همه واحدها */
export function convertUnits(value: number, fromKey: string): Record<string, number> {
  const from = UNITS.find((u) => u.key === fromKey) ?? UNITS[0];
  const out: Record<string, number> = {};
  for (const u of UNITS) {
    out[u.key] = (value * from.grams) / u.grams;
  }
  return out;
}
