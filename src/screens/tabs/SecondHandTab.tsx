// تب «دست دوم و آب‌شده» — انتخاب عیار، خرید/فروش، مبدل مظنه

import { useMemo } from 'react';
import { calcSecondHand, mazanehToGram18, type SecondHandMode } from '../../lib/calc';
import { KARAT_OPTIONS } from '../../config';
import { useApp } from '../../state/AppContext';
import { MoneyInput, PercentInput, WeightInput } from '../../components/NumberInputs';
import { ResultCard } from '../../components/ResultCard';
import { Chip, Segmented } from '../../components/ui';
import { ScaleIcon, StoreIcon } from '../../components/icons';

export function SecondHandTab() {
  const { inputs, setInput, gold18, settings, fmtMoney, fmtWeight, fmtPercent, words, setManualGold18 } = useApp();
  const st = inputs.second;

  const karat = typeof st.karat === 'number' ? st.karat : 18;
  const mode: SecondHandMode = st.mode === 'sell' ? 'sell' : 'buy';
  const weight = typeof st.weight === 'number' ? st.weight : null;
  const marginPct = typeof st.marginPct === 'number' ? st.marginPct : settings.secondHandMarginPct;
  const mazaneh = typeof st.mazaneh === 'number' ? st.mazaneh : 0;

  const result = weight && gold18 > 0 ? calcSecondHand({ weight, gold18, karat, mode, marginPct }) : null;

  const gram18FromMazaneh = useMemo(
    () => (mazaneh > 0 ? Math.round(mazanehToGram18(mazaneh)) : null),
    [mazaneh]
  );

  return (
    <div>
      <section className="card">
        <div className="label-row">
          <span className="label">عیار طلا</span>
          <span className="hint">نرخ هر گرم بر اساس عیار</span>
        </div>
        <div className="chips">
          {KARAT_OPTIONS.map((k) => (
            <Chip key={k} active={karat === k} onClick={() => setInput('second', 'karat', k)}>
              {fmtPercent(k)} عیار
            </Chip>
          ))}
        </div>
        <div style={{ marginTop: 12 }}>
          <Segmented
            options={[
              { key: 'buy', label: 'خرید از طلافروش' },
              { key: 'sell', label: 'فروش به طلافروش' },
            ]}
            value={mode}
            onChange={(m) => setInput('second', 'mode', m)}
            ariaLabel="حالت معامله"
          />
        </div>
        <div style={{ marginTop: 12 }}>
          <WeightInput
            label="وزن طلا (گرم)"
            value={weight ?? 0}
            onChange={(v) => setInput('second', 'weight', v)}
            placeholder="مثلاً ۱۲"
          />
        </div>
        <div style={{ marginTop: 12 }}>
          <PercentInput
            label="حاشیه سود (پورسانت)"
            hint={mode === 'buy' ? 'به قیمت خام اضافه می‌شود' : 'از قیمت خام کم می‌شود'}
            value={marginPct}
            onChange={(v) => setInput('second', 'marginPct', v)}
          />
        </div>
      </section>

      <section className="card">
        <div className="label-row">
          <span className="label">
            <span className="label-icon"><ScaleIcon size={16} /></span>
            مبدل مظنه (مثقال ۱۷ عیار تهران)
          </span>
        </div>
        <MoneyInput
          label="نرخ مظنه"
          value={mazaneh}
          onChange={(v) => setInput('second', 'mazaneh', v)}
          placeholder="مثلاً ۳۴٬۵۰۰٬۰۰۰"
          showWords={false}
        />
        {gram18FromMazaneh ? (
          <div style={{ marginTop: 10 }}>
            <div className="words-line">
              هر گرم ۱۸ عیار ≈ <b style={{ color: 'var(--gold-strong)' }}>{fmtMoney(gram18FromMazaneh)}</b> تومان
            </div>
            <button
              type="button"
              className="chip active"
              style={{ marginTop: 8 }}
              onClick={() => setManualGold18(gram18FromMazaneh)}
            >
              <StoreIcon size={15} />
              استفاده به‌عنوان نرخ طلای ۱۸ عیار
            </button>
          </div>
        ) : null}
      </section>

      {result && weight && gold18 > 0 ? (
        <ResultCard
          title={mode === 'buy' ? 'مبلغ کل پرداختی (خرید)' : 'مبلغ کل دریافتی (فروش)'}
          amount={fmtMoney(result.total)}
          words={words(result.total)}
          details={[
            {
              label: <>نرخ هر گرم ({fmtPercent(karat)} عیار)</>,
              value: `${fmtMoney(result.pricePerGram)} تومان`,
            },
            { label: <>ارزش خام ({fmtWeight(weight)} گرم)</>, value: `${fmtMoney(result.raw)} تومان` },
            {
              label: mode === 'buy' ? 'حاشیه سود طلافروش' : 'کسر حاشیه طلافروش',
              value: `${fmtMoney(result.margin)} تومان`,
              sub: `${fmtPercent(marginPct)}٪ — بدون اجرت و مالیات`,
            },
          ]}
        />
      ) : (
        <div className="empty-state">
          <div className="big">وزن و نرخ را وارد کن</div>
          محاسبه دست دوم بدون اجرت ساخت و مالیات است.
        </div>
      )}
    </div>
  );
}
