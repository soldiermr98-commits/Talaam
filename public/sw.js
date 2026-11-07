/* طلام — سرویس‌ورکر آفلاین‌محور، بدون وابستگی خارجی */
const CACHE = 'talam-v1';

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
  // درخواست‌های خارجی (مثل endpoint نرخ‌ها) دست‌نخورده رد می‌شوند
  if (url.origin !== self.location.origin) return;
  event.respondWith(staleWhileRevalidate(req));
});

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
