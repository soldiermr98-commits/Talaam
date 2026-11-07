// شیت مبدل واحدها — گرم، سوت، مثقال صرافی، مثقال شرعی، نخود

import { useMemo, useState } from 'react';
import { convertUnits, UNITS } from '../lib/calc';
import { useApp } from '../state/AppContext';
import { Sheet, Chip } from './ui';
import { normalizeNumericInput, parseDecimal } from '../lib/numbers';
import { ScaleIcon } from './icons';

export function ConverterSheet({ onClose }: { onClose: () => void }) {
  const { fmtWeight } = useApp();
  const [value, setValue] = useState<number | null>(null);
  const [unit, setUnit] = useState('gram');

  const results = useMemo(() => (value && value > 0 ? convertUnits(value, unit) : null), [value, unit]);

  const handleChange = (raw: string) => {
    const n = parseDecimal(normalizeNumericInput(raw));
    setValue(n != null && n > 0 ? n : null);
  };

  return (
    <Sheet onClose={onClose} title="مبدل واحدها" subtitle="تبدیل فوری بین واحدهای وزن طلا" headerIcon={<ScaleIcon size={20} />}>
      <div className="field" style={{ marginBottom: 12 }}>
        <input
          type="text"
          inputMode="decimal"
          dir="ltr"
          value={value != null && value > 0 ? String(value).replace('.', '٫') : ''}
          onChange={(e) => handleChange(e.target.value)}
          placeholder="مقدار را وارد کن…"
          aria-label="مقدار"
        />
        <span className="unit-chip">{UNITS.find((u) => u.key === unit)?.label}</span>
      </div>

      <div className="chips" style={{ marginBottom: 14 }}>
        {UNITS.map((u) => (
          <Chip key={u.key} active={unit === u.key} onClick={() => setUnit(u.key)}>
            {u.label}
          </Chip>
        ))}
      </div>

      {results ? (
        <div className="conv-grid">
          {UNITS.map((u) => (
            <div className="conv-cell" key={u.key}>
              <div className="k">{u.label}</div>
              <div className="v">{fmtWeight(results[u.key], 4)}</div>
            </div>
          ))}
        </div>
      ) : (
        <div className="empty-state">هر عددی وارد کنی، معادل آن در همه واحدها فوری نمایش داده می‌شود.</div>
      )}

      <p className="footer-note">
        هر گرم = ۱۰۰ سوت • مثقال صرافی = ۴٫۶۰۸۳ گرم (۲۴ نخود) • مثقال شرعی = ۳٫۴۵۶ گرم • نخود = ۰٫۱۹۲ گرم
      </p>
    </Sheet>
  );
}
