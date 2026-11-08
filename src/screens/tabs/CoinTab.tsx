// تب «حباب سکه» — بازطراحی کامل مطابق طرح مرجع (کارت یکپارچه، گیج دوگانه و مقایسه قطعات)

import { useState } from 'react';
import { calcCoinBubble, type CoinKey } from '../../lib/calc';
import { BUBBLE_THRESHOLDS, COIN_SPECS } from '../../config';
import { useApp } from '../../state/AppContext';
import { MoneyInput } from '../../components/NumberInputs';
import { Sheet } from '../../components/ui';
import {
  CandlestickIcon,
  CheckIcon,
  CoinDollarIcon,
  JewelerScaleIcon,
  PencilIcon,
  RefreshIcon,
  StarFilledIcon,
  StoreIcon,
} from '../../components/icons';

const CHIP_LABEL: Record<CoinKey, string> = {
  emami: 'سکه امامی',
  bahar: 'بهار آزادی',
  nim: 'نیم سکه',
  rob: 'ربع سکه',
  gerami: 'سکه گرمی',
};

const FULL_TITLE: Record<CoinKey, string> = {
  emami: 'سکه تمام طرح امامی',
  bahar: 'سکه تمام بهار آزادی',
  nim: 'نیم سکه بهار آزادی',
  rob: 'ربع سکه بهار آزادی',
  gerami: 'سکه یک گرمی',
};

