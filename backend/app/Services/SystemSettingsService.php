<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

class SystemSettingsService
{
    protected const CACHE_KEY_PREFIX = 'hassal_sys_setting_';
    protected const CACHE_TTL = 3600; // 1 hour

    /**
     * Retrieves a typed system setting value with caching and default fallback.
     */
    public function get(string $key, mixed $default = null, ?int $organizationId = null): mixed
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $key . '_' . ($organizationId ?? 'global');

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($key, $default, $organizationId) {
            // First check organization override if requested
            if ($organizationId) {
                $orgSetting = SystemSetting::where('key', $key)->where('organization_id', $organizationId)->first();
                if ($orgSetting !== null) {
                    return $orgSetting->typed_value;
                }
            }

            // Fallback to global platform setting
            $globalSetting = SystemSetting::where('key', $key)->whereNull('organization_id')->first();
            if ($globalSetting !== null) {
                return $globalSetting->typed_value;
            }

            return $default;
        });
    }

    /**
     * Sets or updates a system setting and clears the associated cache.
     */
    public function set(string $key, mixed $value, string $type = 'string', string $group = 'platform', ?string $descAr = null, ?string $descEn = null, ?int $organizationId = null): SystemSetting
    {
        $stringValue = is_array($value) || is_object($value) ? json_encode($value) : (string)$value;

        $setting = SystemSetting::updateOrCreate(
            ['key' => $key, 'organization_id' => $organizationId],
            [
                'value' => $stringValue,
                'type' => $type,
                'group' => $group,
                'description_ar' => $descAr,
                'description_en' => $descEn,
                'is_editable' => true,
            ]
        );

        $cacheKey = self::CACHE_KEY_PREFIX . $key . '_' . ($organizationId ?? 'global');
        Cache::forget($cacheKey);
        Cache::forget('hassal_public_settings');

        return $setting;
    }

    /**
     * Returns all public settings suitable for client bootstrap.
     */
    public function getPublicSettings(): array
    {
        return Cache::remember('hassal_public_settings', 600, function () {
            $settings = SystemSetting::where('is_public', true)->whereNull('organization_id')->get();
            $result = [];
            foreach ($settings as $setting) {
                $result[$setting->key] = $setting->typed_value;
            }
            return $result;
        });
    }
}
