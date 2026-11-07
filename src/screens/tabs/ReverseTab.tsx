// تب «چند درصد حساب کرده؟» — محاسبه معکوس اجرت و سود فروشنده


import { calcReverse } from '../../lib/calc';
import { VERDICT_THRESHOLDS } from '../../config';
import { useApp } from '../../state/AppContext';
import { MoneyInput, WeightInput } from '../../components/NumberInputs';
import { shareText } from '../../lib/share';
import { EyeIcon, RefreshIcon, ShareIcon } from '../../components/icons';

const VERDICTS = [
  { key: 'fair', label: 'منصفانه' },
  { key: 'slightly-high', label: 'کمی گران' },
  { key: 'expensive', label: 'گران' },
] as const;

export function ReverseTab() {
  const { inputs, setInput, gold18, settings, fmtMoney, fmtWeight, fmtPercent } = useApp();
  const st = inputs.reverse;

  const weight = typeof st.weight === 'number' ? st.weight : null;
  const finalPrice = typeof st.finalPrice === 'number' ? st.finalPrice : null;

  const result =
    weight && finalPrice && gold18 > 0
      ? calcReverse({
          weight,
          gold18,
          finalPrice,
          profitPct: settings.profitPct,
          taxPct: settings.taxPct,
          fairMaxPct: VERDICT_THRESHOLDS.fairMax,
          expensiveMaxPct: VERDICT_THRESHOLDS.expensiveMax,
        })
      : null;

  const barPct = result?.makingPct != null ? Math.max(2, Math.min(100, (result.makingPct / 30) * 100)) : 0;
  const barColor =
    result?.verdict === 'fair' ? 'var(--green)' : result?.verdict === 'slightly-high' ? 'var(--gold)' : 'var(--red)';

  const shareResult = () => {
    if (!result || !weight || !finalPrice) return;
    const lines = [
      'محاسبه معکوس طلام:',
      `وزن: ${fmtWeight(weight)} گرم — مبلغ اعلامی: ${fmtMoney(finalPrice)} تومان`,
      `اضافه روی قیمت خام طلا: ${fmtPercent(result.premiumPct)}٪`,
      result.makingPct != null ? `مجموع تقریبی اجرت، سود و مالیات: ${fmtPercent(result.makingPct)}٪ (با فرض سود ${fmtPercent(settings.profitPct)}٪ و مالیات ${fmtPercent(settings.taxPct)}٪)` : '',
      '— محاسبه تقریبی، اپلیکیشن طلام',
    ].filter(Boolean);
    void shareText('نتیجه محاسبه طلام', lines.join('\n'));
  };

  const reset = () => {
    setInput('reverse', 'weight', null);
    setInput('reverse', 'finalPrice', null);
  };

  return (
    <div>
      <section className="card">
        <WeightInput
          label="وزن طلا (گرم)"
          hint="دقت تا هزارم"
          value={weight ?? 0}
          onChange={(v) => setInput('reverse', 'weight', v)}
          placeholder="مثلاً ۳.۵"
        />
      </section>

      <section className="card">
        <MoneyInput
          label="قیمت اعلامی فروشنده (کل پرداختی)"
          icon={<EyeIcon size={16} />}
          value={finalPrice ?? 0}
          onChange={(v) => setInput('reverse', 'finalPrice', v)}
          placeholder="مثلاً ۲۱٬۵۰۰٬۰۰۰"
        />
      </section>

      {result && weight && finalPrice ? (
        result.verdict === 'below-raw' ? (
          <div className="empty-state">
            <div className="big">ورودی‌ها را چک کن</div>
            مبلغ اعلامی ({fmtMoney(finalPrice)} تومان) از ارزش خام طلا ({fmtMoney(result.raw)} تومان) کمتر است.
          </div>
        ) : (
          <>
            <section className="result-card">
              <div className="result-head">
                <span className="result-title">
                  اضافه روی قیمت خام طلا
                  <span className="chip-badge">تقریبی</span>
                </span>
                <span className={`chip-badge${result.verdict === 'fair' ? ' gold' : ''}`}>
                  {result.verdict === 'fair' ? 'منصفانه' : result.verdict === 'slightly-high' ? 'کمی گران' : 'گران'}
                </span>
              </div>
              <div className="result-amount">
                ٪{fmtPercent(result.premiumPct)}
                <span className="amount-unit">بدون هیچ فرضی</span>
              </div>
              <div className="words-line">
                مجموع تقریبی اجرت، سود و مالیات: <b style={{ color: 'var(--gold-strong)' }}>٪{fmtPercent(result.makingPct ?? 0)}</b>
                {' '}— با فرض سود {fmtPercent(settings.profitPct)}٪ و مالیات {fmtPercent(settings.taxPct)}٪
              </div>

              <div className="chips" style={{ marginTop: 12 }}>
                {VERDICTS.map((v) => {
                  const active = result.verdict === v.key;
                  const hint =
                    v.key === 'fair' ? `تا ${fmtPercent(VERDICT_THRESHOLDS.fairMax)}٪` : v.key === 'expensive' ? `بالای ${fmtPercent(VERDICT_THRESHOLDS.expensiveMax)}٪` : '';
                  return (
                    <span key={v.key} className={active ? 'chip-medium' : 'chip-badge'}>
                      {v.label} {hint ? `(${hint})` : ''}
                    </span>
                  );
                })}
              </div>
              <div className="progress">
                <div style={{ width: `${barPct}%`, background: barColor }} />
              </div>

              <div className="detail-rows" style={{ marginTop: 12 }}>
                <div className="detail-row">
                  <div>ارزش خام طلا</div>
                  <div className="detail-value">{fmtMoney(result.raw)} تومان</div>
                </div>
                <div className="detail-row">
                  <div>اضافه پرداختی</div>
                  <div className="detail-value">{fmtMoney(result.extraPaid)} تومان</div>
                </div>
                {result.making != null ? (
                  <div className="detail-row">
                    <div>
                      اجرت ضمنی
                      <div className="detail-sub">شامل مجموع سود طلافروش، اجرت ساخت کارگاه و مالیات</div>
                    </div>
                    <div className="detail-value">{fmtMoney(result.making)} تومان</div>
                  </div>
                ) : null}
              </div>
              <div className="words">محاسبه تقریبی است؛ به قیمت توافقی نهایی معامله توجه کن.</div>
            </section>

            <div className="btn-row">
              <button type="button" className="btn btn-outline" onClick={reset}>
                <RefreshIcon size={18} />
                محاسبه مجدد
              </button>
              <button type="button" className="btn btn-gold" onClick={shareResult}>
                <ShareIcon size={18} />
                اشتراک‌گذاری نتیجه
              </button>
            </div>
          </>
        )
      ) : (
        <div className="empty-state">
          <div className="big">وزن و مبلغ فروشنده را وارد کن</div>
          می‌فهمیم فروشنده چند درصد اجرت و سود روی قیمت خام طلا حساب کرده.
        </div>
      )}
    </div>
  );
}