export function CoinTab() {
  const {
    inputs,
    setInput,
    coins,
    gold18,
    prices,
    isManual,
    updatedAtText,
    fmtMoney,
    fmtPercent,
    fmtWeight,
    refreshPrices,
    refreshing,
  } = useApp();
  const st = inputs.coin;
  const [editingPrice, setEditingPrice] = useState(false);

  const coinKey: CoinKey = st.key && st.key in COIN_SPECS ? (st.key as CoinKey) : 'emami';
  const spec = COIN_SPECS[coinKey];
  const marketFromPrices = coins ? coins[coinKey] : 0;
  const customMarket = typeof st.market === 'number' && st.market > 0 ? st.market : 0;
  const market = customMarket > 0 ? customMarket : marketFromPrices;

  const bubble =
    market > 0 && gold18 > 0
      ? calcCoinBubble({ marketPrice: market, gold18, weight: spec.weight, purity: spec.purity }, BUBBLE_THRESHOLDS)
      : null;

  const intrinsicShare = bubble && market > 0 ? Math.max(0, Math.min(100, (bubble.intrinsic / market) * 100)) : 0;
  const bubbleShare = bubble ? Math.max(0, 100 - intrinsicShare) : 0;

  const sourceLabel = !isManual ? prices?.source_label || prices?.source || '' : 'نرخ دستی';

  const otherCoins = (Object.keys(COIN_SPECS) as CoinKey[])
    .filter((k) => k !== coinKey)
    .map((k) => {
      const s = COIN_SPECS[k];
      const p = coins ? coins[k] : 0;
      if (p <= 0 || gold18 <= 0) return null;
      const b = calcCoinBubble({ marketPrice: p, gold18, weight: s.weight, purity: s.purity }, BUBBLE_THRESHOLDS);
      return { key: k, label: CHIP_LABEL[k], bubble: b };
    })
    .filter((item): item is NonNullable<typeof item> => item !== null);

  return (
    <div className="coin-tab-wrap">
      {/* ردیف افقی چیپ‌های انتخاب سکه */}
      <section aria-label="انتخاب سکه">
        <div className="coin-chips-scroll">
          {(Object.keys(COIN_SPECS) as CoinKey[]).map((k) => {
            const active = coinKey === k;
            return (
              <button
                key={k}
                type="button"
                className={`coin-select-chip${active ? ' active' : ''}`}
                onClick={() => {
                  setInput('coin', 'key', k);
                  setInput('coin', 'market', null);
                }}
              >
                {active ? <StarFilledIcon size={15} /> : null}
                <span>{CHIP_LABEL[k]}</span>
              </button>
            );
          })}
        </div>
      </section>

      {/* کارت اصلی محاسبه سکه */}
      <section className="coin-main-card">
        <div className="coin-card-top-glow" aria-hidden="true" />

        {/* هدر مشخصات سکه */}
        <div className="coin-head">
          <div className="coin-head-right">
            <div className="coin-head-icon">
              <CoinDollarIcon size={22} />
            </div>
            <div>
              <h1 className="coin-head-title">{FULL_TITLE[coinKey]}</h1>
              <p className="coin-head-sub">
                وزن: {fmtWeight(spec.weight)} گرم | عیار {fmtPercent(spec.purity * 1000)} ({fmtPercent(spec.purity * 24)})
              </p>
            </div>
          </div>

          {bubble ? (
            <div className={`coin-status-badge level-${bubble.level}`}>
              <span className="dot" />
              <span>٪{fmtPercent(bubble.bubblePct)} حباب</span>
            </div>
          ) : null}
        </div>

        {bubble ? (
          <>
            {/* باکس مرکزی میزان حباب قیمت */}
            <div className="coin-hero-box">
              <span className="coin-hero-label">میزان حباب قیمت</span>
              <div className="coin-hero-amount-row">
                <span className="coin-hero-number">{fmtMoney(bubble.bubble)}</span>
                <span className="coin-hero-unit">تومان</span>
              </div>
              <p className="coin-hero-caption">اختلاف قیمت معامله با ارزش خالص طلای موجود</p>
            </div>

            {/* ردیف‌های تفکیک قیمت بازار و ارزش ذاتی */}
            <div className="coin-metrics">
              <button
                type="button"
                className="coin-metric-row clickable"
                onClick={() => setEditingPrice(true)}
                title="ضربه برای ویرایش قیمت بازار سکه"
              >
                <div className="coin-metric-label">
                  <StoreIcon size={18} />
                  <span>قیمت در بازار آزاد</span>
                  <span className="coin-edit-pill">
                    <PencilIcon size={12} />
                    {customMarket > 0 ? 'دستی' : 'ویرایش'}
                  </span>
                </div>
                <div className="coin-metric-val">
                  <span className="num">{fmtMoney(market)}</span>
                  <span className="unit">تومان</span>
                </div>
              </button>

              <div className="coin-metric-row">
                <div className="coin-metric-label gold-icon">
                  <JewelerScaleIcon size={18} />
                  <span>ارزش ذاتی طلای خام</span>
                </div>
                <div className="coin-metric-val gold">
                  <span className="num">{fmtMoney(bubble.intrinsic)}</span>
                  <span className="unit">تومان</span>
                </div>
              </div>
            </div>

            {/* گیج نسبت ارزش واقعی و حباب */}
            <div className="coin-gauge-wrap">
              <div className="coin-gauge-legend">
                <span className="leg-gold">
                  <span className="dot" />
                  ارزش واقعی طلا ({fmtPercent(intrinsicShare)}٪)
                </span>
                <span className="leg-err">
                  <span className="dot" />
                  حباب و پریمیوم ({fmtPercent(bubbleShare)}٪)
                </span>
              </div>
              <div className="coin-gauge-track" aria-hidden="true">
                <div className="seg-gold" style={{ width: `${intrinsicShare}%` }} />
                <div className="seg-err" style={{ width: `${bubbleShare}%` }} />
              </div>
            </div>
          </>
        ) : (
          <div style={{ marginTop: 14 }}>
            <MoneyInput
              label={`قیمت بازار ${CHIP_LABEL[coinKey]}`}
              hint="نرخ به تومان"
              value={market}
              onChange={(v) => setInput('coin', 'market', v)}
              placeholder="مثلاً ۵۱٬۲۰۰٬۰۰۰"
            />
          </div>
        )}
      </section>

      {/* بخش مقایسه حباب سایر قطعات */}
      {otherCoins.length > 0 ? (
        <section className="coin-compare-card">
          <div className="coin-compare-head">
            <h2 className="coin-compare-title">
              <CandlestickIcon size={18} />
              <span>مقایسه حباب سایر قطعات</span>
            </h2>
            <span className="coin-compare-sub">بر اساس نرخ لحظه‌ای</span>
          </div>
          <div className="coin-compare-grid">
            {otherCoins.map((item) => (
              <button
                key={item.key}
                type="button"
                className="coin-mini-card"
                onClick={() => {
                  setInput('coin', 'key', item.key);
                  setInput('coin', 'market', null);
                }}
              >
                <div className="coin-mini-top">
                  <span className="coin-mini-name">{item.label}</span>
                  <span className={`coin-mini-pct level-${item.bubble.level}`}>
                    {fmtPercent(item.bubble.bubblePct)}٪
                  </span>
                </div>
                <div className="coin-mini-bottom">
                  <span>حباب:</span>
                  <span className="coin-mini-val">{fmtMoney(item.bubble.bubble)} ت</span>
                </div>
              </button>
            ))}
          </div>
        </section>
      ) : null}

      {/* دکمه بروزرسانی زنده نرخ‌ها */}
      <section className="coin-refresh-sec">
        <button
          type="button"
          className="coin-refresh-btn"
          onClick={() => void refreshPrices(true)}
          disabled={refreshing}
        >
          <RefreshIcon size={20} />
          <span>{refreshing ? 'در حال بروزرسانی…' : 'بروزرسانی زنده نرخ‌ها'}</span>
        </button>
        <p className="coin-refresh-meta">
          آخرین به‌روزرسانی: {updatedAtText || 'نامشخص'}
          {sourceLabel ? ` از ${sourceLabel}` : ''}
        </p>
      </section>

      {editingPrice ? (
        <Sheet
          onClose={() => setEditingPrice(false)}
          title={`قیمت بازار ${CHIP_LABEL[coinKey]}`}
          subtitle="می‌توانی قیمت بازار آزاد این سکه را دستی وارد کنی"
        >
          <MoneyInput
            label="قیمت در بازار آزاد"
            value={market}
            onChange={(v) => setInput('coin', 'market', v)}
            placeholder="مثلاً ۵۱٬۲۰۰٬۰۰۰"
          />
          <div className="btn-row" style={{ marginTop: 14 }}>
            {customMarket > 0 && marketFromPrices > 0 ? (
              <button
                type="button"
                className="btn btn-outline"
                onClick={() => {
                  setInput('coin', 'market', null);
                  setEditingPrice(false);
                }}
              >
                بازگشت به نرخ خودکار
              </button>
            ) : null}
            <button type="button" className="btn btn-gold" onClick={() => setEditingPrice(false)}>
              <CheckIcon size={18} />
              تأیید
            </button>
          </div>
        </Sheet>
      ) : null}
    </div>
  );
}
