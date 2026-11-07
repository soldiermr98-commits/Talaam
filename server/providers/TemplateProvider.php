<?php
/**
 * قالب ساخت آداپتور جدید — این فایل را کپی کن، نام کلاس و id() را عوض کن،
 * fetch() را پیاده کن و کلاس را به ترتیب providers در config.php اضافه کن.
 * هر مقدار «تأییدنشده» را با کامنت UNVERIFIED علامت بزن.
 */
class TemplateProvider extends BaseProvider
{
    public function id(): string
    {
        return 'template';
    }

    public function fetch(): array
    {
        // ۱) آدرس و کلید از config.php می‌آید (هرگز در کد هاردکد نشو)
        $endpoint = $this->requireOption('endpoint');

        // ۲) دریافت
        $payload = $this->httpGetJson($endpoint);

        // ۳) mapping به schema اپ — همه مقادیر باید «تومان» باشند
        //    اگر منبع ریال می‌دهد: $this->toToman($value, 'rial')
        //
        // UNVERIFIED: نام فیلدهای زیر نمونه است؛ با مستندات رسمی منبع تطبیق بده.
        // $gold18  = $this->toToman($payload['18k'] ?? 0, 'toman');
        // $mazaneh = $this->toToman($payload['mesghal'] ?? 0, 'toman');
        // $coins   = [
        //     'emami'  => $this->toToman($payload['emami'] ?? 0, 'toman'),
        //     'bahar'  => $this->toToman($payload['bahar'] ?? 0, 'toman'),
        //     'nim'    => $this->toToman($payload['nim'] ?? 0, 'toman'),
        //     'rob'    => $this->toToman($payload['rob'] ?? 0, 'toman'),
        //     'gerami' => $this->toToman($payload['gerami'] ?? 0, 'toman'),
        // ];
        // return ['gold18' => $gold18, 'mazaneh' => $mazaneh, 'coins' => $coins];

        throw new RuntimeException('TemplateProvider یک قالب است — پس از پیاده‌سازی این throw را حذف کن');
    }
}
