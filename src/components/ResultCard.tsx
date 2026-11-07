// کارت نتیجه بزرگ با جزئیات جمع‌شدنی

import React, { useState } from 'react';
import { Collapsible } from './ui';

export interface DetailRowData {
  label: React.ReactNode;
  value: string;
  sub?: string;
}

export function ResultCard({
  title,
  badge,
  badgeTone,
  amount,
  unit = 'تومان',
  words,
  details,
  detailsOpen: initialOpen = true,
  children,
  detailsLabel = 'جزئیات',
  stacked,
  noCollapse,
}: {
  title: React.ReactNode;
  badge?: React.ReactNode;
  amount: React.ReactNode;
  unit?: string;
  words?: string;
  details?: DetailRowData[];
  detailsOpen?: boolean;
  children?: React.ReactNode;
  detailsLabel?: string;
  badgeTone?: 'gold' | 'plain';
  stacked?: boolean; // واحد زیر عدد (مطابق طرح صفحه طلای نو)
  noCollapse?: boolean; // ردیف‌ها همیشه باز، بدون دکمه جزئیات
}) {
  const [open, setOpen] = useState(initialOpen);
  const rows = details?.map((d, i) => (
    <div className="detail-row" key={i}>
      <div>
        {d.label}
        {d.sub ? <div className="detail-sub">{d.sub}</div> : null}
      </div>
      <div className="detail-value">{d.value}</div>
    </div>
  ));
  return (
    <section className="result-card">
      <div className="result-head">
        <span className="result-title">{title}</span>
        {badge ? <span className={`chip-badge${badgeTone === 'gold' ? ' gold' : ''}`}>{badge}</span> : null}
      </div>
      <div className={`result-amount${stacked ? ' stacked' : ''}`}>
        {amount}
        <span className="amount-unit">{unit}</span>
      </div>
      {words ? <div className="words-line">معادل: {words}</div> : null}
      {children}
      {rows && rows.length > 0 ? (
        noCollapse ? (
          <div className="detail-rows">{rows}</div>
        ) : (
          <Collapsible open={open} onToggle={() => setOpen((v) => !v)} label={detailsLabel}>
            {rows}
          </Collapsible>
        )
      ) : null}
    </section>
  );
}
