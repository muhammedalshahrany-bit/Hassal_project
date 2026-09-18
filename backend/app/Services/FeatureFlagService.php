<?php

namespace App\Services;

use App\Models\FeatureFlag;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class FeatureFlagService
{
    protected const CACHE_KEY = 'hassal_all_feature_flags';
    protected const CACHE_TTL = 3600;

    /**
     * Checks if a feature flag is enabled globally and for a specific context.
     */
    public function isEnabled(string $key, ?User $user = null, ?int $organizationId = null): bool
    {
        $flags = $this->getAllFlags();

        if (!isset($flags[$key])) {
            return true; // Default to true if not registered
        }

        $flag = $flags[$key];
        if (!$flag['is_enabled']) {
            return false;
        }

        $rules = $flag['rules_json'] ?? [];
        if (empty($rules)) {
            return true;
        }

        // Targeted organization check
        if (!empty($rules['allowed_organization_ids']) && $organizationId) {
            if (!in_array($organizationId, $rules['allowed_organization_ids'])) {
                return false;
            }
        }

        // Targeted role check
        if (!empty($rules['allowed_roles']) && $user) {
            $userRoles = $user->getRoleNames()->toArray();
            if (empty(array_intersect($userRoles, $rules['allowed_roles']))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Retrieves all active feature flags key-value mapping.
     */
    public function getAllFlags(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $flags = FeatureFlag::all();
            $mapped = [];
            foreach ($flags as $flag) {
                $mapped[$flag->key] = [
                    'key' => $flag->key,
                    'name_ar' => $flag->name_ar,
                    'name_en' => $flag->name_en,
                    'is_enabled' => (bool)$flag->is_enabled,
                    'rules_json' => $flag->rules_json,
                ];
            }
            return $mapped;
        });
    }

    /**
     * Toggles or updates a feature flag.
     */
    public function setFlag(string $key, bool $isEnabled, ?array $rules = null): FeatureFlag
    {
        $flag = FeatureFlag::updateOrCreate(
            ['key' => $key],
            [
                'is_enabled' => $isEnabled,
                'rules_json' => $rules,
            ]
        );

        Cache::forget(self::CACHE_KEY);
        return $flag;
    }
}
