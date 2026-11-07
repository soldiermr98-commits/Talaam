import type { AdProvider } from './types';

/** پیاده‌سازی خنثی — وقتی تبلیغات خاموش است استفاده می‌شود */
export class NoopAdProvider implements AdProvider {
  readonly name = 'noop';
  async init(): Promise<void> {
    /* هیچ */
  }
  showBanner(_container: HTMLElement | null): void {
    /* هیچ */
  }
  destroy(): void {
    /* هیچ */
  }
}
