// اسلات بنر تبلیغ — پیش‌فرض خاموش (ADS_ENABLED=false در config)
// فقط یک بنر کوچک ثابت پایین؛ هیچ تبلیغ تمام‌صفحه یا اینترstitial ندارد.

import { useEffect, useRef } from 'react';
import { ADS_ENABLED } from '../config';
import { getAdProvider } from '../lib/ad';

export function AdBanner() {
  const ref = useRef<HTMLDivElement>(null);
  const provider = getAdProvider();

  useEffect(() => {
    if (!ADS_ENABLED) return;
    void provider.init();
    provider.showBanner(ref.current);
    return () => provider.destroy();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  if (!ADS_ENABLED) return null;
  return (
    <div className="ad-slot" ref={ref} aria-hidden="true">
      <div className="ad-box">جای بنر تبلیغ</div>
    </div>
  );
}
