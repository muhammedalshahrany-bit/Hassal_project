<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Currency;
use App\Models\FeatureFlag;
use App\Models\SystemSetting;
use App\Support\CategoryCatalog;
use App\Support\FlagCatalog;
use App\Support\SettingCatalog;
use Illuminate\Database\Seeder;

/**
 * Reference data only.
 *
 * Everything here is platform configuration an administrator is expected to
 * review and change: the RBAC matrix, system settings, currency registry,
 * feature flags, and the food-sector classification the product starts from.
 *
 * Settings and flags come from App\Support\SettingCatalog and FlagCatalog —
 * the same lists `app:install-system` seeds — so there is exactly one
 * vocabulary of keys between the installer, the admin console and the code
 * that reads them at runtime.
 *
 * Deliberately absent:
 *   - user accounts, organizations, memberships   (nobody is invented by a seeder)
 *   - products, offers, demands, deals            (same reason)
 *   - any "market reference price"                (no real source to back it)
 *   - any status implying official verification   (no government feed wired)
 *   - any credential, token, or secret
 *
 * A production install gets an empty, honest marketplace from this seeder plus
 * `app:install-system --admin-*`, which creates the first real operator.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        // ─── System settings ───────────────────────────────────────────────────
        //
        // Rates that depend on law or on a commercial agreement the operator
        // must make start at zero, and the code refuses to substitute a number
        // of its own when one is missing.
        foreach (SettingCatalog::defaults() as $setting) {
            SystemSetting::updateOrCreate(['key' => $setting['key']], $setting);
        }

        // ─── Currency registry ─────────────────────────────────────────────────
        //
        // USD is seeded inactive on purpose: an exchange rate published without
        // a source is fabricated data. The admin enters a real rate (or a rate
        // provider supplies one) and only then activates the currency.
        $currencies = [
            ['iso_code' => 'YER', 'name_ar' => 'ريال يمني',    'name_en' => 'Yemeni Rial', 'symbol' => 'ر.ي', 'decimal_places' => 2, 'is_default' => true,  'is_active' => true,  'exchange_rate_to_base' => 1.000000, 'rate_source' => 'BASE_CURRENCY'],
            ['iso_code' => 'USD', 'name_ar' => 'دولار أمريكي', 'name_en' => 'US Dollar',   'symbol' => '$', 'decimal_places' => 2, 'is_default' => false, 'is_active' => false, 'exchange_rate_to_base' => 1.000000, 'rate_source' => 'UNSET_SET_BEFORE_USE'],
        ];

        foreach ($currencies as $currency) {
            Currency::updateOrCreate(['iso_code' => $currency['iso_code']], $currency);
        }

        // ─── Feature flags ─────────────────────────────────────────────────────
        foreach (FlagCatalog::defaults() as $flag) {
            FeatureFlag::updateOrCreate(['key' => $flag['key']], $flag);
        }

        // ─── Food-sector classification the product starts from ────────────────
        foreach (CategoryCatalog::defaults() as $category) {
            Category::updateOrCreate(['code' => $category['code']], $category);
        }

        // Brands are supplied by the businesses themselves — none invented here.
    }
}
