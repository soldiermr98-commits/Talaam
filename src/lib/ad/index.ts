import { AD_BANNER_ID, AD_PROVIDER, ADS_ENABLED } from '../../config';
import { NoopAdProvider } from './noop';
import { TapsellAdProvider } from './tapsell';
import { YektanetAdProvider } from './yektanet';
import type { AdProvider } from './types';

let instance: AdProvider | null = null;

/** ارائه‌دهنده تبلیغ به‌صورت singleton؛ وقتی ADS_ENABLED=false خنثی است */
export function getAdProvider(): AdProvider {
  if (!instance) {
    if (!ADS_ENABLED) {
      instance = new NoopAdProvider();
    } else {
      instance = AD_PROVIDER === 'yektanet' ? new YektanetAdProvider(AD_BANNER_ID) : new TapsellAdProvider(AD_BANNER_ID);
    }
  }
  return instance;
}

export type { AdProvider } from './types';
