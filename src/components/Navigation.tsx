// تب‌های بالایی و نوار ناوبری پایین

import React from 'react';
import type { TabKey } from '../state/AppContext';
import { CalculatorIcon, CoinsIcon, DollarIcon, GemIcon } from './icons';

const TOP_TABS: { key: TabKey; label: string }[] = [
  { key: 'new', label: 'طلای نو' },
  { key: 'coin', label: 'حباب سکه' },
  { key: 'second', label: 'دست دوم' },
  { key: 'reverse', label: 'درصد حساب' },
];

export function TabBar({ tab, onChange }: { tab: TabKey; onChange: (t: TabKey) => void }) {
  return (
    <nav className="tabbar" aria-label="انتخاب محاسبه">
      {TOP_TABS.map((t) => (
        <button key={t.key} type="button" className={`tab-pill${tab === t.key ? ' active' : ''}`} onClick={() => onChange(t.key)}>
          {t.label}
        </button>
      ))}
    </nav>
  );
}

const NAV_ITEMS: { key: TabKey; label: string; icon: (size: number) => React.ReactNode }[] = [
  { key: 'new', label: 'طلای نو', icon: (s) => <GemIcon size={s} /> },
  { key: 'second', label: 'دست دوم و آب‌شده', icon: (s) => <CoinsIcon size={s} /> },
  { key: 'coin', label: 'حباب سکه', icon: (s) => <DollarIcon size={s} /> },
  { key: 'reverse', label: 'درصد حساب', icon: (s) => <CalculatorIcon size={s} /> },
];

export function BottomNav({ tab, onChange }: { tab: TabKey; onChange: (t: TabKey) => void }) {
  return (
    <nav className="bottom-nav" aria-label="ناوبری اصلی">
      <div className="inner">
        {NAV_ITEMS.map((item) => (
          <button key={item.key} type="button" className={`nav-item${tab === item.key ? ' active' : ''}`} onClick={() => onChange(item.key)} aria-current={tab === item.key ? 'page' : undefined}>
            {item.icon(21)}
            <span>{item.label}</span>
          </button>
        ))}
      </div>
    </nav>
  );
}
