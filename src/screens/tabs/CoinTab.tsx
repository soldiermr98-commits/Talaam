// تب «حباب سکه» — قیمت بازار، ارزش ذاتی، مبلغ و درصد حباب + مقایسه سایر قطعات

import { useState } from 'react';
import { calcCoinBubble, type CoinKey } from '../../lib/calc';
import { BUBBLE_THRESHOLDS, COIN_SPECS } from '../../config';
import { useApp } from '../../state/AppContext';
import { MoneyInput } from '../../components/NumberInputs';
import { ResultCard } from '../../components/ResultCard';
import { Chip, Collapsible } from '../../components/ui';
import { DollarIcon, RefreshIcon, StoreIcon } from '../../components/icons';

const LEVEL_LABEL = { low: 'حباب کم', medium: 'حباب متوسط', high: 'حباب زیاد' } as const;
const SHORT_LABEL = { emami: 'امامی', bahar: 'بهار', nim: 'نیم', rob: 'ربع', gerami: 'گرمی' } as const;

export function CoinTab() {
  const { inputs, setInput, coins, gold18, fmtMoney, fmtPercent, fmtWeight, words, refreshPrices, refreshing } = useApp();
  const st = inputs.coin;
  const [formulaOpen, setFormulaOpen] = useState(false);

  const coinKey: CoinKey = st.key && st.key in COIN_SPECS ? (st.key as CoinKey) : 'emami';
  const spec = COIN_SPECS[coinKey];
  const marketFromPrices = coins ? coins[coinKey] : 0;
  const market = typeof st.market === 'number' && st.market > 0 ? st.market : marketFromPrices;

  const bubble = market > 0 && gold18 > 0 ? calcCoinBubble({ marketPrice: market, gold18, weight: spec.weight, purity: spec.purity }, BUBBLE_THRESHOLDS) : null;

  const intrinsicShare = bubble && market > 0 ? Math.max(0, Math.min(100, (bubble.intrinsic / market) * 100)) : 0;

  return (
    <div>
      <section className="card">
        <div className="label-row">
          <span className="label">انتخاب سکه</span>
          <span className="hint">قیمت بازار</span>
        </div>
        <div className="chips">
          {(Object.keys(COIN_SPECS) as CoinKey[]).map((k) => (
            <Chip key={k} active={coinKey === k} onClick={() => setInput('coin', 'key', k)}>
              {coinKey === k ? <DollarIcon size={14} /> : null}
              {SHORT_LABEL[k]}
            </Chip>
          ))}
        </div>
        <div style={{ marginTop: 12 }}>
          <MoneyInput
            label={`قیمت بازار ${spec.label}`}
            hint={marketFromPrices > 0 ? 'از نرخ‌های دریافتی' : 'دستی وارد کن'}
            value={market}
            onChange={(v) => setInput('coin', 'market', v)}
            placeholder="مثلاً ۵۱٬۲۰۰٬۰۰۰"
          />
        </div>
      </section>

      {bubble ? (
        <>
          <ResultCard
            title={spec.label}
            badge={
              <span className={`chip-${bubble.level}`}>
                {LEVEL_LABEL[bubble.level]} — {fmtPercent(bubble.bubblePct)}٪
              </span>
            }
            amount={fmtMoney(bubble.bubble)}
            words={words(bubble.bubble)}
            details={[
              { label: 'قیمت در بازار آزاد', value: `${fmtMoney(market)} تومان` },
              { label: 'ارزش ذاتی طلای خام', value: `${fmtMoney(bubble.intrinsic)} تومان` },
              {
                label: 'مشخصات سکه',
                value: `${fmtWeight(spec.weight)} گرم — عیار ${fmtPercent(spec.purity * 1000)}`,
                sub: `طلای خالص: ${fmtWeight(bubble.pureGold)} گرم`,
              },
              { label: 'درصد حباب', value: `${fmtPercent(bubble.bubblePct)}٪` },
            ]}
          >
            <div className="coin-bar" aria-hidden="true">
              <div className="seg" style={{ width: `${intrinsicShare}%`, background: 'var(--gold)' }} />
              <div className="seg" style={{ width: `${100 - intrinsicShare}%`, background: 'var(--red)' }} />
            </div>
            <div className="legend-row">
              <span>ارزش واقعی طلا {fmtPercent(intrinsicShare)}٪</span>
              <span>حباب و پریمیوم {fmtPercent(100 - intrinsicShare)}٪</span>
            </div>
            <Collapsible open={formulaOpen} onToggle={() => setFormulaOpen((v) => !v)} label="نحوه محاسبه">
              <div className="formula-box">
                طلای خالص = وزن × خلوص ({fmtPercent(spec.purity * 100)}٪)
                <br />
                ارزش ذاتی = طلای خالص ÷ ۰.۷۵ × نرخ گرم ۱۸ عیار
                <br />
                حباب = قیمت بازار − ارزش ذاتی
                <br />
                درصد حباب = حباب ÷ ارزش ذاتی × ۱۰۰
              </div>
            </Collapsible>
          </ResultCard>

          <section className="card">
            <div className="label-row">
              <span className="label">مقایسه حباب سایر قطعات</span>
              <span className="hint">بر اساس نرخ‌های فعلی</span>
            </div>
            <div className="chips">
              {(Object.keys(COIN_SPECS) as CoinKey[])
                .filter((k) => k !== coinKey)
                .map((k) => {
                  const s = COIN_SPECS[k];
                  const price = coins ? coins[k] : 0;
                  if (price <= 0 || gold18 <= 0) return null;
                  const b = calcCoinBubble({ marketPrice: price, gold18, weight: s.weight, purity: s.purity }, BUBBLE_THRESHOLDS);
                  return (
                    <span key={k} className={`chip-${b.level}`}>
                      {SHORT_LABEL[k]}: {fmtPercent(b.bubblePct)}٪
                    </span>
                  );
                })}
            </div>
          </section>

          <div className="btn-row">
            <button type="button" className="btn btn-gold btn-block" onClick={() => refreshPrices(true)} disabled={refreshing}>
              <RefreshIcon size={19} />
              {refreshing ? 'در حال بروزرسانی…' : 'بروزرسانی نرخ‌ها'}
            </button>
          </div>
          <p className="footer-note">
            <StoreIcon size={14} />
            نرخ‌های سکه از endpoint پیکربندی‌شده خوانده می‌شود؛ آفلاین از آخرین نرخ ذخیره‌شده استفاده می‌کنیم.
          </p>
        </>
      ) : (
        <div className="empty-state">
          <div className="big">قیمت سکه را وارد کن</div>
          {gold18 <= 0 ? 'برای محاسبه حباب، هم نرخ گرم ۱۸ عیار و هم قیمت سکه لازم است.' : 'قیمت بازار سکه را در کارد بالا وارد کن.'}
        </div>
      )}
    </div>
  );
}
