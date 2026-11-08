// کارت فاکتور — تم تیره ثابت برای خروجی تصویر ۱۰۸۰ پیکسل

import { LockIcon, GearIcon } from './components/icons';
import type { NewGoldResult } from './lib/calc';
import { formatMoney, formatWeight, formatPercent, mapDigits, moneyToPersianWords, type DigitLocale } from './lib/numbers';

export interface InvoiceData {
  weight: number;
  gold18: number;
  isManual: boolean;
  makingMode: 'percent' | 'fixed';
  makingValue: number;
  profitPct: number;
  taxPct: number;
  result: NewGoldResult;
  dateStr: string;
  tracking: string;
  digits: DigitLocale;
}

function money(v: number, digits: DigitLocale) {
  return formatMoney(v, digits);
}

export function InvoiceCard({ data }: { data: InvoiceData }) {
  const { weight, gold18, isManual, makingMode, makingValue, profitPct, taxPct, result, dateStr, tracking, digits } = data;
  return (
    <div className="inv">
      <img src="icons/icon-192.png" alt="طلام" className="inv-logo-img" draggable={false} />
      <div className="inv-title">پیش‌فاکتور محاسبه طلا</div>
      <div className="inv-date">{dateStr}</div>

      <div className="inv-rate">
        <span className="dot" />
        <span>
          {isManual ? 'نرخ دستی' : 'نرخ پایه'} ۱۸ عیار: <b>{money(gold18, digits)}</b> تومان/گرم
        </span>
      </div>

      <div className="inv-rows">
        <div className="inv-row">
          <span>
            وزن خالص طلا
            <span className="sub">۱۸ عیار استاندارد</span>
          </span>
          <b>{formatWeight(weight, digits)} گرم</b>
        </div>
        <div className="inv-row">
          <span>ارزش طلای خام</span>
          <b>
            {money(result.raw, digits)} <span style={{ color: '#8d7fa8', fontSize: 15 }}>تومان</span>
          </b>
        </div>
        <div className="inv-row">
          <span>
            اجرت ساخت <span className="tag">{makingMode === 'percent' ? `${formatPercent(makingValue, digits)}٪` : `${money(makingValue, digits)} ت/گرم`}</span>
          </span>
          <b>
            {money(result.making, digits)} <span style={{ color: '#8d7fa8', fontSize: 15 }}>تومان</span>
          </b>
        </div>
        <div className="inv-row">
          <span>
            سود طلافروش <span className="tag">{formatPercent(profitPct, digits)}٪</span>
          </span>
          <b>
            {money(result.profit, digits)} <span style={{ color: '#8d7fa8', fontSize: 15 }}>تومان</span>
          </b>
        </div>
        <div className="inv-row">
          <span>
            مالیات بر ارزش افزوده <span className="tag">{formatPercent(taxPct, digits)}٪</span>
            <span className="sub">فقط از مجموع سود و اجرت</span>
          </span>
          <b>
            {money(result.tax, digits)} <span style={{ color: '#8d7fa8', fontSize: 15 }}>تومان</span>
          </b>
        </div>
      </div>

      <div className="inv-total">
        <div className="k">مبلغ نهایی پرداختی</div>
        <div className="v">
          {money(result.total, digits)} <span style={{ fontSize: 22, color: '#cfc3e6' }}>تومان</span>
        </div>
        <div className="words">حروف: {formatMoneyAsWords(result.total, digits)}</div>
      </div>

      <div className="inv-brand">
        <span className="chip">
          <GearIcon size={22} />
        </span>
        محاسبه شده توسط اپلیکیشن طلام
      </div>
      <div className="inv-note">محاسبه تقریبی، ملاک نرخ طلافروشی است.</div>

      <div className="inv-footer">
        <LockIcon size={18} />
        سند معتبر دیجیتال — کد پیگیری: {tracking}
      </div>
    </div>
  );
}

function formatMoneyAsWords(v: number, digits: DigitLocale): string {
  // حروف همیشه فارسی است؛ ارقام داخل جمله با تنظیم کاربر
  return mapDigits(moneyToPersianWords(v), digits);
}
