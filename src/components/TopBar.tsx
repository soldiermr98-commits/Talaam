// نوار بالا: تنظیمات، لوگو، وضعیت آنلاین

import { useApp } from '../state/AppContext';
import { GearIcon, GemIcon, WifiIcon, WifiOffIcon } from './icons';

export function TopBar({ onSettings }: { onSettings: () => void }) {
  const { online } = useApp();
  return (
    <header className="topbar">
      <button type="button" className="icon-btn" onClick={onSettings} aria-label="تنظیمات">
        <GearIcon />
      </button>
      <div className="logo">
        <span>طلام</span>
        <span className="logo-chip">
          <GemIcon size={18} />
        </span>
      </div>
      <div className={`status-chip${online ? '' : ' off'}`}>
        <span className="dot" />
        {online ? <WifiIcon size={14} /> : <WifiOffIcon size={14} />}
        <span>{online ? 'آنلاین' : 'آفلاین'}</span>
      </div>
    </header>
  );
}
