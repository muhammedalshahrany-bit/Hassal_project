<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HASSAL Production Hardening — Phase 1
 *
 * Changes:
 *   1. organizations.vat_number    → nullable (not all orgs have VAT)
 *   2. organizations.country_code  → new column (ISO-3166-1 alpha-2, default 'YE')
 *   3. organizations.city          → new column (for geo search & profile display)
 *   4. organizations.status        → rename pending_kyc → PENDING_VERIFICATION for clarity
 *      (done via application-level Enum, column stays varchar)
 *
 * Warehouses:
 *   5. warehouses.district         → add nullable district column if missing
 *   6. warehouses.country_code     → add for multi-country support
 *
 * Offers:
 *   7. offers.currency_code        → ensure default is 'YER' (already set, but guard)
 *
 * Currencies table:
 *   8. currencies.exchange_rate_to_base → add if missing (float, default 1.0)
 *   9. currencies.is_default            → add if missing (boolean)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1-3: Organizations enhancements ───────────────────────────────────
        Schema::table('organizations', function (Blueprint $table) {
            // Make vat_number nullable (many SMEs in Yemen don't have VAT numbers)
            $table->string('vat_number', 50)->nullable()->change();

            // Country code for multi-country expansion (default Yemen)
            if (!Schema::hasColumn('organizations', 'country_code')) {
                $table->string('country_code', 2)->default('YE')->after('type');
            }

            // City for geo display and search
            if (!Schema::hasColumn('organizations', 'city')) {
                $table->string('city', 100)->nullable()->after('country_code');
            }

            // Trade name (distinct from legal name) — useful for display
            if (!Schema::hasColumn('organizations', 'trade_name_ar')) {
                $table->string('trade_name_ar', 255)->nullable()->after('name_en');
            }
            if (!Schema::hasColumn('organizations', 'trade_name_en')) {
                $table->string('trade_name_en', 255)->nullable()->after('trade_name_ar');
            }

            // Website for B2B profile
            if (!Schema::hasColumn('organizations', 'website')) {
                $table->string('website', 255)->nullable()->after('city');
            }

            // Phone & email for organization contact (separate from user accounts)
            if (!Schema::hasColumn('organizations', 'phone')) {
                $table->string('phone', 30)->nullable()->after('website');
            }
            if (!Schema::hasColumn('organizations', 'email')) {
                $table->string('email', 255)->nullable()->after('phone');
            }
        });

        // ── 5-6: Warehouses enhancements ─────────────────────────────────────
        Schema::table('warehouses', function (Blueprint $table) {
            if (!Schema::hasColumn('warehouses', 'district')) {
                $table->string('district', 100)->nullable()->after('city');
            }
            if (!Schema::hasColumn('warehouses', 'country_code')) {
                $table->string('country_code', 2)->default('YE')->after('city');
            }
        });

        // ── 8-9: Currencies columns ───────────────────────────────────────────
        Schema::table('currencies', function (Blueprint $table) {
            if (!Schema::hasColumn('currencies', 'exchange_rate_to_base')) {
                $table->decimal('exchange_rate_to_base', 18, 8)->default(1.00000000)->after('symbol');
            }
            if (!Schema::hasColumn('currencies', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('is_active');
            }
        });

        // ── Indexes for new columns ────────────────────────────────────────────
        Schema::table('organizations', function (Blueprint $table) {
            $table->index('country_code');
            $table->index(['country_code', 'type', 'status']);
        });

        Schema::table('warehouses', function (Blueprint $table) {
            $table->index('country_code');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('vat_number', 50)->unique()->change();
            $table->dropColumn(array_filter([
                Schema::hasColumn('organizations', 'country_code')  ? 'country_code'  : null,
                Schema::hasColumn('organizations', 'city')          ? 'city'          : null,
                Schema::hasColumn('organizations', 'trade_name_ar') ? 'trade_name_ar' : null,
                Schema::hasColumn('organizations', 'trade_name_en') ? 'trade_name_en' : null,
                Schema::hasColumn('organizations', 'website')       ? 'website'       : null,
                Schema::hasColumn('organizations', 'phone')         ? 'phone'         : null,
                Schema::hasColumn('organizations', 'email')         ? 'email'         : null,
            ]));
        });

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn(array_filter([
                Schema::hasColumn('warehouses', 'district')     ? 'district'     : null,
                Schema::hasColumn('warehouses', 'country_code') ? 'country_code' : null,
            ]));
        });

        Schema::table('currencies', function (Blueprint $table) {
            $table->dropColumn(array_filter([
                Schema::hasColumn('currencies', 'exchange_rate_to_base') ? 'exchange_rate_to_base' : null,
                Schema::hasColumn('currencies', 'is_default')            ? 'is_default'            : null,
            ]));
        });
    }
};
