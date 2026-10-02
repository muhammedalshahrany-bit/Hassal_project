<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every money amount on the platform is now denominated in a currency that exists.
 *
 * `currencies` is the vocabulary — ISO code, symbol, decimal places, the
 * operator's rate, whether it is usable at all — and eleven tables carry a
 * `currency_code` column pointing into it. Not one of them said so: the columns
 * are plain `varchar(3)` with a default of `'YER'`, held together by validation
 * rules written in eleven different places, some of which check `exists` and
 * `is_active` (`OfferController::store`, `DemandController` for demands and
 * quotations) and some of which only check `size:3`.
 *
 * The consequence is not a hypothetical. The ledger is the one table set whose
 * whole purpose is that its numbers can be trusted: a `journal_entries` row
 * stamped `SAR`, a currency the platform never declared and therefore has no
 * rate, no symbol and no decimal count for, would still balance in `debits` and
 * `credits` and still print in a report — as three digits next to a number whose
 * unit nobody can name. The application cannot promise that, because nothing
 * below the application refused it either.
 *
 * So this adds the constraint the code has been assuming:
 *
 *   {table}.currency_code → currencies.iso_code   RESTRICT on delete, CASCADE on rename
 *
 * `iso_code` is already UNIQUE, which is the only thing that makes a natural key
 * like this safe to point at — and `RESTRICT` is the correct delete rule for a
 * vocabulary the platform must not be able to yank out from under its own
 * history: an administrator who retires a currency *deactivates* it
 * (`is_active = false`, which is what the pricing service refuses to price
 * against), because the transactions written in it stay in the ledger forever.
 * `CASCADE` on update is the counterpart: when a code is corrected, the rows
 * that named it follow it instead of being orphaned by the fix.
 *
 * What an undeclared code costs is not abstract: the symbol a report prints, the
 * number of decimal places a total is rounded to, and the rate that converts a
 * deal into the platform's base currency all live only on a `currencies` row.
 * A code with no row is an amount that can be stored, summed and exported while
 * nobody — including the person reading the export — can say what unit it is in.
 */
return new class extends Migration
{
    /**
     * Tables whose `currency_code` is a currency, not a free-text remark.
     */
    private const GUARDED = [
        'deals',
        'demands',
        'journal_entries',
        'ledger_accounts',
        'offer_items',
        'offers',
        'purchase_requests',
        'quotations',
        'reservations',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('currencies')) {
            return;
        }

        // A foreign key cannot be added over rows that already break it, and an
        // orphan here means a real amount is already recorded in a currency the
        // platform never declared. Reconcile it — usually by adding the currency
        // row with the rate the operator intended — and run this again.
        $orphans = $this->orphanCount();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Refusing to constrain currency_code: {$orphans} row(s) name a currency that is not in `currencies`. "
                . 'Fix the codes or declare the currencies, then migrate again. '
                . 'List them with the query in orphanCount().'
            );
        }

        foreach (self::GUARDED as $table) {
            if (!Schema::hasColumn($table, 'currency_code') || $this->hasCurrencyKey($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->foreign('currency_code')
                    ->references('iso_code')
                    ->on('currencies')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
            });
        }
    }

    public function down(): void
    {
        foreach (self::GUARDED as $table) {
            if (!Schema::hasColumn($table, 'currency_code')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropForeign($table . '_currency_code_foreign');
            });
        }
    }

    /**
     * Rows whose currency code is not declared in `currencies`.
     */
    private function orphanCount(): int
    {
        $total = 0;

        foreach (self::GUARDED as $table) {
            if (!Schema::hasColumn($table, 'currency_code')) {
                continue;
            }

            $sql = 'SELECT 1 FROM `' . $table . '` o
                      WHERE NOT EXISTS (SELECT 1 FROM `currencies` c WHERE c.`iso_code` = o.`currency_code`)';

            $total += count(DB::select($sql));
        }

        return $total;
    }

    private function hasCurrencyKey(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
                AND REFERENCED_TABLE_NAME = \'currencies\'',
            [$table, 'currency_code']
        ) !== [];
    }
};
