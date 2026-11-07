<?php
/**
 * رابط مشترک همه منابع نرخ.
 * هر آداپتور باید آرایه نرمال‌شده زیر را برگرداند یا RuntimeException پرتاب کند:
 * [
 *   'gold18'  => int,   // تومان بر گرم ۱۸ عیار
 *   'mazaneh' => int,   // تومان بر مثقال ۱۷ عیار تهران
 *   'coins'   => ['emami'=>int,'bahar'=>int,'nim'=>int,'rob'=>int,'gerami'=>int], // بازار، تومان (۰ = موجود نیست)
 * ]
 * نرمال‌سازی واحد (ریال→تومان) وظیفه خود آداپتور است (BaseProvider::toToman).
 */
interface ProviderInterface
{
    /** شناسه کوتاه منبع که در خروجی JSON با کلید source منتشر می‌شود */
    public function id(): string;

    /** دریافت و نرمال‌سازی؛ در هر خطایی RuntimeException پرتاب می‌شود */
    public function fetch(): array;
}
