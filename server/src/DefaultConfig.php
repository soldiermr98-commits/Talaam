<?php
/**
 * پیکربندی پیشفرض طلام — همین فایل در بسته deploy میآید و «هیچ ویرایشی» لازم نیست.
 * منبع پیشفرض: markaznerkh (به انتخاب مالک پروژه). تغییرات بعدی از داشبورد /admin انجام میشود.
 */
class DefaultConfig
{
    public static function get(string $serverDir): array
    {
        return [
            'timezone' => 'Asia/Tehran',
            'providers' => [
                ['class' => 'ConfigurableHttpProvider', 'options' => [
                    'id' => 'markaznerkh',
                    'enabled' => true,
                    'url' => 'https://market.markaznerkh.ir/api/v1/rates',
                    'method' => 'GET',
                    'query' => ['symbols' => 'GOLD_18K,COIN_86'],
                    'headers' => [],
                    'auth' => ['type' => 'header', 'name' => 'Authorization', 'key' => 'Bearer tala_free_abcf238ceb0cb0b3f72d6fc0a1717579a585decf856756a7b45c14f378152058'],
                    'map' => [
                        'gold18' => ['path' => 'GOLD_18K.price', 'unit' => 'toman'],
                        'coins.emami' => ['path' => 'COIN_86.price', 'unit' => 'toman'],
                    ],
                    'max_age_seconds' => 600,
                    'min_interval_seconds' => 300,
                    'daily_cap' => 300,
                ]],
                ['class' => 'ManualProvider', 'options' => ['path' => $serverDir . '/data/override.json']],
            ],
            'thresholds' => [
                'max_jump_pct' => 5.0,
                'jump_confirm_runs' => 3,
                'mazaneh_ratio' => 4.3318,
                'mazaneh_tolerance' => 0.5,
                'gold18_min' => 100000,
                'gold18_max' => 2000000000,
                'coin_max_gold18_ratio' => 100,
            ],
            'http' => ['timeout_seconds' => 5, 'retries' => 2, 'retry_backoff_ms' => 400],
            'cron' => [
                'lock_path' => $serverDir . '/data/lock',
                'log_path' => $serverDir . '/data/logs/app.log',
                'log_max_bytes' => 524288,
                'log_keep' => 3,
                'rejected_log' => $serverDir . '/data/logs/rejected.log',
                'alert_webhook_url' => '',
                'alert_after_minutes' => 15,
            ],
            'http_trigger_token' => 'WgoQcpvCk6-tKRPQ',
            'admin_token' => '9FWhb-58lISIP67M',
            'paths' => [
                'last_good' => $serverDir . '/data/last_good.json',
                'publish' => $serverDir . '/public/prices.json',
                'state' => $serverDir . '/data/state.json',
                'override' => $serverDir . '/data/override.json',
            ],
        ];
    }
}
