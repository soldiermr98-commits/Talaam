<?php
/**
 * رجیستری منابع — ساخت provider از بلوک config.
 * افزودن منبع جدید = افزودن یک بلوک به providers در config.php؛ بدون تغییر کد.
 */
class ProviderFactory
{
    /**
     * @param array $spec ['class' => '…', 'options' => [...]]
     * @throws RuntimeException پیکربندی بد → پیام واضح
     */
    public static function create(array $spec, array $http): ProviderInterface
    {
        $class = trim((string)($spec['class'] ?? ''));
        if ($class === '') {
            throw new RuntimeException('provider entry is missing "class"');
        }
        if (!class_exists($class)) {
            throw new RuntimeException("unknown provider class '$class' — expected one of: ConfigurableHttpProvider, ManualProvider, or a custom adapter class");
        }
        $options = is_array($spec['options'] ?? null) ? $spec['options'] : [];
        return new $class($options, $http);
    }

    /** ساخت همه منابع از config — خطاها جمع و پرتاب میشوند تا config بد زودتر معلوم شود */
    public static function createAll(array $config): array
    {
        $http = is_array($config['http'] ?? null) ? $config['http'] : [];
        $providers = [];
        foreach (($config['providers'] ?? []) as $i => $spec) {
            if (!is_array($spec)) {
                throw new RuntimeException("providers[$i] must be an array with 'class' and 'options'");
            }
            $providers[] = self::create($spec, $http);
        }
        return $providers;
    }
}
