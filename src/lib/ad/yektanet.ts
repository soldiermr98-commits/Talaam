import type { AdProvider } from './types';

/**
 * استاب یکتانت — TODO: بعد از فعال‌سازی ADS_ENABLED:
 * اسکریپت رسمی یکتانت (web-player) را تزریق کن و بنر نمایشی را پیاده کن.
 * فقط برای نسخه PWA مناسب است؛ در APK اندروید از تپسل استفاده شود.
 */
export class YektanetAdProvider implements AdProvider {
  readonly name = 'yektanet';
  constructor(private bannerId: string = '') {}

  async init(): Promise<void> {
    void this.bannerId;
  }

  showBanner(container: HTMLElement | null): void {
    void container;
  }

  destroy(): void {
    /* هیچ */
  }
}
