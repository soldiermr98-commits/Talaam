// نوار نرخ طلای ۱۸ عیار و مظنه — ویرایش در پاپ‌اپ شیت، هماهنگ با طرح مرجع

import { useEffect, useRef, useState } from 'react';
import { useApp } from '../state/AppContext';
import { formatMoney, parseMoney, toPersianDigits } from '../lib/numbers';
import { Sheet } from './ui';
import { CheckIcon, PencilIcon } from './icons';

export function PriceStrip() {
  const { gold18, mazaneh, updatedAtStale, online, isManual, setManualGold18, settings } = useApp();
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState('');
  const [error, setError] = useState('');
  const inputRef = useRef<HTMLInputElement>(null);

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
  const mazanehDisplay = mazaneh > 0 ? formatMoney(mazaneh, settings.digits) : null;

  return (
    <>
      <section className="ticker-strip" aria-label="نرخ طلای ۱۸ عیار">
        <button type="button" className="ticker-main-btn" onClick={openEditor} title="ویرایش نرخ طلای ۱۸ عیار">
          <span className={`ticker-dot${updatedAtStale ? ' stale' : ''}`} />
          {priceDisplay ? (
            <>
              <span className="ticker-label">{isManual ? 'گرم ۱۸ (دستی):' : 'گرم ۱۸ عیار:'}</span>
              <span className="ticker-val-primary">{priceDisplay}</span>
              <span className="ticker-unit">ت</span>
            </>
          ) : (
            <span className="ticker-label">
              {online ? 'نرخ ۱۸ عیار را وارد کن' : 'آفلاین — ورود نرخ دستی'}
            </span>
          )}
        </button>

        <div className="ticker-side">
          {isManual ? (
            <button
              type="button"
              className="ticker-reset-btn"
              onClick={() => setManualGold18(null)}
              title="بازگشت به نرخ خودکار"
            >
              نرخ خودکار
            </button>
          ) : mazanehDisplay ? (
            <button type="button" className="ticker-secondary-btn" onClick={openEditor}>
              <span className="ticker-label">مظنه:</span>
              <span className="ticker-val-gold">{mazanehDisplay}</span>
            </button>
          ) : null}
          <button type="button" className="ticker-edit-icon" onClick={openEditor} aria-label="ویرایش نرخ">
            <PencilIcon size={14} />
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
