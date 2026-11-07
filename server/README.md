# پوشه server — نمونه‌های سرو نرخ‌ها

اپ «طلام» یک فایل JSON ثابت با این schema می‌خواند:

```json
{
  "updated_at": "2026-01-01T12:00:00+03:30",
  "gold18": 0,
  "mazaneh": 0,
  "coins": { "emami": 0, "bahar": 0, "nim": 0, "rob": 0, "gerami": 0 }
}
```

- `gold18`: تومان بر گرم ۱۸ عیار
- `mazaneh`: تومان بر مثقال ۱۷ عیار تهران
- `coins`: قیمت بازار سکه‌ها به تومان

## Cloudflare Worker

1. `wrangler init` (یا یک پروژه Worker در داشبورد بساز)
2. محتوای `cloudflare-worker.js` را بگذار؛ منطق دریافت نرخ واقعی جایی که `TODO` نوشته شده پیاده می‌شود.
3. بعد از deploy، آدرس را در `src/config/index.ts` قرار بده:

```ts
export const MOCK_MODE = false;
export const PRICES_URL = 'https://your-worker.workers.dev/prices.json';
```

## PHP

فایل `prices.php` را روی هر هاست PHP بگذار و همانطور آدرس بده. منطق دریافت از منبع واقعی داخل `TODO` است.

## نکته

- آپدیت این فایل هر ۵ دقیقه کافی است (کش `max-age=300`).
- CORS باز است تا اپ PWA مستقیم بخواند؛ برای اپ اندروید (Capacitor) CORS مشکلی ایجاد نمی‌کند.
