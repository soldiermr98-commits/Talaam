// ابزارهای عددی: نرمال‌سازی ارقام فارسی/عربی/انگلیسی، قالب‌بندی، تبدیل عدد به حروف فارسی

export type DigitLocale = 'fa' | 'en';

const FA_DIGITS = '۰۱۲۳۴۵۶۷۸۹';
const AR_DIGITS = '٠١٢٣٤٥٦٧٨٩';

const FA_THOUSANDS = '\u066C'; // ٬
const EN_THOUSANDS = ',';

/** تبدیل ارقام فارسی و عربی به انگلیسی */
export function toEnglishDigits(input: string): string {
  let out = '';
  for (const ch of input) {
    const fa = FA_DIGITS.indexOf(ch);
    if (fa >= 0) {
      out += String(fa);
      continue;
    }
    const ar = AR_DIGITS.indexOf(ch);
    if (ar >= 0) {
      out += String(ar);
      continue;
    }
    out += ch;
  }
  return out;
}

/** تبدیل ارقام انگلیسی به فارسی */
export function toPersianDigits(input: string): string {
  return input.replace(/[0-9]/g, (d) => FA_DIGITS[Number(d)]);
}

/** نقشه‌ی ارقام بر اساس تنظیم کاربر */
export function mapDigits(input: string, locale: DigitLocale): string {
  return locale === 'fa' ? toPersianDigits(input) : input;
}

/**
 * نرمال‌سازی ورودی عددی:
 * - ارقام فارسی/عربی → انگلیسی
 * - ممیز فارسی ٫ (U+066B) → نقطه
 * - جداکننده‌های هزارگان ٬ (U+066C) و , و ، حذف می‌شوند
 * - هر کاراکتر دیگری حذف می‌شود
 */
export function normalizeNumericInput(raw: string): string {
  const s = toEnglishDigits(raw)
    .replace(/\u066B/g, '.')
    .replace(/[\u066C,،]/g, '')
    .replace(/[^0-9.]/g, '');
  const first = s.indexOf('.');
  if (first === -1) return s;
  return s.slice(0, first + 1) + s.slice(first + 1).replace(/\./g, '');
}

/** تجزیه عدد اعشاری؛ ورودی نامعتبر → null */
export function parseDecimal(raw: string): number | null {
  const s = normalizeNumericInput(raw);
  if (!s || s === '.') return null;
  const n = Number(s);
  return Number.isFinite(n) ? n : null;
}

/** تجزیه مبلغ تومانی (گرد شده به عدد صحیح) */
export function parseMoney(raw: string): number | null {
  const n = parseDecimal(raw);
  return n === null ? null : Math.round(n);
}

/** افزودن جداکننده هزارگان به رشته‌ی عددی */
export function groupThousands(s: string, separator: string = FA_THOUSANDS): string {
  const neg = s.startsWith('-') ? '-' : '';
  const body = neg ? s.slice(1) : s;
  const dot = body.indexOf('.');
  const int = dot === -1 ? body : body.slice(0, dot);
  const frac = dot === -1 ? '' : body.slice(dot);
  const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, separator);
  return neg + grouped + frac;
}

/** قالب‌بندی مبلغ تومانی با جداکننده هزارگان و ارقام محلی */
export function formatMoney(value: number, locale: DigitLocale = 'fa'): string {
  if (!Number.isFinite(value)) return '';
  const sep = locale === 'fa' ? FA_THOUSANDS : EN_THOUSANDS;
  return mapDigits(groupThousands(String(Math.round(value)), sep), locale);
}

/** قالب‌بندی وزن (حداکثر N رقم اعشار، بدون صفرهای انتهایی) */
export function formatWeight(value: number, locale: DigitLocale = 'fa', maxDecimals = 3): string {
  if (!Number.isFinite(value)) return '';
  const fixed = value.toFixed(maxDecimals);
  const trimmed = fixed.includes('.') ? fixed.replace(/0+$/, '').replace(/\.$/, '') : fixed;
  return mapDigits(trimmed, locale);
}

/** قالب‌بندی درصد (حداکثر یک رقم اعشار) */
export function formatPercent(value: number, locale: DigitLocale = 'fa'): string {
  if (!Number.isFinite(value)) return '';
  const rounded = Math.round(value * 10) / 10;
  return mapDigits(String(rounded), locale);
}

const ONES = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه'];
const TEENS = ['ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
const TENS = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
const HUNDREDS = ['', 'صد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
const SCALES = ['', 'هزار', 'میلیون', 'میلیارد', 'تریلیون'];
const MAX_WORDS = 999_999_999_999_999; // تا ۹۹۹ تریلیون

function threeDigitToWords(n: number): string {
  const parts: string[] = [];
  const h = Math.floor(n / 100);
  const rest = n % 100;
  if (h > 0) parts.push(HUNDREDS[h]);
  if (rest >= 10 && rest < 20) {
    parts.push(TEENS[rest - 10]);
  } else {
    const t = Math.floor(rest / 10);
    const o = rest % 10;
    if (t >= 2) parts.push(TENS[t]);
    if (o > 0) parts.push(ONES[o]);
  }
  return parts.join(' و ');
}

/** تبدیل عدد صحیح به حروف فارسی (تا تریلیون) */
export function numberToPersianWords(value: number): string {
  if (!Number.isFinite(value)) return '';
  const n = Math.abs(Math.trunc(value));
  if (n === 0) return 'صفر';
  if (n > MAX_WORDS) return toEnglishDigits(String(n));
  const groups: number[] = [];
  let x = n;
  while (x > 0) {
    groups.push(x % 1000);
    x = Math.floor(x / 1000);
  }
  const parts: string[] = [];
  for (let i = groups.length - 1; i >= 0; i--) {
    if (groups[i] === 0) continue;
    const scale = SCALES[i] ?? '';
    const words = threeDigitToWords(groups[i]);
    parts.push(scale ? `${words} ${scale}` : words);
  }
  return parts.join(' و ');
}

/** مبلغ تومانی به حروف، مثل «چهار میلیون و پانصد و بیست هزار تومان» */
export function moneyToPersianWords(value: number): string {
  const words = numberToPersianWords(value);
  return words ? `${words} تومان` : '';
}
