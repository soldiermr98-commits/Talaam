// تب «طلای نو» — بازطراحی عین طرح: وزن، اجرت (درصد/ثابت)، سود، قانون مالیات، معادل، نتیجه

import { calcNewGold, type MakingMode } from '../../lib/calc';
import { MAKING_QUICK_CHIPS } from '../../config';
import { useApp } from '../../state/AppContext';
import { MoneyInput, PercentInput, WeightInput } from '../../components/NumberInputs';
import { ResultCard } from '../../components/ResultCard';
import { Chip, Toggle } from '../../components/ui';
import { PencilIcon, ShareIcon } from '../../components/icons';

export function NewGoldTab({ onShareInvoice }: { onShareInvoice: () => void }) {
  const { inputs, setInput, gold18, settings, fmtMoney, fmtWeight, fmtPercent, words } = useApp();
  const st = inputs.new;

  const weight = typeof st.weight === 'number' ? st.weight : null;
  const makingMode: MakingMode = st.makingMode === 'fixed' ? 'fixed' : 'percent';
  const makingValue =
    typeof st.makingValue === 'number' ? st.makingValue : makingMode === 'percent' ? 14 : 500_000;
  const profitPct = typeof st.profitPct === 'number' ? st.profitPct : settings.profitPct;
  const taxPct = settings.taxPct;

  const toggleUnit = () => setInput('new', 'makingMode', makingMode === 'percent' ? 'fixed' : 'percent');

  const result =
    weight && gold18 > 0
      ? calcNewGold({
          weight,
          gold18,
          makingMode,
          makingPercent: makingMode === 'percent' ? makingValue : undefined,
          makingFixedPerGram: makingMode === 'fixed' ? makingValue : undefined,
          profitPct,
          taxPct,
        })
      : null;

  return (
    <div>
      <section className="card">
        <WeightInput
          label="وزن طلا (گرم)"
          hint="۱۸ عیار استاندارد"
          value={weight ?? 0}
          onChange={(v) => setInput('new', 'weight', v)}
          placeholder="مثلاً ۴.۲۵"
        />
      </section>

      <section className="card">
        <div className="label-row">
          <span className="label">{makingMode === 'percent' ? 'اجرت ساخت (درصد)' : 'اجرت ساخت (تومان/گرم)'}</span>
          <span className="hint">کارمزد کارگاه</span>
        </div>
        {makingMode === 'percent' ? (
          <PercentInput
            bare
            value={makingValue}
            onChange={(v) => setInput('new', 'makingValue', v)}
            onUnitClick={toggleUnit}
          />
        ) : (
          <MoneyInput
            bare
            unitLabel="تومان/گرم"
            value={makingValue}
            onChange={(v) => setInput('new', 'makingValue', v ?? 0)}
            placeholder="مثلاً ۵۰۰٬۰۰۰"
            onUnitClick={toggleUnit}
          />
        )}
        {makingMode === 'percent' ? (
          <div className="chips" style={{ marginTop: 12 }}>
            <span className="hint" style={{ alignSelf: 'center' }}>پیش‌فرض:</span>
            {MAKING_QUICK_CHIPS.map((p) => (
              <Chip key={p} active={makingValue === p} onClick={() => setInput('new', 'makingValue', p)}>
                +{fmtPercent(p)}٪
              </Chip>
            ))}
          </div>
        ) : (
          <div className="hint" style={{ marginTop: 10 }}>
            برای بازگشت به حالت درصدی، روی «تومان/گرم» بزن.
          </div>
        )}
      </section>

      <div className="grid-2">
        <section className="card">
          <PercentInput label="سود طلافروش" value={profitPct} onChange={(v) => setInput('new', 'profitPct', v)} />
        </section>
        <section className="card tax-card">
          <div className="tax-title">قانون مالیات ({fmtPercent(taxPct)}٪)</div>
          <div className="toggle-row">
            <span className="hint">فقط بر اجرت و سود</span>
            <Toggle on label="مالیات فقط بر اجرت و سود اعمال می‌شود" />
          </div>
        </section>
      </div>

      {result ? (
        <div className="words-card">
          <PencilIcon size={14} />
          <span className="t">معادل: {words(result.total)}</span>
        </div>
      ) : null}

      {result && weight ? (
        <>
          <ResultCard
            title="قیمت نهایی پرداختی"
            badge="فاکتور استاندارد تجاری"
            badgeTone="gold"
            amount={fmtMoney(result.total)}
            stacked
            noCollapse
            details={[
              { label: <>ارزش طلای خام ({fmtWeight(weight)} گرم):</>, value: `${fmtMoney(result.raw)} تومان` },
              {
                label: makingMode === 'percent' ? <>اجرت ساخت ({fmtPercent(makingValue)}٪):</> : <>اجرت ساخت:</>,
                value: `${fmtMoney(result.making)} تومان`,
              },
              { label: <>سود طلافروشنده ({fmtPercent(profitPct)}٪):</>, value: `${fmtMoney(result.profit)} تومان` },
              { label: <>مالیات بر ارزش افزوده ({fmtPercent(taxPct)}٪):</>, value: `${fmtMoney(result.tax)} تومان` },
            ]}
          />
          <div className="btn-row">
            <button type="button" className="btn btn-gold btn-block" onClick={onShareInvoice}>
              <ShareIcon size={19} />
              اشتراک‌گذاری فاکتور
            </button>
          </div>
        </>
      ) : (
        <div className="empty-state">
          <div className="big">نرخ و وزن را وارد کن</div>
          {gold18 <= 0 ? 'اول نرخ طلای ۱۸ عیار را از نوار بالا وارد کن.' : 'فقط وزن طلا را وارد کن تا محاسبه انجام شود.'}
        </div>
      )}
    </div>
  );
}
