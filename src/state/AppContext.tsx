// Context مرکزی اپ: تنظیمات، نرخ‌ها (کش/دستی)، ورودی‌های آخر هر تب و قالب‌بندی

import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { DEFAULT_SETTINGS, PRICES_URL, type Settings } from '../config';
import {
  fetchPrices,
  loadCachedPrices,
  OVERRIDE_STORAGE_KEY,
  PRICES_STORAGE_KEY,
  relativeTime,
  savePrices,
  validatePriceData,
  type CoinPrices,
  type PriceData,
} from '../lib/prices';
import { storageGetJSONSync, storageSetJSON } from '../lib/storage';
import {
  formatMoney,
  formatPercent,
  formatWeight,
  moneyToPersianWords,
  toPersianDigits,
  type DigitLocale,
} from '../lib/numbers';

const SETTINGS_KEY = 'settings';
const INPUTS_KEY = 'inputs';

export type TabKey = 'new' | 'second' | 'coin' | 'reverse';
export type TabInputs = Record<string, number | string>;
export type AllInputs = Record<TabKey, TabInputs>;

const EMPTY_INPUTS: AllInputs = { new: {}, second: {}, coin: {}, reverse: {} };

interface AppContextValue {
  settings: Settings;
  setSettings: (patch: Partial<Settings>) => void;
  resolvedTheme: 'dark' | 'light';

  prices: PriceData | null;
  gold18: number; // مؤثر: با override دستی
  mazaneh: number;
  coins: CoinPrices | null;
  updatedAt: string | null;
  updatedAtStale: boolean;
  updatedAtText: string;
  isManual: boolean; // نرخ دستی فعال است؟
  setManualGold18: (value: number | null) => void;
  refreshPrices: (manual?: boolean) => Promise<void>;
  refreshing: boolean;
  everFetched: boolean;

  online: boolean;

  fmtMoney: (v: number) => string;
  fmtWeight: (v: number, maxDecimals?: number) => string;
  fmtPercent: (v: number) => string;
  num: (v: number | string) => string; // فقط نقشه ارقام
  words: (v: number) => string; // حروف فارسی مبلغ

  inputs: AllInputs;
  setInput: (tab: TabKey, key: string, value: number | string | null) => void;
}

const AppContext = createContext<AppContextValue | null>(null);

function sanitizeSettings(s?: Partial<Settings> | null): Settings {
  if (!s) return { ...DEFAULT_SETTINGS };
  const tax = Number(s.taxPct);
  const profit = Number(s.profitPct);
  const margin = Number(s.secondHandMarginPct);
  return {
    theme: s.theme === 'dark' || s.theme === 'light' || s.theme === 'system' ? s.theme : DEFAULT_SETTINGS.theme,
    digits: s.digits === 'fa' || s.digits === 'en' ? s.digits : DEFAULT_SETTINGS.digits,
    taxPct: Number.isFinite(tax) && tax >= 0 && tax <= 100 ? tax : DEFAULT_SETTINGS.taxPct,
    profitPct: Number.isFinite(profit) && profit >= 0 && profit <= 100 ? profit : DEFAULT_SETTINGS.profitPct,
    secondHandMarginPct:
      Number.isFinite(margin) && margin >= 0 && margin <= 100 ? margin : DEFAULT_SETTINGS.secondHandMarginPct,
  };
}

function sanitizeInputs(x: unknown): AllInputs {
  if (!x || typeof x !== 'object') return { ...EMPTY_INPUTS };
  const o = x as Record<string, unknown>;
  const out = { ...EMPTY_INPUTS } as AllInputs;
  for (const tab of Object.keys(EMPTY_INPUTS) as TabKey[]) {
    const t = o[tab];
    if (t && typeof t === 'object') {
      const entries = Object.entries(t as Record<string, unknown>).filter(
        ([, v]) => typeof v === 'number' || typeof v === 'string'
      );
      out[tab] = Object.fromEntries(entries) as TabInputs;
    }
  }
  return out;
}

