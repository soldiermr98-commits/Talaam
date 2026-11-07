// رابط ارائه‌دهنده تبلیغ — پیاده‌سازی‌های no-op و استاب Tapsell/Yektanet
// پیش‌فرض: ADS_ENABLED=false در src/config → هیچ تبلیغی لود نمی‌شود.

export interface AdProvider {
  readonly name: string;
  init(): Promise<void>;
  showBanner(container: HTMLElement | null): void;
  destroy(): void;
}
