<?php
/**
 * ⚠️ UNVERIFIED — استاب برای «سرویس‌های API نرخ به سبک BrsApi».
 *
 * هیچ endpoint، پارامتر یا نام فیلدی در این فایل تأیید نشده است.
 * قبل از استفاده:
 *   1) مستندات رسمی سرویس را بگیر و endpoint + پارامتر کلید + نام فیلدها + واحد خروجی (تومان/ریال) را تأیید کن
 *   2) در config.php: ['class'=>'BrsapiStyleProvider','options'=>['endpoint'=>'…','api_key'=>'…','unit'=>'toman']]
 *   3) mapPayload را با فیلدهای واقعی تطبیق بده و throw پایین را حذف کن
 *   4) با jump-guard و تست‌ها صحت را بسنچ
 */
class BrsapiStyleProvider extends BaseProvider
{
    public function id(): string
    {
        return 'brsapi';
    }

    public function fetch(): array
    {
        $endpoint = $this->requireOption('endpoint'); // UNVERIFIED — از مستندات رسمی
        $apiKey = $this->requireOption('api_key');
        $unit = strtolower((string)($this->options['unit'] ?? 'toman')); // 'toman' | 'rial' — UNVERIFIED

        // UNVERIFIED: نام پارامتر کلید ('key') فرضی است
        $url = $endpoint . (strpos($endpoint, '?') === false ? '?' : '&') . 'key=' . rawurlencode($apiKey);
        $payload = $this->httpGetJson($url);

        return $this->mapPayload($payload, $unit);
    }

    /**
     * UNVERIFIED — mapping فرضی؛ پس از تأیید مستندات تکمیل شود.
     */
    private function mapPayload(array $payload, string $unit): array
    {
        // TODO(unverified): نمونه ساختاری — با مستندات واقعی جایگزین کن
        // $gold18  = $this->toToman($payload['data']['gold_18'] ?? 0, $unit);
        // $mazaneh = $this->toToman($payload['data']['mesghal'] ?? 0, $unit);
        // $coins = [
        //     'emami'  => $this->toToman($payload['data']['sekee_emami'] ?? 0, $unit),
        //     'bahar'  => $this->toToman($payload['data']['sekee_bahar'] ?? 0, $unit),
        //     'nim'    => $this->toToman($payload['data']['sekee_nim'] ?? 0, $unit),
        //     'rob'    => $this->toToman($payload['data']['sekee_rob'] ?? 0, $unit),
        //     'gerami' => $this->toToman($payload['data']['sekee_gerami'] ?? 0, $unit),
        // ];
        // return ['gold18' => $gold18, 'mazaneh' => $mazaneh, 'coins' => $coins];

        throw new RuntimeException('BrsapiStyleProvider: field mapping is TODO — endpoint/fields UNVERIFIED');
    }
}
