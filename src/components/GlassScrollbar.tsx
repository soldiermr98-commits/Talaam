// اسکرول‌بار گلس‌مورفیسم — ریل شفاف با backdrop blur و دسته طلایی قابل درگ
// جایگزین اسکرول‌بار نیتیو مرورگر (که با CSS مخفی شده)

import { useCallback, useEffect, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react';

const HIDE_AFTER_MS = 1400;
const MIN_THUMB = 36;

export function GlassScrollbar() {
  const railRef = useRef<HTMLDivElement>(null);
  const thumbRef = useRef<HTMLDivElement>(null);
  const hideTimer = useRef<number | null>(null);
  const drag = useRef<{ startY: number; startScroll: number; pxPerScroll: number } | null>(null);
  const [visible, setVisible] = useState(false);

  const scheduleHide = useCallback(() => {
    if (hideTimer.current) window.clearTimeout(hideTimer.current);
    hideTimer.current = window.setTimeout(() => setVisible(false), HIDE_AFTER_MS);
  }, []);

  useEffect(() => {
    const doc = document.documentElement;

    const update = () => {
      const rail = railRef.current;
      const thumb = thumbRef.current;
      if (!rail || !thumb) return;
      const viewport = doc.clientHeight;
      const content = doc.scrollHeight;
      if (content <= viewport + 8) {
        setVisible(false);
        return;
      }
      const railH = rail.clientHeight;
      const maxScroll = content - viewport;
      const thumbH = Math.max(MIN_THUMB, Math.round((viewport / content) * railH));
      const y = Math.round((doc.scrollTop / maxScroll) * (railH - thumbH));
      thumb.style.height = `${thumbH}px`;
      thumb.style.transform = `translateY(${y}px)`;
      setVisible(true);
      scheduleHide();
    };

    update();
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    const ro = new ResizeObserver(update);
    ro.observe(document.body);
    return () => {
      window.removeEventListener('scroll', update);
      window.removeEventListener('resize', update);
      ro.disconnect();
      if (hideTimer.current) window.clearTimeout(hideTimer.current);
    };
  }, [scheduleHide]);

  const onRailPointerDown = (e: ReactPointerEvent<HTMLDivElement>) => {
    // فقط کلیک مستقیم روی ریل (نه دسته) → پرش به موقعیت
    if (e.target !== e.currentTarget) return;
    const doc = document.documentElement;
    const rail = railRef.current;
    if (!rail) return;
    const rect = rail.getBoundingClientRect();
    const ratio = Math.min(1, Math.max(0, (e.clientY - rect.top) / rect.height));
    doc.scrollTop = ratio * (doc.scrollHeight - doc.clientHeight);
  };

  const onThumbPointerDown = (e: ReactPointerEvent<HTMLDivElement>) => {
    e.stopPropagation();
    const rail = railRef.current;
    const thumb = thumbRef.current;
    if (!rail || !thumb) return;
    const railH = rail.clientHeight;
    const thumbH = thumb.offsetHeight;
    const maxScroll = document.documentElement.scrollHeight - document.documentElement.clientHeight;
    if (railH - thumbH <= 0 || maxScroll <= 0) return;
    drag.current = {
      startY: e.clientY,
      startScroll: document.documentElement.scrollTop,
      pxPerScroll: maxScroll / (railH - thumbH),
    };
    e.currentTarget.setPointerCapture(e.pointerId);
  };

  const onThumbPointerMove = (e: ReactPointerEvent<HTMLDivElement>) => {
    if (!drag.current) return;
    const dy = e.clientY - drag.current.startY;
    document.documentElement.scrollTop = drag.current.startScroll + dy * drag.current.pxPerScroll;
  };

  const endDrag = (e: ReactPointerEvent<HTMLDivElement>) => {
    if (!drag.current) return;
    drag.current = null;
    try {
      e.currentTarget.releasePointerCapture(e.pointerId);
    } catch {
      /* نادیده */
    }
  };

  return (
    <div
      ref={railRef}
      className={`glass-rail${visible ? ' visible' : ''}`}
      onPointerDown={onRailPointerDown}
      onPointerEnter={() => {
        setVisible(true);
        scheduleHide();
      }}
      aria-hidden="true"
    >
      <div
        ref={thumbRef}
        className="thumb"
        onPointerDown={onThumbPointerDown}
        onPointerMove={onThumbPointerMove}
        onPointerUp={endDrag}
        onPointerCancel={endDrag}
      />
    </div>
  );
}
