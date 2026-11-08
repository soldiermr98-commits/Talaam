// نوار بالا: تنظیمات، لوگو و نام برند «طلام»، وضعیت آنلاین/آفلاین

import { useApp } from '../state/AppContext';
import { CoinDollarIcon, GearIcon, WifiIcon, WifiOffIcon } from './icons';

export function TopBar({ onSettings }: { onSettings: () => void }) {
  const { online, refreshPrices, refreshing } = useApp();
  return (
    <header className="topbar">
      <button type="button" className="icon-btn" onClick={onSettings} aria-label="تنظیمات">
        <GearIcon size={21} />
      </button>
      <div className="brand-lockup" aria-label="طلام">
        <span className="brand-badge">
          <CoinDollarIcon size={18} />
        </span>
        <span className="brand-title">طلام</span>
      </div>
      <button
        type="button"
        className={`icon-btn status-icon-btn${online ? ' online' : ' off'}`}
        onClick={() => void refreshPrices(true)}
        disabled={refreshing}
        aria-label={online ? 'وضعیت اتصال: آنلاین (بروزرسانی)' : 'وضعیت اتصال: آفلاین'}
        title={online ? 'آنلاین — ضربه برای بروزرسانی' : 'آفلاین'}
      >
        {online ? <WifiIcon size={20} /> : <WifiOffIcon size={20} />}
      </button>
    </header>
  );
}
