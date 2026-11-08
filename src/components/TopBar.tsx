// نوار بالا: تنظیمات در راست، آیکون رسمی «طلام» دقیقاً وسط در وسط هدر، وضعیت اتصال در چپ

import { useApp } from '../state/AppContext';
import { GearIcon, WifiIcon, WifiOffIcon } from './icons';

export function TopBar({ onSettings }: { onSettings: () => void }) {
  const { online, refreshPrices, refreshing } = useApp();
  return (
    <header className="topbar">
      <button type="button" className="icon-btn" onClick={onSettings} aria-label="تنظیمات">
        <GearIcon size={22} />
      </button>
      <div className="logo-center" aria-label="طلام">
        <img src="icons/icon-192.png" alt="طلام" className="logo-img" draggable={false} />
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
