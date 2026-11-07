// لایه ذخیره‌سازی: Capacitor Preferences با fallback به localStorage
// خواندن همگام از localStorage برای کول‌استارت فوری (<1s) نگه داشته می‌شود.

import { Preferences } from '@capacitor/preferences';

const PREFIX = 'talam.';

/** خواندن همگام (فقط localStorage) — برای مقداردهی اولیه فوری UI */
export function storageGetSync(key: string): string | null {
  try {
    return localStorage.getItem(PREFIX + key);
  } catch {
    return null;
  }
}

export function storageGetJSONSync<T>(key: string): T | null {
  const raw = storageGetSync(key);
  if (!raw) return null;
  try {
    return JSON.parse(raw) as T;
  } catch {
    return null;
  }
}

export async function storageGet(key: string): Promise<string | null> {
  try {
    const { value } = await Preferences.get({ key: PREFIX + key });
    if (value != null && value !== '') return value;
  } catch {
    /* به fallback می‌رویم */
  }
  try {
    return localStorage.getItem(PREFIX + key);
  } catch {
    return null;
  }
}

export async function storageSet(key: string, value: string): Promise<void> {
  try {
    localStorage.setItem(PREFIX + key, value);
  } catch {
    /* بی‌صدا */
  }
  try {
    await Preferences.set({ key: PREFIX + key, value });
  } catch {
    /* بی‌صدا */
  }
}

export async function storageGetJSON<T>(key: string): Promise<T | null> {
  const raw = await storageGet(key);
  if (!raw) return null;
  try {
    return JSON.parse(raw) as T;
  } catch {
    return null;
  }
}

export async function storageSetJSON(key: string, value: unknown): Promise<void> {
  await storageSet(key, JSON.stringify(value));
}
