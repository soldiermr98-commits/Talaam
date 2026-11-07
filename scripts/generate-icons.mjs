// ساخت آیکون‌های PNG اپ — بدون هیچ وابستگی خارجی (PNG encoder دستی + zlib)
// اجرا: node scripts/generate-icons.mjs
// اگر پوشه android/ موجود باشد، آیکون‌های launcher اندروید را هم می‌سازد.

import { deflateSync } from 'node:zlib';
import { mkdirSync, writeFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = join(ROOT, 'public', 'icons');

// ───────── PNG encoder (RGBA 8-bit) ─────────
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
  ihdr[9] = 6; // color type RGBA
  const raw = Buffer.alloc((width * 4 + 1) * height);
  for (let y = 0; y < height; y++) {
    raw[y * (width * 4 + 1)] = 0; // filter none
    rgba.copy(raw, y * (width * 4 + 1) + 1, y * width * 4, (y + 1) * width * 4);
  }
  return Buffer.concat([sig, chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw, { level: 9 })), chunk('IEND', Buffer.alloc(0))]);
}

// ───────── رسترایز شکل ─────────
function hex(c) {
  const n = parseInt(c.slice(1), 16);
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
}

const BG = hex('#1E1030');
const GOLD = hex('#D4A843');
const GOLD_LIGHT = hex('#E8C97D');
const GOLD_DARK = hex('#B08A2E');

// چندضلعی‌های جواهر (در فضای 0..1)
const FACETS = [
  // بدنه اصلی
  { pts: [[0.3, 0.28], [0.7, 0.28], [0.86, 0.44], [0.5, 0.82], [0.14, 0.44]], color: GOLD },
  // وجه بالایی وسط (روشن)
  { pts: [[0.4, 0.28], [0.6, 0.28], [0.66, 0.44], [0.34, 0.44]], color: GOLD_LIGHT },
  // وجه پایین چپ (تیره)
  { pts: [[0.14, 0.44], [0.34, 0.44], [0.5, 0.82]], color: GOLD_DARK },
];

function pointInPoly(px, py, pts) {
  let inside = false;
  for (let i = 0, j = pts.length - 1; i < pts.length; j = i++) {
    const [xi, yi] = pts[i];
    const [xj, yj] = pts[j];
    if (yi > py !== yj > py && px < ((xj - xi) * (py - yi)) / (yj - yi) + xi) inside = !inside;
  }
  return inside;
}

/** رندر با ۴×۴ زیرنمونه برای لبه‌های نرم */
function render(size, { rounded = false, gemScale = 1 } = {}) {
  const buf = Buffer.alloc(size * size * 4);
  const S = 4;
  for (let y = 0; y < size; y++) {
    for (let x = 0; x < size; x++) {
      let r = 0, g = 0, b = 0, a = 0;
      for (let sy = 0; sy < S; sy++) {
        for (let sx = 0; sx < S; sx++) {
          const px = (x + (sx + 0.5) / S) / size;
          const py = (y + (sy + 0.5) / S) / size;
          let [cr, cg, cb, ca] = [BG[0], BG[1], BG[2], 255];
          if (rounded) {
            // گوشه‌های گرد در شعاع 18٪
            const rad = 0.18;
            const cx = Math.min(Math.max(px, rad), 1 - rad);
            const cy = Math.min(Math.max(py, rad), 1 - rad);
            if ((px - cx) ** 2 + (py - cy) ** 2 > rad * rad) ca = 0;
          }
          if (ca > 0) {
            // جواهر در مرکز با مقیاس gemScale
            const gx = 0.5 + (px - 0.5) / gemScale;
            const gy = 0.5 + (py - 0.5) / gemScale;
            for (const f of FACETS) {
              if (pointInPoly(gx, gy, f.pts)) {
                [cr, cg, cb] = f.color;
                break;
              }
            }
          }
          r += cr; g += cg; b += cb; a += ca;
        }
      }
      const n = S * S;
      const i = (y * size + x) * 4;
      buf[i] = Math.round(r / n);
      buf[i + 1] = Math.round(g / n);
      buf[i + 2] = Math.round(b / n);
      buf[i + 3] = Math.round(a / n);
    }
  }
  return buf;
}

function writeIcon(size, name, opts) {
  const png = encodePng(size, size, render(size, opts));
  writeFileSync(join(OUT, name), png);
  console.log(`✓ ${name} (${size}×${size}, ${(png.length / 1024).toFixed(1)} KB)`);
}

mkdirSync(OUT, { recursive: true });
writeIcon(192, 'icon-192.png');
writeIcon(512, 'icon-512.png');
writeIcon(512, 'icon-maskable-512.png', { gemScale: 0.78 }); // ناحیه امن maskable
writeIcon(512, 'icon-rounded-512.png', { rounded: true });

// ───────── آیکون‌های اندروید (اگر پلتفرم اضافه شده باشد) ─────────
const ANDROID_RES = join(ROOT, 'android', 'app', 'src', 'main', 'res');
if (existsSync(ANDROID_RES)) {
  const DENSITIES = { mdpi: 48, hdpi: 72, xhdpi: 96, xxhdpi: 144, xxxhdpi: 192 };
  for (const [dpi, size] of Object.entries(DENSITIES)) {
    const dir = join(ANDROID_RES, `mipmap-${dpi}`);
    mkdirSync(dir, { recursive: true });
    writeFileSync(join(dir, 'ic_launcher.png'), encodePng(size, size, render(size, { rounded: true })));
    writeFileSync(join(dir, 'ic_launcher_round.png'), encodePng(size, size, render(size, { rounded: true })));
    // foreground adaptive icon (با ناحیه امن بزرگ‌تر)
    const fgSize = Math.round(size * 2.25);
    writeFileSync(join(dir, 'ic_launcher_foreground.png'), encodePng(fgSize, fgSize, render(fgSize, { gemScale: 0.7 })));
    console.log(`✓ android mipmap-${dpi}`);
  }
  console.log('✓ آیکون‌های اندروید ساخته شدند (پس‌زمینه adaptive را از resources رنگ کنید: #1E1030)');
} else {
  console.log('ℹ پوشه android/ موجود نیست؛ آیکون‌های PWA ساخته شدند.');
}
