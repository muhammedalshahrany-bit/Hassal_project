<?php

namespace App\Platform\Services;

use App\Platform\Models\SystemSetting;
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
     * Write a setting's value.
     *
     * An existing row keeps the type, group, descriptions and editability it
     * was declared with: the call changes the value and nothing else. That
     * matters because the caller's arguments used to overwrite the whole row —
     * editing `tax.rate` through `POST /settings/update` (which sends no type
     * and no group) silently retyped a decimal to a string, moved it out of the
     * `tax` group, and erased both descriptions it is listed under.
     *
     * A row that does not exist yet is created from the arguments given, since
     * for a new key the caller is the only source of that metadata.
     */
    public function set(
        string $key,
        mixed $value,
        string $type = 'string',
        string $group = 'platform',
        ?string $descAr = null,
        ?string $descEn = null,
        ?int $organizationId = null
    ): SystemSetting {
        $stringValue = is_array($value) || is_object($value) ? json_encode($value) : (string)$value;

        $query = SystemSetting::where('key', $key);
        if ($organizationId === null) {
            $query->whereNull('organization_id');
        } else {
            $query->where('organization_id', $organizationId);
        }
        $existing = $query->first();

        if ($existing !== null) {
            $existing->value = $stringValue;
            if ($descAr !== null) $existing->description_ar = $descAr;
            if ($descEn !== null) $existing->description_en = $descEn;
            $existing->save();

            $setting = $existing;
        } else {
            $setting = SystemSetting::create([
                'key' => $key,
                'value' => $stringValue,
                // A row created through this path has no earlier default, so the
                // value that introduced the key is the one it can return to.
                'default_value' => $stringValue,
                'type' => $type,
                'group' => $group,
                'description_ar' => $descAr,
                'description_en' => $descEn,
                'is_editable' => true,
                'organization_id' => $organizationId,
            ]);
        }

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
