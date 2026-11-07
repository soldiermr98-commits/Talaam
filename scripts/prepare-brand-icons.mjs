// آماده‌سازی آیکون برند طلام از تصویر مرجع (بدون وابستگی خارجی)
// اجرا: node scripts/prepare-brand-icons.mjs
// - خواندن PNG (decoder دستی) از assets/brand/icon-master.png
// - خروجی: آیکون‌های PWA (public/icons)، لانچر اندروید (mipmap)، foreground اداپتیو، اسپلش‌ها

import { deflateSync, inflateSync } from 'node:zlib';
import { mkdirSync, writeFileSync, existsSync, readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const MASTER = join(ROOT, 'assets', 'brand', 'icon-master.png');
const OUT = join(ROOT, 'public', 'icons');
const SPLASH_BG = [30, 16, 48]; // #1E1030

// ───────── CRC + chunk helpers (encoder) ─────────
const CRC_TABLE = (() => {
  const t = new Uint32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    t[n] = c >>> 0;
  }
  return t;
})();

function crc32(buf) {
  let c = 0xffffffff;
  for (let i = 0; i < buf.length; i++) c = CRC_TABLE[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
}

function chunk(type, data) {
  const len = Buffer.alloc(4);
  len.writeUInt32BE(data.length);
  const body = Buffer.concat([Buffer.from(type, 'ascii'), data]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(body));
  return Buffer.concat([len, body, crc]);
}

function encodePng(width, height, rgba) {
  const sig = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(width, 0);
  ihdr.writeUInt32BE(height, 4);
  ihdr[8] = 8; // bit depth
  ihdr[9] = 6; // RGBA
  const raw = Buffer.alloc((width * 4 + 1) * height);
  for (let y = 0; y < height; y++) {
    raw[y * (width * 4 + 1)] = 0;
    rgba.copy(raw, y * (width * 4 + 1) + 1, y * width * 4, (y + 1) * width * 4);
  }
  return Buffer.concat([sig, chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw, { level: 9 })), chunk('IEND', Buffer.alloc(0))]);
}

// ───────── PNG decoder مینیمال ─────────
function decodePng(buf) {
  const sig = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
  if (!buf.subarray(0, 8).equals(sig)) throw new Error('not a PNG');
  let pos = 8;
  let width = 0, height = 0, bitDepth = 0, colorType = 0, interlace = 0;
  let palette = null, trns = null;
  const idat = [];
  while (pos < buf.length) {
    const len = buf.readUInt32BE(pos);
    const type = buf.toString('ascii', pos + 4, pos + 8);
    const data = buf.subarray(pos + 8, pos + 8 + len);
    if (type === 'IHDR') {
      width = data.readUInt32BE(0);
      height = data.readUInt32BE(4);
      bitDepth = data[8];
      colorType = data[9];
      interlace = data[12];
    } else if (type === 'PLTE') {
      palette = Buffer.from(data);
    } else if (type === 'tRNS') {
      trns = Buffer.from(data);
    } else if (type === 'IDAT') {
      idat.push(data);
    } else if (type === 'IEND') {
      break;
    }
    pos += 12 + len;
  }
  if (interlace !== 0) throw new Error('interlaced PNG پشتیبانی نمی‌شود');
  if (![0, 2, 3, 4, 6].includes(colorType)) throw new Error(`colorType ${colorType} پشتیبانی نمی‌شود`);

  const channels = { 0: 1, 2: 3, 3: 1, 4: 2, 6: 4 }[colorType];
  const bppSrc = channels * (bitDepth / 8);
  const stride = width * channels * (bitDepth / 8);
  const raw = inflateSync(Buffer.concat(idat));
  const lines = Buffer.alloc(stride * height);

  // بازکردن فیلترهای اسکن‌لاین
  let rp = 0;
  const prev = Buffer.alloc(stride);
  const cur = Buffer.alloc(stride);
  for (let y = 0; y < height; y++) {
    const filter = raw[rp++];
    raw.copy(cur, 0, rp, rp + stride);
    rp += stride;
    for (let x = 0; x < stride; x++) {
      const a = x >= bppSrc ? cur[x - bppSrc] : 0;
      const b = prev[x];
      const c = x >= bppSrc ? prev[x - bppSrc] : 0;
      let v = cur[x];
      if (filter === 1) v = (v + a) & 0xff;
      else if (filter === 2) v = (v + b) & 0xff;
      else if (filter === 3) v = (v + ((a + c) >> 1)) & 0xff;
      else if (filter === 4) {
        const p = a + b - c;
        const pa = Math.abs(p - a), pb = Math.abs(p - b), pc = Math.abs(p - c);
        v = (v + (pa <= pb && pa <= pc ? a : pb <= pc ? b : c)) & 0xff;
      }
      cur[x] = v;
    }
    cur.copy(lines, y * stride);
    cur.copy(prev);
  }

  // تبدیل به RGBA8
  const out = Buffer.alloc(width * height * 4);
  const px = (x, y) => y * stride + x * channels * (bitDepth / 8);
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const i = px(x, y);
      const o = (y * width + x) * 4;
      if (colorType === 6) {
        out[o] = lines[i]; out[o + 1] = lines[i + 1]; out[o + 2] = lines[i + 2]; out[o + 3] = lines[i + 3];
      } else if (colorType === 2) {
        out[o] = lines[i]; out[o + 1] = lines[i + 1]; out[o + 2] = lines[i + 2]; out[o + 3] = 255;
      } else if (colorType === 4) {
        out[o] = lines[i]; out[o + 1] = lines[i]; out[o + 2] = lines[i]; out[o + 3] = lines[i + 1];
      } else if (colorType === 0) {
        out[o] = lines[i]; out[o + 1] = lines[i]; out[o + 2] = lines[i]; out[o + 3] = 255;
      } else if (colorType === 3) {
        const idx = lines[i];
        out[o] = palette[idx * 3]; out[o + 1] = palette[idx * 3 + 1]; out[o + 2] = palette[idx * 3 + 2];
        out[o + 3] = trns && idx < trns.length ? trns[idx] : 255;
      }
      if (bitDepth === 16) {
        // بایت پرارزش قبلاً بر اساس bppSrc انتخاب شده — درست است
      }
    }
  }
  return { width, height, data: out };
}

