/**
 * طلام — نمونه ثانویه: Cloudflare Worker برای سرو prices.json
 *
 * این فقط مثال جایگزین است؛ هدف اصلی PHP + cron روی هاست ایران است
 * (کاربران ایران به Worker بدون تحریم‌شکن دسترسی پایدار ندارند — unverified).
 *
 * TODO(unverified): منطق دریافت از منبع واقعی را همین‌جا (یا بهتر: در PHP) پیاده کن.
 * schema خروجی باید با server/README_SERVER.md یکی باشد.
 */

const CACHE_TTL = 300; // ۵ دقیقه کش KV

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    if (!url.pathname.endsWith('/prices.json')) {
      return new Response('not found', { status: 404 });
    }

    const data = await getPrices(env);
    return new Response(JSON.stringify(data), {
      headers: {
        'content-type': 'application/json; charset=utf-8',
        'access-control-allow-origin': '*',
        'cache-control': 'public, max-age=60',
      },
    });
  },
};

/**
 * اول KV cache؛ اگر نبود منبع واقعی (TODO)؛ اگر آن هم نشد → last good با stale=true.
 * هرگز داده تازه جعلی نساز.
 */
async function getPrices(env) {
  try {
    const cached = await env.PRICES?.get('last_good', 'json');
    if (cached) {
      // TODO(unverified): fetch از منبع واقعی + validate + jump-guard (همان قواعد PHP)
      // تا پیاده‌سازی شود، last good با stale=true سرو می‌شود:
      return { ...cached, stale: true };
    }
  } catch {
    /* بدون KV هم کار می‌کند */
  }
  return {
    updated_at: new Date().toISOString(),
    source: 'none',
    stale: true,
    gold18: 0,
    mazaneh: 0,
    coins: { emami: 0, bahar: 0, nim: 0, rob: 0, gerami: 0 },
  };
}
