// شیت تنظیمات: تم، ارقام، درصدهای پیش‌فرض، نرخ دستی، درباره و لینک‌های فروشگاه


import {
  APP_VERSION,
  BAZAAR_RATE_URL,
  DEFAULT_SETTINGS,
  MYKET_APP_URL,
  SHARE_APP_TEXT,
} from '../config';
import { useApp } from '../state/AppContext';
import { Sheet, Segmented } from './ui';
import { PercentInput } from './NumberInputs';
import { GemIcon, ShareIcon, StarIcon } from './icons';
import { shareText } from '../lib/share';

export function SettingsSheet({ onClose }: { onClose: () => void }) {
  const { settings, setSettings, isManual, updatedAtText, setManualGold18, refreshPrices, num } = useApp();

  return (
    <Sheet onClose={onClose} title="تنظیمات طلام" subtitle="پیش‌تنظیم شخصی محاسبات" headerIcon={<GemIcon size={20} />}>
      <div className="label-row">
        <span className="label">حالت نمایش</span>
      </div>
      <Segmented
        options={[
          { key: 'system', label: 'سیستم' },
          { key: 'dark', label: 'تیره' },
          { key: 'light', label: 'روشن' },
        ]}
        value={settings.theme}
        onChange={(v) => setSettings({ theme: v })}
        ariaLabel="تم"
      />

      <div className="label-row" style={{ marginTop: 14 }}>
        <span className="label">نمایش ارقام</span>
      </div>
      <Segmented
        options={[
          { key: 'fa', label: 'فارسی' },
          { key: 'en', label: 'انگلیسی' },
        ]}
        value={settings.digits}
        onChange={(v) => setSettings({ digits: v })}
        ariaLabel="نوع ارقام"
      />

      <div className="divider" />
      <div className="label-row">
        <span className="label">درصدهای پیش‌فرض محاسبات</span>
      </div>
      <div className="grid-2">
        <div>
          <PercentInput
            label="مالیات (٪)"
            value={settings.taxPct}
            onChange={(v) => setSettings({ taxPct: v ?? DEFAULT_SETTINGS.taxPct })}
          />
        </div>
        <div>
          <PercentInput
            label="سود طلافروش (٪)"
            value={settings.profitPct}
            onChange={(v) => setSettings({ profitPct: v ?? DEFAULT_SETTINGS.profitPct })}
          />
        </div>
        <div>
          <PercentInput
            label="حاشیه دست دوم (٪)"
            value={settings.secondHandMarginPct}
            onChange={(v) => setSettings({ secondHandMarginPct: v ?? DEFAULT_SETTINGS.secondHandMarginPct })}
          />
        </div>
      </div>

      <div className="divider" />
      <div className="row-group">
        <div>
          <div className="label">نرخ طلای ۱۸ عیار</div>
          <div className="hint" style={{ marginTop: 4 }}>
            {isManual ? 'الان روی نرخ دستی است' : `بروزرسانی: ${updatedAtText || '—'}`}
          </div>
        </div>
        <button
          type="button"
          className="chip"
          disabled={!isManual}
          style={{ opacity: isManual ? 1 : 0.5 }}
          onClick={() => {
            setManualGold18(null);
            void refreshPrices(true);
            onClose();
          }}
        >
          بازگشت به نرخ خودکار
        </button>
      </div>

      <div className="divider" />
      <div className="row-group">
        <span className="label">نسخه</span>
        <span className="hint">طلام {num(APP_VERSION)}</span>
      </div>

      <div className="btn-row">
        <button
          type="button"
          className="btn btn-outline btn-block"
          onClick={() => void shareText('اپ محاسبه طلا طلام', SHARE_APP_TEXT)}
        >
          <ShareIcon size={18} />
          اشتراک‌گذاری اپ
        </button>
      </div>

      <div className="label-row" style={{ marginTop: 16 }}>
        <span className="label">
          <StarIcon size={15} /> امتیاز به اپ
        </span>
      </div>
      <div className="chips">
        <a className="chip active" href={BAZAAR_RATE_URL} target="_blank" rel="noopener noreferrer">
          در کافه‌بازار
        </a>
        <a className="chip active" href={MYKET_APP_URL} target="_blank" rel="noopener noreferrer">
          در مایکت
        </a>
      </div>
      <p className="footer-note" style={{ marginTop: 14 }}>
        محاسبات تقریبی است و مسئولیت معامله نهایی با شماست.
      </p>
    </Sheet>
  );
}