// ───────── resample (box filter) ─────────
function resample(img, tw, th) {
  const { width: sw, height: sh, data: src } = img;
  const out = Buffer.alloc(tw * th * 4);
  for (let y = 0; y < th; y++) {
    const y0 = Math.floor((y * sh) / th);
    const y1 = Math.max(y0 + 1, Math.floor(((y + 1) * sh) / th));
    for (let x = 0; x < tw; x++) {
      const x0 = Math.floor((x * sw) / tw);
      const x1 = Math.max(x0 + 1, Math.floor(((x + 1) * sw) / tw));
      let r = 0, g = 0, b = 0, a = 0, n = 0;
      for (let sy = y0; sy < y1; sy++) {
        for (let sx = x0; sx < x1; sx++) {
          const i = (sy * sw + sx) * 4;
          r += src[i]; g += src[i + 1]; b += src[i + 2]; a += src[i + 3]; n++;
        }
      }
      const o = (y * tw + x) * 4;
      out[o] = Math.round(r / n); out[o + 1] = Math.round(g / n);
      out[o + 2] = Math.round(b / n); out[o + 3] = Math.round(a / n);
    }
  }
  return { width: tw, height: th, data: out };
}

/** ماسک گوشه گرد — اگر گوشه‌های مرجع کدر باشند، بیرون مربع گرد شفاف می‌شود */
function roundMask(img, radiusRatio = 0.215) {
  const { width: w, height: h, data } = img;
  // اگر گوشه‌ها از قبل شفاف‌اند، نیازی به ماسک نیست
  const corner = (0 * w + 0) * 4;
  if (data[corner + 3] < 250) return img;
  const out = Buffer.from(data);
  const rad = Math.min(w, h) * radiusRatio;
  for (let y = 0; y < h; y++) {
    for (let x = 0; x < w; x++) {
      const cx = Math.min(Math.max(x, rad), w - rad);
      const cy = Math.min(Math.max(y, rad), h - rad);
      if ((x - cx) ** 2 + (y - cy) ** 2 > rad * rad) out[(y * w + x) * 4 + 3] = 0;
    }
  }
  return { width: w, height: h, data: out };
}

/** نمونه‌رنگ پس‌زمینه — میانه چند نقطه امن تا حاشیه/گرادیان اشتباه نشود */
function sampleBg(img) {
  const { width: w, height: h, data } = img;
  const pts = [
    [0.06, 0.5], [0.5, 0.06], [0.94, 0.5], [0.5, 0.94],
    [0.10, 0.10], [0.90, 0.10], [0.10, 0.90], [0.90, 0.90],
  ];
  const samples = pts.map(([fx, fy]) => {
    const i = (Math.floor(h * fy) * w + Math.floor(w * fx)) * 4;
    return [data[i], data[i + 1], data[i + 2]];
  });
  // تیره‌ترین نمونه = پس‌زمینه واقعی (حاشیه‌های طلایی روشن‌ترند)
  samples.sort((p, q) => p[0] + p[1] + p[2] - (q[0] + q[1] + q[2]));
  return samples[0];
}

