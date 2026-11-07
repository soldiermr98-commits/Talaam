// تب «طلای نو» — وزن، اجرت (درصد یا ثابت)، سود و مالیات → مبلغ نهایی


import { calcNewGold, type MakingMode } from '../../lib/calc';
import { MAKING_QUICK_CHIPS } from '../../config';
import { useApp } from '../../state/AppContext';
import { MoneyInput, PercentInput, WeightInput } from '../../components/NumberInputs';
import { ResultCard } from '../../components/ResultCard';
import { Chip, Segmented } from '../../components/ui';
import { ShareIcon, ScaleIcon } from '../../components/icons';

export function NewGoldTab({ onShareInvoice }: { onShareInvoice: () => void }) {
  const { inputs, setInput, gold18, settings, fmtMoney, fmtWeight, fmtPercent, words } = useApp();
  const st = inputs.new;

  const weight = typeof st.weight === 'number' ? st.weight : null;
  const makingMode: MakingMode = st.makingMode === 'fixed' ? 'fixed' : 'percent';
  const makingValue =
    typeof st.makingValue === 'number' ? st.makingValue : makingMode === 'percent' ? 14 : 500_000;
  const profitPct = typeof st.profitPct === 'number' ? st.profitPct : settings.profitPct;
  const taxPct = typeof st.taxPct === 'number' ? st.taxPct : settings.taxPct;

  const result = weight && gold18 > 0 ? calcNewGold({ weight, gold18, makingMode, makingPercent: makingMode === 'percent' ? makingValue : undefined, makingFixedPerGram: makingMode === 'fixed' ? makingValue : undefined, profitPct, taxPct }) : null;

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
          <span className="label">
            <span className="label-icon"><ScaleIcon size={16} /></span>
            {makingMode === 'percent' ? 'اجرت ساخت (درصد)' : 'اجرت ساخت (تومان بر گرم)'}
          </span>
          <span className="hint">کارمزد کارگاه</span>
        </div>
        <div style={{ marginBottom: 10 }}>
          <Segmented
            compact
            options={[
              { key: 'percent', label: 'درصد' },
              { key: 'fixed', label: 'تومان/گرم' },
            ]}
            value={makingMode}
            onChange={(m) => setInput('new', 'makingMode', m)}
            ariaLabel="حالت اجرت ساخت"
          />
        </div>
        {makingMode === 'percent' ? (
          <>
            <PercentInput label="مقدار اجرت" value={makingValue} onChange={(v) => setInput('new', 'makingValue', v)} />
            <div className="chips" style={{ marginTop: 10 }}>
              <span className="hint" style={{ alignSelf: 'center' }}>پیش‌فرض:</span>
              {MAKING_QUICK_CHIPS.map((p) => (
                <Chip key={p} active={makingValue === p} onClick={() => setInput('new', 'makingValue', p)}>
                  +{fmtPercent(p)}٪
                </Chip>
              ))}
            </div>
          </>
        ) : (
          <MoneyInput
            label="اجرت هر گرم"
            value={makingValue}
            onChange={(v) => setInput('new', 'makingValue', v ?? 0)}
            placeholder="مثلاً ۵۰۰٬۰۰۰"
            showWords={false}
          />
        )}
      </section>

      <section className="card grid-2">
        <PercentInput label="سود طلافروش" value={profitPct} onChange={(v) => setInput('new', 'profitPct', v)} />
        <div>
          <PercentInput label="مالیات بر ارزش افزوده" value={taxPct} onChange={(v) => setInput('new', 'taxPct', v)} />
          <div className="hint" style={{ marginTop: 6 }}>فقط بر اجرت و سود محاسبه می‌شود</div>
        </div>
      </section>

      {result && weight ? (
        <ResultCard
          title="قیمت نهایی پرداختی"
          badge="فاکتور استاندارد"
          badgeTone="gold"
          amount={fmtMoney(result.total)}
          words={words(result.total)}
          details={[
            { label: <>ارزش طلای خام ({fmtWeight(weight)} گرم)</>, value: `${fmtMoney(result.raw)} تومان` },
            {
              label: 'اجرت ساخت',
              value: `${fmtMoney(result.making)} تومان`,
              sub: makingMode === 'percent' ? `${fmtPercent(makingValue)}٪ از ارزش خام` : `${fmtMoney(makingValue)} تومان برای هر گرم`,
            },
            { label: 'سود طلافروش', value: `${fmtMoney(result.profit)} تومان`, sub: `${fmtPercent(profitPct)}٪` },
            { label: 'مالیات بر ارزش افزوده', value: `${fmtMoney(result.tax)} تومان`, sub: `${fmtPercent(taxPct)}٪ فقط از سود و اجرت` },
          ]}
        />
      ) : (
        <div className="empty-state">
          <div className="big">نرخ و وزن را وارد کن</div>
          {gold18 <= 0 ? 'اول نرخ طلای ۱۸ عیار را از نوار بالا وارد کن.' : 'فقط وزن طلا را وارد کن تا محاسبه انجام شود.'}
        </div>
      )}

      {result ? (
        <div className="btn-row">
          <button type="button" className="btn btn-gold btn-block" onClick={onShareInvoice}>
            <ShareIcon size={19} />
            اشتراک‌گذاری فاکتور
          </button>
        </div>
      ) : null}
    </div>
  );
}
