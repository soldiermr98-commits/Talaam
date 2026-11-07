// نوار نرخ طلای ۱۸ عیار — ویرایش در پاپ‌اپ شیت، نمایش زمان بروزرسانی و هشدار stale

import { useEffect, useRef, useState } from 'react';
import { useApp } from '../state/AppContext';
import { formatMoney, parseMoney, toPersianDigits } from '../lib/numbers';
import { Sheet } from './ui';
import { CheckIcon, PencilIcon, WifiOffIcon } from './icons';

export function PriceStrip() {
  const { gold18, updatedAtText, updatedAtStale, online, isManual, setManualGold18, settings, everFetched } = useApp();
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState('');
  const [error, setError] = useState('');
  const inputRef = useRef<HTMLInputElement>(null);

  // اولین اجرا بدون داده و آفلاین → مستقیم پاپ‌اپ ورود دستی؛ با رسیدن نرخ، بسته شود
  const prevGold = useRef(gold18);
  useEffect(() => {
    if (prevGold.current <= 0 && gold18 > 0) {
      setEditing(false);
      setDraft('');
    }
    prevGold.current = gold18;
    if (gold18 <= 0 && !editing && !everFetched) setEditing(true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [gold18, everFetched]);

  useEffect(() => {
    if (editing) setTimeout(() => inputRef.current?.focus(), 120);
  }, [editing]);

  const openEditor = () => {
    setDraft(gold18 > 0 ? toPersianDigits(String(gold18)) : '');
    setError('');
    setEditing(true);
  };

  const cancel = () => {
    setEditing(false);
    setDraft('');
    setError('');
  };

  const submit = () => {
    const n = parseMoney(draft);
    if (!n || n <= 0) {
      setError('یک عدد معتبر وارد کن');
      return;
    }
    setManualGold18(n);
    setEditing(false);
    setDraft('');
    setError('');
  };

  const priceDisplay = gold18 > 0 ? formatMoney(gold18, settings.digits) : null;

  return (
    <>
      <section className="card strip" aria-label="نرخ طلای ۱۸ عیار">
        <div className="strip-icon">
          <CheckDollar />
        </div>
        <div className="strip-info">
          {priceDisplay ? (
            <div className="strip-line1">
              <span>{isManual ? 'نرخ دستی طلای ۱۸ عیار:' : 'نرخ طلای ۱۸ عیار:'}</span>
              <span className="strip-price">{priceDisplay}</span>
              <span>تومان</span>
            </div>
          ) : (
            <div className="strip-line1">
              <span>{online ? 'نرخ ۱۸ عیار را وارد کن:' : 'آفلاین. نرخ طلا رو دستی وارد کن'}</span>
            </div>
          )}
          <div className={`strip-sub${updatedAtStale ? ' stale' : ''}`}>
            {gold18 > 0 ? (
              <>
                <span>بروزرسانی: {updatedAtText || 'نامشخص'}</span>
                {updatedAtStale ? <span>• نرخ قدیمی است</span> : null}
              </>
            ) : (
              <WifiOffIcon size={13} />
            )}
          </div>
          {isManual ? (
            <div className="strip-sub">
              <span className="manual-chip">نرخ دستی — تا دریافت نرخ جدید معتبر است</span>
              <button type="button" className="link-btn" onClick={() => setManualGold18(null)}>
                بازگشت به نرخ خودکار
              </button>
            </div>
          ) : null}
        </div>
        <div className="strip-actions">
          <button type="button" className="icon-btn" onClick={openEditor} aria-label="ویرایش نرخ">
            <PencilIcon size={19} />
          </button>
        </div>
      </section>

      {editing ? (
        <Sheet onClose={cancel} title="ویرایش نرخ طلای ۱۸ عیار" subtitle="این نرخ تا دریافت نرخ موفق بعدی معتبر می‌ماند">
          <div className="field" style={{ minHeight: 68 }}>
            <input
              ref={inputRef}
              type="text"
              inputMode="decimal"
              dir="ltr"
              value={draft}
              placeholder={toPersianDigits('مثلاً ۸٬۰۰۰٬۰۰۰')}
              onChange={(e) => {
                setDraft(e.target.value);
                setError('');
              }}
              onKeyDown={(e) => {
                if (e.key === 'Enter') submit();
                if (e.key === 'Escape') cancel();
              }}
              onFocus={(e) => {
                const el = e.currentTarget;
                requestAnimationFrame(() => el.setSelectionRange(el.value.length, el.value.length));
              }}
              aria-label="نرخ طلای ۱۸ عیار به تومان"
              style={{ fontSize: 26 }}
            />
            <span className="unit-chip">تومان/گرم</span>
          </div>
          {error ? <div className="words" style={{ color: 'var(--red)' }}>{error}</div> : null}
          <div className="hint" style={{ marginTop: 8 }}>
            تا وقتی نرخ جدید از منبع برسد، همه محاسبات با همین عدد انجام می‌شود.
          </div>
          <div className="btn-row">
            <button type="button" className="btn btn-outline" onClick={cancel}>
              انصراف
            </button>
            <button type="button" className="btn btn-gold" onClick={submit}>
              <CheckIcon size={18} />
              ذخیره نرخ
            </button>
          </div>
        </Sheet>
      ) : null}
    </>
  );
}

function CheckDollar() {
  // آیکون دلار در دایره — مطابق طراحی
  return (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M12 2v20" />
      <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
    </svg>
  );
}
