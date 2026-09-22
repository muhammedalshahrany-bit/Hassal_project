<?php

namespace App\Support;

use App\Models\FeatureFlag;
use App\Models\SystemSetting;
use App\Services\FeatureFlagService;
use Illuminate\Support\Facades\Cache;

/**
 * The one place that knows how a catalog row becomes a database row.
 *
 * Why this exists: `app:install-system` seeds both catalogs once, on a fresh
 * database. Anything added to a catalog afterwards never reaches an install
 * that is already live, and the failure is invisible in both directions.
 * `FeatureFlagService::isEnabled()` fails closed on an unknown key, so a
 * released capability stays off with no admin screen showing the switch;
 * `SystemSettingsService::get()` answers from the caller's fallback, so the
 * console lists a setting nobody can edit. That is how the offer community
 * vanished from a deployed marketplace — the code, the routes and the tests all
 * had it, and the live `feature_flags` table did not.
 *
 * Two rules, and they are the whole point:
 *   * **Insert what is missing.** The catalog is the only source for a key's
 *     type, group, descriptions and public visibility — a row created without
 *     them shows up in the console as a bare key with no explanation.
 *   * **Never overwrite what exists.** A stored value is an operator's
 *     decision. Re-running a sync must not undo a tax rate or switch a
 *     capability back on.
 *
 * `settings:sync-catalog` and `features:sync-catalog` report drift through
 * here, the post-deploy migration inserts through here, and `app:install-system`
 * keeps its own upsert because on a fresh database it also folds in the
 * country, currency and timezone the operator typed into the wizard.
 */
final class CatalogSync
{
    /**
     * Catalog settings with no global row in this database.
     *
     * @return list<array<string, mixed>>
     */
    public static function missingSettings(): array
    {
        $have = SystemSetting::whereNull('organization_id')->pluck('key')->all();

        return array_values(array_filter(
            SettingCatalog::defaults(),
            static fn (array $row): bool => !in_array($row['key'], $have, true),
        ));
    }

    /**
     * Catalog feature flags with no row in this database.
     *
     * @return list<array<string, mixed>>
     */
    public static function missingFlags(): array
    {
        $have = FeatureFlag::pluck('key')->all();

        return array_values(array_filter(
            FlagCatalog::defaults(),
            static fn (array $row): bool => !in_array($row['key'], $have, true),
        ));
    }

    public static function insertSetting(array $row): SystemSetting
    {
        $setting = SystemSetting::create([
            'key'             => $row['key'],
            'value'           => $row['value'],
            'default_value'   => $row['default_value'] ?? $row['value'],
            'type'            => $row['type'],
            'group'           => $row['group'],
            'description_ar'  => $row['description_ar'] ?? null,
            'description_en'  => $row['description_en'] ?? null,
            'is_public'       => (bool) ($row['is_public'] ?? false),
            'is_editable'     => (bool) ($row['is_editable'] ?? true),
            'organization_id' => null,
        ]);

        self::forgetSettingCache($setting->key);

        return $setting;
    }

    public static function insertFlag(array $row): FeatureFlag
    {
        $flag = FeatureFlag::create([
            'key'            => $row['key'],
            'is_enabled'     => (bool) $row['is_enabled'],
            'name_ar'        => $row['name_ar'],
            'name_en'        => $row['name_en'],
            'description_ar' => $row['description_ar'] ?? null,
        ]);

        app(FeatureFlagService::class)->flushCache();

        return $flag;
    }

    /**
     * Insert every missing catalog row. Returns how many were created.
     *
     * @return array{settings: int, flags: int}
     */
    public static function run(): array
    {
        $settings = self::missingSettings();
        $flags = self::missingFlags();

        foreach ($settings as $row) {
            self::insertSetting($row);
        }

        foreach ($flags as $row) {
            self::insertFlag($row);
        }

        return ['settings' => count($settings), 'flags' => count($flags)];
    }

    /**
     * Drift this database has, as keys. Used by the sync commands and by the
     * post-install guard in `app:install-system --check`.
     *
     * @return array{settings: list<string>, flags: list<string>}
     */
    public static function drift(): array
    {
        return [
            'settings' => array_column(self::missingSettings(), 'key'),
            'flags'    => array_column(self::missingFlags(), 'key'),
        ];
    }

    private static function forgetSettingCache(string $key): void
    {
        Cache::forget('hassal_sys_setting_' . $key . '_global');
        Cache::forget('hassal_public_settings');
    }
}
