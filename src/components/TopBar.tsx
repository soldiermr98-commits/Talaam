// نوار بالا: تنظیمات، لوگو، وضعیت آنلاین — مطابق طرح

import { useApp } from '../state/AppContext';
import { GearIcon, WifiIcon, WifiOffIcon } from './icons';

export function TopBar({ onSettings }: { onSettings: () => void }) {
  const { online } = useApp();
  return (
    <header className="topbar">
      <button type="button" className="icon-btn" onClick={onSettings} aria-label="تنظیمات">
        <GearIcon />
      </button>
      <div className="logo">
        <span>طلام</span>
        <span className="logo-chip logo-letter">ط</span>
      </div>
      <div className={`status-chip${online ? '' : ' off'}`}>
        <span className="dot" />
        {online ? <WifiIcon size={14} /> : <WifiOffIcon size={14} />}
        <span>{online ? 'آنلاین' : 'آفلاین'}</span>
      </div>
    </header>
  );
}
