// نوار نرخ طلای ۱۸ عیار — قابل ویرایش دستی، نمایش زمان بروزرسانی و مظنه

import { useEffect, useRef, useState } from 'react';
import { useApp } from '../state/AppContext';
import { formatMoney, parseMoney, toPersianDigits, mapDigits } from '../lib/numbers';
import { CheckIcon, PencilIcon, WifiOffIcon } from './icons';

export function PriceStrip() {
  const { gold18, updatedAtText, updatedAtStale, online, isManual, setManualGold18, settings, everFetched } = useApp();
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState('');
  const inputRef = useRef<HTMLInputElement>(null);

  // اولین اجرا بدون داده و آفلاین → مستقیم حالت ورود دستی؛ با رسیدن نرخ، ویرایشگر بسته شود
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
    if (editing) setTimeout(() => inputRef.current?.focus(), 60);
  }, [editing]);

  const submit = () => {
    const n = parseMoney(draft);
    if (n && n > 0) setManualGold18(n);
    setEditing(false);
    setDraft('');
  };

  const priceDisplay = gold18 > 0 ? formatMoney(gold18, settings.digits) : null;

  return (
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
            <span>بروزرسانی: {updatedAtText || 'نامشخص'}</span>
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
        {editing ? (
          <div className="strip-edit-row">
            <div className="field" style={{ flex: 1 }}>
              <input
                ref={inputRef}
                type="text"
                inputMode="decimal"
                dir="ltr"
                value={draft}
                placeholder={mapDigits('مثلاً ۴٬۴۵۰٬۰۰۰', settings.digits)}
                onChange={(e) => setDraft(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') submit();
                  if (e.key === 'Escape') setEditing(false);
                }}
                aria-label="نرخ طلای ۱۸ عیار به تومان"
              />
              <span className="unit-chip">تومان/گرم</span>
            </div>
            <button type="button" className="chip active" onClick={submit} style={{ minHeight: 44 }}>
              ذخیره
            </button>
          </div>
        ) : null}
      </div>
      <div className="strip-actions">
        <button
          type="button"
          className="icon-btn"
          onClick={() => {
            setDraft(gold18 > 0 ? toPersianDigits(String(gold18)) : '');
            setEditing((v) => !v);
          }}
          aria-label="ویرایش نرخ"
        >
          {editing ? <CheckIcon size={20} /> : <PencilIcon size={19} />}
        </button>
      </div>
    </section>
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