export function AppProvider({ children }: { children: React.ReactNode }) {
  const [settings, setSettingsState] = useState<Settings>(() => {
    const s = storageGetJSONSync<Settings>(SETTINGS_KEY);
    return sanitizeSettings(s);
  });
  const [prices, setPrices] = useState<PriceData | null>(() => {
    const cached = storageGetJSONSync<PriceData>(PRICES_STORAGE_KEY);
    return cached ? validatePriceData(cached) : null;
  });
  const [manual, setManual] = useState<number | null>(() => {
    const m = storageGetJSONSync<number | null>(OVERRIDE_STORAGE_KEY);
    return typeof m === 'number' && Number.isFinite(m) ? m : null;
  });
  const [inputs, setInputs] = useState<AllInputs>(() => sanitizeInputs(storageGetJSONSync(INPUTS_KEY)));
  const [online, setOnline] = useState<boolean>(() => (typeof navigator !== 'undefined' ? navigator.onLine : true));
  const [refreshing, setRefreshing] = useState(false);
  const [everFetched, setEverFetched] = useState(false);
  const [tick, setTick] = useState(0);
  const [systemDark, setSystemDark] = useState<boolean>(() =>
    typeof window !== 'undefined' && 'matchMedia' in window
      ? window.matchMedia('(prefers-color-scheme: dark)').matches
      : true
  );
  const inputsTimer = useRef<number | null>(null);

  // خواندن همگام اولیه + هماهنگ‌سازی با Preferences و دریافت پس‌زمینه
  useEffect(() => {
    let alive = true;
    (async () => {
      const cached = await loadCachedPrices();
      if (cached && alive) setPrices((p) => p ?? cached);
      void refreshPrices();
    })();
    return () => {
      alive = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // وضعیت آنلاین/آفلاین
  useEffect(() => {
    const on = () => setOnline(true);
    const off = () => setOnline(false);
    window.addEventListener('online', on);
    window.addEventListener('offline', off);
    return () => {
      window.removeEventListener('online', on);
      window.removeEventListener('offline', off);
    };
  }, []);

  // تیک هر ۳۰ ثانیه برای به‌روزرسانی «چند دقیقه پیش»
  useEffect(() => {
    const t = window.setInterval(() => setTick((x) => x + 1), 30_000);
    return () => window.clearInterval(t);
  }, []);

  // تم سیستم
  useEffect(() => {
    if (!('matchMedia' in window)) return;
    const mq = window.matchMedia('(prefers-color-scheme: dark)');
    const handler = (e: MediaQueryListEvent) => setSystemDark(e.matches);
    mq.addEventListener('change', handler);
    return () => mq.removeEventListener('change', handler);
  }, []);

  const resolvedTheme: 'dark' | 'light' =
    settings.theme === 'system' ? (systemDark ? 'dark' : 'light') : settings.theme;

  // اعمال تم روی ریشه و به‌روزرسانی theme-color
  useEffect(() => {
    document.documentElement.dataset.theme = resolvedTheme;
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', resolvedTheme === 'dark' ? '#1E1030' : '#FAF6EE');
  }, [resolvedTheme]);

  const refreshPrices = useCallback(async (manualFetch = false) => {
    if (manualFetch) setRefreshing(true);
    try {
      const data = await fetchPrices(PRICES_URL);
      setPrices(data);
      setEverFetched(true);
      void savePrices(data);
      // override دستی بعد از موفقیت دریافت، پاک می‌شود
      setManual(null);
      void storageSetJSON(OVERRIDE_STORAGE_KEY, null);
    } catch {
      // بی‌صدا — نرخ‌های کشی باقی می‌مانند
    } finally {
      if (manualFetch) setRefreshing(false);
    }
  }, []);

  const setSettings = useCallback((patch: Partial<Settings>) => {
    setSettingsState((prev) => {
      const next = sanitizeSettings({ ...prev, ...patch });
      void storageSetJSON(SETTINGS_KEY, next);
      return next;
    });
  }, []);

  const setManualGold18 = useCallback((value: number | null) => {
    const v = value != null && Number.isFinite(value) && value > 0 ? Math.round(value) : null;
    setManual(v);
    void storageSetJSON(OVERRIDE_STORAGE_KEY, v);
  }, []);

  const setInput = useCallback((tab: TabKey, key: string, value: number | string | null) => {
    setInputs((prev) => {
      const nextTab = { ...prev[tab] };
      if (value === null || value === '' || value === undefined) delete nextTab[key];
      else nextTab[key] = value;
      const next: AllInputs = { ...prev, [tab]: nextTab };
      // ذخیره debounce شده
      if (inputsTimer.current) window.clearTimeout(inputsTimer.current);
      inputsTimer.current = window.setTimeout(() => {
        void storageSetJSON(INPUTS_KEY, next);
      }, 300);
      return next;
    });
  }, []);

  const gold18 = useMemo(() => {
    if (manual != null) return manual;
    return prices?.gold18 ?? 0;
  }, [manual, prices]);

  const mazaneh = useMemo(() => prices?.mazaneh ?? (gold18 > 0 ? Math.round(gold18 * 4.3318) : 0), [prices, gold18]);

  const relative = useMemo(() => {
    void tick;
    if (!prices) return null;
    return relativeTime(prices.updated_at);
  }, [prices, tick]);

  const digits: DigitLocale = settings.digits;

  const value = useMemo<AppContextValue>(() => ({
    settings,
    setSettings,
    resolvedTheme,
    prices,
    gold18,
    mazaneh,
    coins: prices ? prices.coins : null,
    updatedAt: prices?.updated_at ?? null,
    // stale = یا فاصله زمانی از updated_at زیاد است یا سرور خودش پرچم stale فرستاده
    updatedAtStale: (relative?.stale ?? false) || prices?.stale === true,
    updatedAtText: relative?.text ?? '',
    isManual: manual != null,
    setManualGold18,
    refreshPrices,
    refreshing,
    everFetched,
    online,
    fmtMoney: (v: number) => formatMoney(v, digits),
    fmtWeight: (v: number, maxDecimals?: number) => formatWeight(v, digits, maxDecimals ?? 3),
    fmtPercent: (v: number) => formatPercent(v, digits),
    num: (v: number | string) => (digits === 'fa' ? toPersianDigits(String(v)) : String(v)),
    words: (v: number) => moneyToPersianWords(v),
    inputs,
    setInput,
  }), [
    settings, setSettings, resolvedTheme, prices, gold18, mazaneh, relative,
    manual, setManualGold18, refreshPrices, refreshing, everFetched, online, digits, inputs, setInput,
  ]);

  return <AppContext.Provider value={value}>{children}</AppContext.Provider>;
}

export function useApp(): AppContextValue {
  const ctx = useContext(AppContext);
  if (!ctx) throw new Error('useApp must be used within AppProvider');
  return ctx;
}
