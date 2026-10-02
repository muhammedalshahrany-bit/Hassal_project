<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One currency and one rate per deal, not two of each.
 *
 * `deals` stored the same fact twice, under two names:
 *
 *   currency_code        — 'SAR'      transaction_currency  — 'SAR'
 *   exchange_rate_snapshot — 0.0040   exchange_rate         — 0.0040
 *
 * Both pairs were written from one expression, in `PricingAndCurrencyService`,
 * one row after the other, so the two copies could only ever agree at the
 * moment of creation — and nothing read the second name. `API_CONTRACT.md`
 * documents neither `transaction_currency` nor `exchange_rate`; the web client
 * declares them in its generated type as optional and never touches them. That
 * makes them the purest form of the thing 3NF exists to remove: a column whose
 * only job is to repeat another column, so every future writer has to remember
 * to write both, and every reader has to guess which one is authoritative.
 *
 * `currency_code` and `exchange_rate_snapshot` survive, and the choice is not
 * arbitrary: `_snapshot` is the name this schema already uses for every
 * point-in-time commercial term (`tax_rate_snapshot`,
 * `platform_fee_rate_snapshot`, `rate_source` beside it), and `currency_code`
 * is the name the whole platform uses for the same field on offers, payments,
 * reservations and ledger entries. The retired names were the odd ones out.
 *
 * `base_currency` stays. It is *not* a copy of `currency_code` — it records
 * which currency the rate was expressed against, which is information no other
 * column holds and which can legitimately differ from it.
 */
return new class extends Migration
{
    private const RETIRED = [
        'transaction_currency',
        'exchange_rate',
    ];

    public function up(): void
    {
        foreach (self::RETIRED as $column) {
            if (Schema::hasColumn('deals', $column)) {
                DB::statement("ALTER TABLE `deals` DROP COLUMN `{$column}`");
            }
        }
    }

    public function down(): void
    {
        // Restored with the defaults the original DDL carried, filled from the
        // columns that were never removed, so a rollback returns a table whose
        // two copies agree rather than one holding empty strings.
        if (!Schema::hasColumn('deals', 'transaction_currency')) {
            DB::statement('ALTER TABLE `deals` ADD COLUMN `transaction_currency` VARCHAR(3) NOT NULL DEFAULT \'YER\' AFTER `currency_code`');
            DB::statement('UPDATE `deals` SET `transaction_currency` = `currency_code`');
        }

        if (!Schema::hasColumn('deals', 'exchange_rate')) {
            DB::statement('ALTER TABLE `deals` ADD COLUMN `exchange_rate` DECIMAL(18,6) NOT NULL DEFAULT 1.000000 AFTER `base_currency`');
            DB::statement('UPDATE `deals` SET `exchange_rate` = `exchange_rate_snapshot`');
        }
    }
};
