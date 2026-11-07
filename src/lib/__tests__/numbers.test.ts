import { describe, expect, it } from 'vitest';
import {
  formatMoney,
  formatPercent,
  formatWeight,
  moneyToPersianWords,
  normalizeNumericInput,
  numberToPersianWords,
  parseDecimal,
  parseMoney,
  toPersianDigits,
} from '../numbers';

describe('normalizeNumericInput — نرمال‌سازی ورودی', () => {
  it('ارقام فارسی و ممیز فارسی', () => {
    expect(normalizeNumericInput('۴٫۲۵')).toBe('4.25');
    expect(normalizeNumericInput('۱۲۳۴')).toBe('1234');
  });

  it('ارقام عربی', () => {
    expect(normalizeNumericInput('٤٥٠٠')).toBe('4500');
  });

  it('جداکننده هزارگان حذف می‌شود', () => {
    expect(normalizeNumericInput('21,500,000')).toBe('21500000');
    expect(normalizeNumericInput('۱٬۲۳۴٬۵۶۷')).toBe('1234567');
    expect(normalizeNumericInput('۱،۲۳۴')).toBe('1234');
  });

  it('نویسه‌های ناشناخته حذف و فقط یک نقطه مجاز است', () => {
    expect(normalizeNumericInput('4.2.5')).toBe('4.25');
    expect(normalizeNumericInput('  12a3 ')).toBe('123');
  });
});

describe('parseDecimal / parseMoney', () => {
  it('تجزیه ساده', () => {
    expect(parseDecimal('4.25')).toBe(4.25);
    expect(parseDecimal('۴٫۲۵')).toBe(4.25);
    expect(parseDecimal('')).toBeNull();
    expect(parseDecimal('.')).toBeNull();
  });

  it('پول گرد می‌شود', () => {
    expect(parseMoney('2,500.7')).toBe(2501);
    expect(parseMoney('۴٬۵۰۰٬۰۰۰')).toBe(4_500_000);
  });
});

describe('formatMoney / formatWeight / formatPercent', () => {
  it('قالب فارسی با جداکننده ٬', () => {
    expect(formatMoney(4_450_000, 'fa')).toBe('۴٬۴۵۰٬۰۰۰');
  });

  it('قالب انگلیسی با جداکننده ,', () => {
    expect(formatMoney(4_450_000, 'en')).toBe('4,450,000');
  });

  it('وزن تا سه رقم اعشار و بدون صفر انتهایی', () => {
    expect(formatWeight(4.25, 'fa')).toBe('۴.۲۵');
    expect(formatWeight(4.250, 'en')).toBe('4.25');
    expect(formatWeight(4.2504, 'en')).toBe('4.25');
    expect(formatWeight(4, 'fa')).toBe('۴');
    expect(formatWeight(100, 'en')).toBe('100');
  });

  it('درصد با یک اعشار', () => {
    expect(formatPercent(22.44, 'fa')).toBe('۲۲.۴');
    expect(formatPercent(15, 'fa')).toBe('۱۵');
  });
});

describe('numberToPersianWords — عدد به حروف', () => {
  it('صفر', () => {
    expect(numberToPersianWords(0)).toBe('صفر');
  });

  it('مثال spec: ۴٬۵۲۰٬۰۰۰', () => {
    expect(numberToPersianWords(4_520_000)).toBe('چهار میلیون و پانصد و بیست هزار');
    expect(moneyToPersianWords(4_520_000)).toBe('چهار میلیون و پانصد و بیست هزار تومان');
  });

  it('اعداد ساده', () => {
    expect(numberToPersianWords(105)).toBe('صد و پنج');
    expect(numberToPersianWords(60)).toBe('شصت');
    expect(numberToPersianWords(21)).toBe('بیست و یک');
    expect(numberToPersianWords(17)).toBe('هفده');
    expect(numberToPersianWords(7000)).toBe('هفت هزار');
    expect(numberToPersianWords(1005)).toBe('یک هزار و پنج');
  });

  it('مبلغ فاکتور: ۲۲٬۴۴۳٬۵۵۰', () => {
    expect(numberToPersianWords(22_443_550)).toBe('بیست و دو میلیون و چهارصد و چهل و سه هزار و پانصد و پنجاه');
  });

  it('میلیارد و تریلیون', () => {
    expect(numberToPersianWords(1_000_000_000)).toBe('یک میلیارد');
    expect(numberToPersianWords(1_000_000_000_000)).toBe('یک تریلیون');
    expect(numberToPersianWords(123_456_789_012)).toBe('صد و بیست و سه میلیارد و چهارصد و پنجاه و شش میلیون و هفتصد و هشتاد و نه هزار و دوازده');
  });

  it('ارقام فارسی خروجی برای مبلغ تومانی روی ورودی اعشاری', () => {
    expect(moneyToPersianWords(21500000.4)).toBe('بیست و یک میلیون و پانصد هزار تومان');
  });
});

describe('toPersianDigits', () => {
  it('تبدیل ارقام (سایر نویسه‌ها ثابت می‌مانند)', () => {
    expect(toPersianDigits('14.5')).toBe('۱۴.۵');
  });
});
