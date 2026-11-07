// لایه اشتراک‌گذاری: خروجی تصویر فاکتور + اشتراک‌گذاری متن
// وب: Web Share API با فایل → fallback دانلود
// اندروید (Capacitor): نوشتن فایل در Cache و اشتراک‌گذاری با Share plugin / ذخیره در گالری

import { Capacitor } from '@capacitor/core';
import { Directory, Filesystem } from '@capacitor/filesystem';
import { Share } from '@capacitor/share';
import { toPng } from 'html-to-image';

function isNative(): boolean {
  return Capacitor.isNativePlatform();
}

/** تبدیل نود DOM به PNG dataURL با عرض واقعی نود */
export async function nodeToDataUrl(node: HTMLElement): Promise<string> {
  return toPng(node, {
    pixelRatio: 1,
    cacheBust: true,
  });
}

function dataUrlToBlob(dataUrl: string): Blob {
  const [meta, b64] = dataUrl.split(',');
  const mime = /:(.*?);/.exec(meta)?.[1] ?? 'image/png';
  const bin = atob(b64);
  const bytes = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  return new Blob([bytes], { type: mime });
}

function downloadBlob(blob: Blob, fileName: string): void {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = fileName;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 5000);
}

export interface ShareOutcome {
  ok: boolean;
  reason?: 'shared' | 'downloaded' | 'copied' | 'cancelled' | string;
}

/** اشتراک‌گذاری تصویر فاکتور (نود ۱۰۸۰ پیکسلی) */
export async function shareInvoiceImage(node: HTMLElement, fileName = 'talam-invoice.png'): Promise<ShareOutcome> {
  try {
    const dataUrl = await nodeToDataUrl(node);
    if (isNative()) {
      const base64 = dataUrl.split(',')[1] ?? '';
      const file = await Filesystem.writeFile({
        path: fileName,
        data: base64,
        directory: Directory.Cache,
      });
      await Share.share({
        title: 'فاکتور محاسبه طلا',
        dialogTitle: 'اشتراک‌گذاری فاکتور',
        files: [file.uri],
      });
      return { ok: true, reason: 'shared' };
    }
    const blob = dataUrlToBlob(dataUrl);
    const file = new File([blob], fileName, { type: 'image/png' });
    const nav = navigator as Navigator & { canShare?: (d: ShareData) => boolean };
    if (nav.canShare?.({ files: [file] })) {
      await navigator.share({ files: [file], title: 'فاکتور محاسبه طلا' });
      return { ok: true, reason: 'shared' };
    }
    downloadBlob(blob, fileName);
    return { ok: true, reason: 'downloaded' };
  } catch (e) {
    return { ok: false, reason: String(e) };
  }
}

/** ذخیره تصویر فاکتور — اندروید: از طریق شیت اشتراک‌گذاری (کاربر می‌تواند در گالری ذخیره کند)
 *  وب: دانلود مستقیم. ذخیره مستقیم در MediaStore نیازمند پلااگین نیتیو است (README). */
export async function saveInvoiceImage(node: HTMLElement, fileName = 'talam-invoice.png'): Promise<ShareOutcome> {
  try {
    const dataUrl = await nodeToDataUrl(node);
    if (isNative()) {
      const base64 = dataUrl.split(',')[1] ?? '';
      const file = await Filesystem.writeFile({
        path: fileName,
        data: base64,
        directory: Directory.Cache,
      });
      await Share.share({
        title: 'ذخیره فاکتور',
        dialogTitle: 'ذخیره تصویر فاکتور',
        files: [file.uri],
      });
      return { ok: true, reason: 'shared' };
    }
    downloadBlob(dataUrlToBlob(dataUrl), fileName);
    return { ok: true, reason: 'downloaded' };
  } catch (e) {
    return { ok: false, reason: String(e) };
  }
}

/** اشتراک‌گذاری متن نتیجه (بدون تصویر) */
export async function shareText(title: string, text: string): Promise<ShareOutcome> {
  try {
    if (isNative()) {
      await Share.share({ title, text, dialogTitle: title });
      return { ok: true, reason: 'shared' };
    }
    if (navigator.share) {
      await navigator.share({ title, text });
      return { ok: true, reason: 'shared' };
    }
    await navigator.clipboard.writeText(text);
    return { ok: true, reason: 'copied' };
  } catch (e) {
    const name = (e as { name?: string })?.name;
    if (name === 'AbortError') return { ok: true, reason: 'cancelled' };
    return { ok: false, reason: String(e) };
  }
}
