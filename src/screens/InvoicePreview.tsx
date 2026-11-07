// صفحه پیش‌نمایش فاکتور — پیش‌نمایش مقیاس‌شده + خروجی ۱۰۸۰px برای اشتراک‌گذاری

import { useEffect, useMemo, useRef, useState } from 'react';
import { calcNewGold, type MakingMode } from '../lib/calc';
import { useApp } from '../state/AppContext';
import { InvoiceCard } from '../InvoiceCard';
import { CloseIcon, DownloadIcon, PencilIcon, ShareIcon } from '../components/icons';
import { saveInvoiceImage, shareInvoiceImage } from '../lib/share';

export function InvoicePreview({ onClose }: { onClose: () => void }) {
  const { inputs, gold18, isManual, settings } = useApp();
  const exportRef = useRef<HTMLDivElement>(null);
  const wrapRef = useRef<HTMLDivElement>(null);
  const [scale, setScale] = useState(0.3);
  const [height, setHeight] = useState(1700);
  const [busy, setBusy] = useState<'share' | 'save' | ''>('');
  const [toast, setToast] = useState('');

  const st = inputs.new;
  const weight = typeof st.weight === 'number' ? st.weight : null;
  const makingMode: MakingMode = st.makingMode === 'fixed' ? 'fixed' : 'percent';
  const makingValue = typeof st.makingValue === 'number' ? st.makingValue : makingMode === 'percent' ? 14 : 500_000;
  const profitPct = typeof st.profitPct === 'number' ? st.profitPct : settings.profitPct;
  const taxPct = typeof st.taxPct === 'number' ? st.taxPct : settings.taxPct;

  const result = weight && gold18 > 0 ? calcNewGold({ weight, gold18, makingMode, makingPercent: makingMode === 'percent' ? makingValue : undefined, makingFixedPerGram: makingMode === 'fixed' ? makingValue : undefined, profitPct, taxPct }) : null;

  const tracking = useMemo(() => `TLM-${Math.floor(1000 + Math.random() * 9000)}`, []);
  const dateStr = useMemo(
    () => new Intl.DateTimeFormat('fa-IR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date()),
    []
  );

  // اندازه‌گیری نود خروجی برای مقیاس پیش‌نمایش
  useEffect(() => {
    const update = () => {
      const el = exportRef.current;
      if (!el) return;
      const w = wrapRef.current?.clientWidth ?? 360;
      setScale(w / 1080);
      setHeight(el.offsetHeight);
    };
    update();
    const ro = new ResizeObserver(update);
    if (exportRef.current) ro.observe(exportRef.current);
    window.addEventListener('resize', update);
    return () => {
      ro.disconnect();
      window.removeEventListener('resize', update);
    };
  }, []);

  const showToast = (msg: string) => {
    setToast(msg);
    setTimeout(() => setToast(''), 2600);
  };

  const doShare = async () => {
    if (!exportRef.current) return;
    setBusy('share');
    const out = await shareInvoiceImage(exportRef.current);
    setBusy('');
    showToast(out.ok ? (out.reason === 'downloaded' ? 'تصویر فاکتور دانلود شد' : 'فاکتور آماده اشتراک‌گذاری است') : 'ساخت تصویر ناموفق بود');
  };

  const doSave = async () => {
    if (!exportRef.current) return;
    setBusy('save');
    const out = await saveInvoiceImage(exportRef.current);
    setBusy('');
    showToast(out.ok ? (out.reason === 'downloaded' ? 'تصویر فاکتور ذخیره شد' : 'از شیت باز شده می‌توانی در گالری ذخیره کنی') : 'ذخیره ناموفق بود');
  };

  const data =
    result && weight
      ? {
          weight,
          gold18,
          isManual,
          makingMode,
          makingValue,
          profitPct,
          taxPct,
          result,
          dateStr,
          tracking,
          digits: settings.digits,
        }
      : null;

  return (
    <div className="sheet-backdrop" style={{ alignItems: 'stretch' }} onClick={(e) => e.target === e.currentTarget && onClose()}>
      <div className="sheet" role="dialog" aria-modal="true" aria-label="پیش‌نمایش فاکتور">
        <div className="sheet-head">
          <div style={{ width: 40 }} />
          <div className="sheet-titles">
            <div className="sheet-sub">پیش‌فاکتور رسمی</div>
            <div className="sheet-title">پیش‌نمایش فاکتور طلایی</div>
          </div>
          <button type="button" className="icon-btn" onClick={onClose} aria-label="بستن">
            <CloseIcon />
          </button>
        </div>

        {data ? (
          <>
            <div className="invoice-preview-wrap" ref={wrapRef} style={{ height: height * scale }}>
              <div style={{ transform: `scale(${scale})`, transformOrigin: 'top right', width: 1080 }}>
                <InvoiceCard data={data} />
              </div>
            </div>

            <div className="btn-row">
              <button type="button" className="btn btn-gold btn-block" onClick={doSave} disabled={busy !== ''}>
                <DownloadIcon size={19} />
                {busy === 'save' ? 'در حال ساخت تصویر…' : 'ذخیره به عنوان تصویر'}
              </button>
            </div>
            <div className="btn-row">
              <button type="button" className="btn btn-outline" onClick={onClose}>
                <PencilIcon size={18} />
                ویرایش مقادیر
              </button>
              <button type="button" className="btn btn-gold" onClick={doShare} disabled={busy !== ''}>
                <ShareIcon size={18} />
                {busy === 'share' ? 'در حال ساخت تصویر…' : 'اشتراک‌گذاری فاکتور'}
              </button>
            </div>
            {toast ? <div className="words" style={{ textAlign: 'center' }}>{toast}</div> : null}
          </>
        ) : (
          <div className="empty-state">
            <div className="big">فاکتوری برای نمایش نیست</div>
            اول در تب «طلای نو» وزن و نرخ را وارد کن، بعد فاکتور بساز.
            <div style={{ marginTop: 10 }}>
              <button type="button" className="chip active" onClick={onClose}>
                بازگشت
              </button>
            </div>
          </div>
        )}
      </div>

      {/* نود خروجی ۱۰۸۰ پیکسلی — خارج از دید */}
      {data ? (
        <div className="invoice-offscreen" ref={exportRef}>
          <InvoiceCard data={data} />
        </div>
      ) : null}
    </div>
  );
}
