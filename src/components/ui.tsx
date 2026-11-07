// اجزای کوچک رابط: چیپ، سگمنت، کلید، جمع‌شدنی، شیت پایین

import React, { useEffect } from 'react';
import { ChevronDownIcon, CloseIcon } from './icons';

export function Chip({
  active,
  onClick,
  children,
  ariaLabel,
}: {
  active?: boolean;
  onClick?: () => void;
  children: React.ReactNode;
  ariaLabel?: string;
}) {
  return (
    <button type="button" className={`chip${active ? ' active' : ''}`} onClick={onClick} aria-label={ariaLabel} aria-pressed={active}>
      {children}
    </button>
  );
}

export function Segmented<T extends string>({
  options,
  value,
  onChange,
  compact,
  ariaLabel,
}: {
  options: { key: T; label: string }[];
  value: T;
  onChange: (v: T) => void;
  compact?: boolean;
  ariaLabel?: string;
}) {
  return (
    <div className={`segmented${compact ? ' compact' : ''}`} role="tablist" aria-label={ariaLabel}>
      {options.map((o) => (
        <button
          key={o.key}
          type="button"
          className={value === o.key ? 'active' : ''}
          onClick={() => onChange(o.key)}
          role="tab"
          aria-selected={value === o.key}
        >
          {o.label}
        </button>
      ))}
    </div>
  );
}

export function Toggle({ on, onChange, label }: { on: boolean; onChange?: (v: boolean) => void; label?: string }) {
  return (
    <button
      type="button"
      className={`toggle${on ? ' on' : ''}`}
      onClick={() => onChange?.(!on)}
      role="switch"
      aria-checked={on}
      aria-label={label}
    >
      <span className="knob" />
    </button>
  );
}

export function Collapsible({
  open,
  onToggle,
  children,
  label = 'جزئیات',
}: {
  open: boolean;
  onToggle: () => void;
  children: React.ReactNode;
  label?: string;
}) {
  return (
    <div>
      <button type="button" className="details-toggle" onClick={onToggle} aria-expanded={open}>
        <span>{label}</span>
        <ChevronDownIcon size={16} style={{ transform: open ? 'rotate(180deg)' : 'none', transition: 'transform .2s' }} />
      </button>
      {open ? <div className="detail-rows">{children}</div> : null}
    </div>
  );
}

/** شیت پایین صفحه — با Escape و کلیک روی پس‌زمینه بسته می‌شود */
export function Sheet({
  onClose,
  title,
  subtitle,
  children,
  headerIcon,
}: {
  onClose: () => void;
  title: string;
  subtitle?: string;
  children: React.ReactNode;
  headerIcon?: React.ReactNode;
}) {
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    return () => {
      window.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
    };
  }, [onClose]);

  return (
    <div className="sheet-backdrop" onClick={(e) => e.target === e.currentTarget && onClose()}>
      <div className="sheet" role="dialog" aria-modal="true" aria-label={title}>
        <div className="sheet-head">
          <div style={{ width: 40 }} />
          <div className="sheet-titles">
            <div className="sheet-title">{headerIcon ? <span style={{ display: 'inline-flex', marginInlineEnd: 6 }}>{headerIcon}</span> : null}{title}</div>
            {subtitle ? <div className="sheet-sub">{subtitle}</div> : null}
          </div>
          <button type="button" className="icon-btn" onClick={onClose} aria-label="بستن">
            <CloseIcon />
          </button>
        </div>
        {children}
      </div>
    </div>
  );
}
