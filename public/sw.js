/* طلام — سرویس‌ورکر آفلاین‌محور، بدون کش کردن نرخ‌های زنده */
const CACHE = 'talam-v5';

self.addEventListener('install', () => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const keys = await caches.keys();
      await Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)));
      await self.clients.claim();
    })()
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  // درخواست‌های خارجی یا فایل‌های نرخ/API هرگز کش نمی‌شوند
  if (
    url.origin !== self.location.origin ||
    url.pathname.endsWith('prices.json') ||
    url.pathname.includes('/api/') ||
    url.pathname.includes('/server/')
  ) {
    return;
  }
  // برای صفحات HTML ابتدا شبکه چک شود تا آپدیت‌های جدید فوراً دیده شوند
  if (req.mode === 'navigate' || url.pathname.endsWith('.html') || url.pathname.endsWith('/')) {
    event.respondWith(networkFirst(req));
    return;
  }
  event.respondWith(staleWhileRevalidate(req));
});

async function networkFirst(req) {
  const cache = await caches.open(CACHE);
  try {
    const fresh = await fetch(req);
    if (fresh && fresh.ok) cache.put(req, fresh.clone());
    return fresh;
  } catch {
    const cached = await cache.match(req);
    if (cached) return cached;
    return new Response('offline', { status: 503, statusText: 'offline' });
  }
}

async function staleWhileRevalidate(req) {
  const cache = await caches.open(CACHE);
  const cached = await cache.match(req);
  const refresh = fetch(req)
    .then((res) => {
      if (res && res.ok) cache.put(req, res.clone());
      return res;
    })
    .catch(() => undefined);
  if (cached) return cached;
  const fresh = await refresh;
  if (fresh) return fresh;
  return new Response('offline', { status: 503, statusText: 'offline' });
}
