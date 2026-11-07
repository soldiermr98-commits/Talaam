// ورودی عددی تومانی با جداکننده هزارگان خودکار و نمایش حروف فارسی

import React, { useEffect, useRef, useState } from 'react';
import { formatMoney, formatWeight, groupThousands, mapDigits, moneyToPersianWords, normalizeNumericInput, parseDecimal } from '../lib/numbers';
import { MAX_WEIGHT_DECIMALS } from '../config';
import { useApp } from '../state/AppContext';

interface MoneyInputProps {
  label: string;
  hint?: string;
  value: number; // مقدار واقعی (تومان)
  onChange: (value: number | null) => void;
  placeholder?: string;
  showWords?: boolean;
  autoFocus?: boolean;
  onEnter?: () => void;
  icon?: React.ReactNode;
}

export function MoneyInput({ label, hint, value, onChange, placeholder, showWords = true, autoFocus, onEnter, icon }: MoneyInputProps) {
  const { settings } = useApp();
  const [text, setText] = useState<string>(() => formatDisplay(value, settings.digits));
  const focusedRef = useRef(false);

  // وقتی مقدار بیرونی تغییر می‌کند و فوکوس نیستیم، نمایش را همگام کن
  useEffect(() => {
    if (!focusedRef.current) {
      setText(formatDisplay(value, settings.digits));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value, settings.digits]);

  const handleChange = (raw: string) => {
    const norm = normalizeNumericInput(raw);
    setText(formatWhileTyping(norm, settings.digits));
    const n = parseDecimal(norm);
    onChange(n === null || n <= 0 ? null : Math.round(n));
  };

  const words = value > 0 ? moneyToPersianWords(value) : '';

  return (
    <div className="input-block">
      <div className="label-row">
        <span className="label">
          {icon ? <span className="label-icon">{icon}</span> : null}
          {label}
        </span>
        {hint ? <span className="hint">{hint}</span> : null}
      </div>
      <div className="field">
        <input
          type="text"
          inputMode="decimal"
          dir="ltr"
          value={text}
          placeholder={placeholder ? mapDigits(placeholder, settings.digits) : ''}
          onChange={(e) => handleChange(e.target.value)}
          onFocus={() => (focusedRef.current = true)}
          onBlur={() => {
            focusedRef.current = false;
            setText(formatDisplay(value, settings.digits));
          }}
          onKeyDown={(e) => {
            if (e.key === 'Enter') (e.target as HTMLInputElement).blur();
            onEnter?.();
          }}
          autoFocus={autoFocus}
          aria-label={label}
        />
        <span className="unit-chip">تومان</span>
      </div>
      {showWords && words ? <div className="words">«{words}»</div> : null}
    </div>
  );
}

/** ورودی وزن با حداکثر ۳ رقم اعشار */
export function WeightInput({
  label,
  hint,
  value,
  onChange,
  placeholder,
  onEnter,
}: Omit<MoneyInputProps, 'showWords' | 'icon'>) {
  const { settings } = useApp();
  const [text, setText] = useState<string>(() => formatWeightDisplay(value, settings.digits));
  const focusedRef = useRef(false);

  useEffect(() => {
    if (!focusedRef.current) setText(formatWeightDisplay(value, settings.digits));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value, settings.digits]);

  const handleChange = (raw: string) => {
    const norm = normalizeNumericInput(raw);
    // محدود به ۳ رقم اعشار
    const dot = norm.indexOf('.');
    const limited = dot >= 0 ? norm.slice(0, dot + 1 + MAX_WEIGHT_DECIMALS) : norm;
    setText(limited);
    const n = parseDecimal(limited);
    onChange(n === null || n <= 0 ? null : n);
  };

  return (
    <div className="input-block">
      <div className="label-row">
        <span className="label">{label}</span>
        {hint ? <span className="hint">{hint}</span> : null}
      </div>
      <div className="field">
        <input
          type="text"
          inputMode="decimal"
          dir="ltr"
          value={text}
          placeholder={placeholder ? mapDigits(placeholder, settings.digits) : ''}
          onChange={(e) => handleChange(e.target.value)}
          onFocus={() => (focusedRef.current = true)}
          onBlur={() => {
            focusedRef.current = false;
            setText(formatWeightDisplay(value, settings.digits));
          }}
          onKeyDown={(e) => {
            if (e.key === 'Enter') (e.target as HTMLInputElement).blur();
            onEnter?.();
          }}
          aria-label={label}
        />
        <span className="unit-chip">گرم</span>
      </div>
    </div>
  );
}

/** ورودی درصدی (کوچک، بدون حروف) */
export function PercentInput({
  label,
  value,
  onChange,
  hint,
}: {
  label: string;
  value: number;
  onChange: (v: number | null) => void;
  hint?: string;
}) {
  const { settings } = useApp();
  const [text, setText] = useState<string>(() => (value > 0 ? mapDigits(String(value), settings.digits) : ''));
  const focusedRef = useRef(false);

  useEffect(() => {
    if (!focusedRef.current) setText(value > 0 ? mapDigits(String(value), settings.digits) : '');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value, settings.digits]);

  const handleChange = (raw: string) => {
    const norm = normalizeNumericInput(raw);
    setText(norm);
    const n = parseDecimal(norm);
    onChange(n === null || n < 0 ? null : n);
  };

  return (
    <div className="input-block">
      <div className="label-row">
        <span className="label">{label}</span>
        {hint ? <span className="hint">{hint}</span> : null}
      </div>
      <div className="field">
        <input
          type="text"
          inputMode="decimal"
          dir="ltr"
          value={text}
          onChange={(e) => handleChange(e.target.value)}
          onFocus={() => (focusedRef.current = true)}
          onBlur={() => {
            focusedRef.current = false;
            const n = parseDecimal(text);
            setText(n != null && n > 0 ? mapDigits(String(n), settings.digits) : '');
          }}
          aria-label={label}
        />
        <span className="unit-chip">٪</span>
      </div>
    </div>
  );
}

function formatDisplay(value: number, digits: 'fa' | 'en'): string {
  if (!value || value <= 0) return '';
  return formatMoney(value, digits);
}

function formatWeightDisplay(value: number, digits: 'fa' | 'en'): string {
  if (!value || value <= 0) return '';
  return formatWeight(value, digits);
}

/** نمایش حین تایپ: ارقام با جداکننده، ولی بدون نقطه‌ی اضافه */
function formatWhileTyping(norm: string, digits: 'fa' | 'en'): string {
  const dot = norm.indexOf('.');
  if (dot === -1) return mapDigits(groupThousands(norm, digits === 'fa' ? '\u066C' : ','), digits);
  const int = groupThousands(norm.slice(0, dot), digits === 'fa' ? '\u066C' : ',');
  return mapDigits(int + '.' + norm.slice(dot + 1), digits);
}
