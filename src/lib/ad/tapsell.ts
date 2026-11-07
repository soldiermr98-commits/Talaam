import type { AdProvider } from './types';

/**
 * استاب Tapsell — TODO: بعد از فعال‌سازی ADS_ENABLED:
 * 1) اسکریپت رسمی Tapsell را اضافه کن (فقط در نسخه اندروید)
 * 2) متد showBanner را با API رسمی نمایش بنر تپسل پیاده کن
 */
export class TapsellAdProvider implements AdProvider {
  readonly name = 'tapsell';
  constructor(private bannerId: string = '') {}

  async init(): Promise<void> {
    // TODO: مقداردهی اولیه SDK تپسل
    void this.bannerId;
  }

  showBanner(container: HTMLElement | null): void {
    // TODO: درخواست و نمایش بنر در container
    void container;
  }

  destroy(): void {
    // TODO: آزادسازی منابع بنر
  }
}
