// نمونه Cloudflare Worker برای سرو کردن فایل نرخ‌ها
// استقرار: wrangler deploy — و در src/config مقدار PRICES_URL را به این آدرس بده.

// TODO: منطق دریافت نرخ واقعی را اینجا پیاده کن. خروجی باید دقیقاً همین schema باشد:
// {
//   "updated_at": "2026-01-01T12:00:00+03:30",
//   "gold18": 0,          // تومان بر گرم ۱۸ عیار
//   "mazaneh": 0,         // تومان بر مثقال ۱۷ عیار تهران
//   "coins": { "emami": 0, "bahar": 0, "nim": 0, "rob": 0, "gerami": 0 } // قیمت بازار، تومان
// }

const CACHE_TTL = 300; // ۵ دقیقه کش

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
        'cache-control': `public, max-age=${CACHE_TTL}`,
      },
    });
  },
};

async function getPrices(env) {
  // کش در KV برای کاهش فشار به منبع (namespace اختیاری: PRICES)
  try {
    const cached = await env.PRICES?.get('latest');
    if (cached) return JSON.parse(cached);
  } catch {
    /* بدون KV هم کار می‌کند */
  }

  // TODO: جایگزینی با منبع واقعی، مثلاً:
  // const res = await fetch('https://YOUR-SOURCE/api/gold');
  // const src = await res.json();
  // const data = {
  //   updated_at: new Date().toISOString(),
  //   gold18: src.gold18, mazaneh: src.mazaneh,
  //   coins: { emami: src.emami, bahar: src.bahar, nim: src.nim, rob: src.rob, gerami: src.gerami },
  // };

  const data = {
    updated_at: new Date().toISOString(),
    gold18: 0,
    mazaneh: 0,
    coins: { emami: 0, bahar: 0, nim: 0, rob: 0, gerami: 0 },
  };

  try {
    await env.PRICES?.put('latest', JSON.stringify(data), { expirationTtl: CACHE_TTL });
  } catch {
    /* نادیده */
  }
  return data;
}