/** قرار دادن تصویر مربع روی بوم — scale نسبت به ضلع کوچک‌تر بوم است تا تصویر له نشود */
function placeOn(canvasW, canvasH, img, scale, bg) {
  const out = Buffer.alloc(canvasW * canvasH * 4);
  for (let i = 0; i < out.length; i += 4) {
    out[i] = bg[0]; out[i + 1] = bg[1]; out[i + 2] = bg[2]; out[i + 3] = bg.length === 4 ? bg[3] : 255;
  }
  const side = Math.round(Math.min(canvasW, canvasH) * scale);
  const small = resample(img, side, side);
  const ox = Math.round((canvasW - side) / 2);
  const oy = Math.round((canvasH - side) / 2);
  for (let y = 0; y < side; y++) {
    for (let x = 0; x < side; x++) {
      const si = (y * side + x) * 4;
      const a = small.data[si + 3] / 255;
      if (a === 0) continue;
      const di = ((oy + y) * canvasW + ox + x) * 4;
      for (let c = 0; c < 3; c++) {
        out[di + c] = Math.round(small.data[si + c] * a + out[di + c] * (1 - a));
      }
      out[di + 3] = Math.max(out[di + 3], small.data[si + 3]);
    }
  }
  return { width: canvasW, height: canvasH, data: out };
}

function savePng(img, path, label) {
  writeFileSync(path, encodePng(img.width, img.height, img.data));
  console.log(`ok ${label} (${img.width}x${img.height})`);
}

// ───────── اجرا ─────────
if (!existsSync(MASTER)) {
  console.error('مرجع آیکون پیدا نشد: assets/brand/icon-master.png');
  process.exit(1);
}
const master = roundMask(decodePng(readFileSync(MASTER)));
mkdirSync(OUT, { recursive: true });

const bg = sampleBg(master);
const bgHex = '#' + bg.map((v) => v.toString(16).padStart(2, '0')).join('');
console.log('background sampled:', bgHex);

// PWA
savePng(resample(master, 192, 192), join(OUT, 'icon-192.png'), 'icon-192.png');
savePng(resample(master, 512, 512), join(OUT, 'icon-512.png'), 'icon-512.png');
savePng(placeOn(512, 512, master, 0.84, [...bg, 255]), join(OUT, 'icon-maskable-512.png'), 'icon-maskable-512.png');

// اندروید
const ANDROID_RES = join(ROOT, 'android', 'app', 'src', 'main', 'res');
if (existsSync(ANDROID_RES)) {
  const DENSITIES = { mdpi: 48, hdpi: 72, xhdpi: 96, xxhdpi: 144, xxxhdpi: 192 };
  for (const [dpi, size] of Object.entries(DENSITIES)) {
    const dir = join(ANDROID_RES, `mipmap-${dpi}`);
    mkdirSync(dir, { recursive: true });
    writeFileSync(join(dir, 'ic_launcher.png'), encodePng(size, size, resample(master, size, size).data));
    writeFileSync(join(dir, 'ic_launcher_round.png'), encodePng(size, size, resample(master, size, size).data));
    const fg = Math.round(size * 2.25);
    savePng(placeOn(fg, fg, master, 0.58, [0, 0, 0, 0]), join(dir, 'ic_launcher_foreground.png'), `fg-${dpi}`);
  }
  // رنگ پس‌زمینه adaptive از خود آیکون
  writeFileSync(
    join(ANDROID_RES, 'values', 'ic_launcher_background.xml'),
    `<?xml version="1.0" encoding="utf-8"?>\n<resources>\n    <color name="ic_launcher_background">${bgHex}</color>\n</resources>\n`
  );
  console.log('ok adaptive background:', bgHex);

  // اسپلش‌ها — همان ابعاد فایل‌های موجود، تک‌رنگ تیره (کوچک، چند KB)
  // اندروید ۱۲+ خودش آیکون اپ را روی اسپلش سیستم نشان می‌دهد؛ پیش از ۱۲ یک صفحه تیره ساده دیده می‌شود.
  const resDirs = readdirSync(ANDROID_RES).filter((d) => d.startsWith('drawable'));
  const solidRow = [];
  for (let i = 0; i < 16; i++) solidRow.push(...SPLASH_BG, 255); // تصویر 4x4 تک‌رنگ
  const solid = encodePng(4, 4, Buffer.from(solidRow));
  for (const d of resDirs) {
    const splashPath = join(ANDROID_RES, d, 'splash.png');
    if (!existsSync(splashPath)) continue;
    writeFileSync(splashPath, solid);
    console.log(`ok splash-${d} (solid ${SPLASH_BG.map((v) => v.toString(16).padStart(2, '0')).join('')})`);
  }
  console.log('ok android icons + splash');
} else {
  console.log('android/ exists nist — faghat PWA');
}
