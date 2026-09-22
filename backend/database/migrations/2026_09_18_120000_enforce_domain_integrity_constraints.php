<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Converge the schema onto the domain rules the product actually claims.
 *
 * Three problems this fixes, all of them "the database was asserting
 * something the platform cannot support":
 *
 * 1. `organizations.rating` defaulted to 5.00. Every business that never
 *    completed a transaction was shown at a perfect score. Reputation has to
 *    be earned from recorded deals, so an unrated business now has NULL, and
 *    the UI must say "لا توجد تقييمات" instead of five gold stars.
 *
 * 2. `deals.tax_rate_snapshot` and `platform_fee_rate_snapshot` defaulted to
 *    0.1500 and 0.0250 — a 15% VAT and a 2.5% commission baked into DDL. Those
 *    are legal and commercial decisions for the operator of each deployment,
 *    not facts about the universe. Defaults are 0 and come from configuration.
 *    (The original migration files were corrected too; this one brings
 *    databases already migrated from them up to date.)
 *
 * 3. Quantity columns had no database-level guard, and the service layer
 *    "protected" them with PHP `max(0, ...)` clamps that hide the very
 *    oversell they should have surfaced. CHECK constraints make an invalid
 *    stock state impossible to persist, whatever code writes it.
 *
 * Yemeni coordinates replace the Riyadh defaults that previously sat in
 * `warehouses` — already fixed at source, restated here for existing schemas.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Reputation must never be invented ──────────────────────────────
        DB::statement('ALTER TABLE `organizations` MODIFY `rating` DECIMAL(3,2) NULL DEFAULT NULL');
        DB::statement('UPDATE `organizations` SET `rating` = NULL');

        // ── 2. No tax or commission rate is a schema assumption ───────────────
        DB::statement('ALTER TABLE `deals` MODIFY `tax_rate_snapshot` DECIMAL(6,4) NOT NULL DEFAULT 0.0000');
        DB::statement('ALTER TABLE `deals` MODIFY `platform_fee_rate_snapshot` DECIMAL(6,4) NOT NULL DEFAULT 0.0000');

        // Warehouse coordinates: nullable, never a silent location.
        DB::statement('ALTER TABLE `warehouses` MODIFY `latitude` DECIMAL(10,7) NULL DEFAULT NULL');
        DB::statement('ALTER TABLE `warehouses` MODIFY `longitude` DECIMAL(10,7) NULL DEFAULT NULL');
        DB::statement('UPDATE `warehouses` SET `latitude` = NULL, `longitude` = NULL WHERE `latitude` = 24.7136 AND `longitude` = 46.6753');

        // ── 3. Quantity integrity at the database level ───────────────────────
        //
        // Declared / reserved / sold are separate facts within HASSAL. The
        // invariant that must be impossible to violate is that they never
        // overlap into more than what the seller declared.
        $this->addCheck('offers', 'offers_quantity_not_negative', '(`total_quantity` >= 0 AND `reserved_quantity` >= 0 AND `sold_quantity` >= 0 AND `available_quantity` >= 0)');
        $this->addCheck('offers', 'offers_declared_quantity_covers_ledger', '(`reserved_quantity` + `sold_quantity` + `available_quantity` <= `total_quantity`)');
        $this->addCheck('offers', 'offers_price_positive', '(`unit_price` > 0)');
        $this->addCheck('offers', 'offers_min_order_positive', '(`min_order_quantity` > 0)');
        $this->addCheck('offers', 'offers_max_order_ge_min', '(`max_order_quantity` IS NULL OR `max_order_quantity` >= `min_order_quantity`)');

        $this->addCheck('reservations', 'reservations_quantity_positive', '(`quantity` > 0)');
        $this->addCheck('reservations', 'reservations_price_positive', '(`unit_price` > 0)');

        $this->addCheck('deals', 'deals_quantity_positive', '(`quantity` > 0)');
        $this->addCheck('deals', 'deals_unit_price_positive', '(`unit_price` > 0)');
        $this->addCheck('deals', 'deals_amounts_not_negative', '(`base_amount` >= 0 AND `discount_amount` >= 0 AND `tax_amount` >= 0 AND `platform_fee_amount` >= 0 AND `delivery_fee_amount` >= 0)');

        $this->addCheck('purchase_requests', 'purchase_requests_quantity_positive', '(`requested_quantity` > 0)');
        $this->addCheck('purchase_requests', 'purchase_requests_price_positive', '(`requested_price` > 0)');

        $this->addCheck('demand_matches', 'demand_matches_quantity_positive', '(`requested_quantity` > 0)');
    }

    public function down(): void
    {
        $drops = [
            'offers' => ['offers_quantity_not_negative', 'offers_declared_quantity_covers_ledger', 'offers_price_positive', 'offers_min_order_positive', 'offers_max_order_ge_min'],
            'reservations' => ['reservations_quantity_positive', 'reservations_price_positive'],
            'deals' => ['deals_quantity_positive', 'deals_unit_price_positive', 'deals_amounts_not_negative'],
            'purchase_requests' => ['purchase_requests_quantity_positive', 'purchase_requests_price_positive'],
            'demand_matches' => ['demand_matches_quantity_positive'],
        ];

        foreach ($drops as $table => $names) {
            foreach ($names as $name) {
                DB::statement("ALTER TABLE `{$table}` DROP CHECK `{$name}`");
            }
        }

        DB::statement('ALTER TABLE `organizations` MODIFY `rating` DECIMAL(3,2) NOT NULL DEFAULT 5.00');
        DB::statement('ALTER TABLE `deals` MODIFY `tax_rate_snapshot` DECIMAL(6,4) NOT NULL DEFAULT 0.1500');
        DB::statement('ALTER TABLE `deals` MODIFY `platform_fee_rate_snapshot` DECIMAL(6,4) NOT NULL DEFAULT 0.0250');
        DB::statement('ALTER TABLE `warehouses` MODIFY `latitude` DECIMAL(10,7) NOT NULL DEFAULT 24.7136000');
        DB::statement('ALTER TABLE `warehouses` MODIFY `longitude` DECIMAL(10,7) NOT NULL DEFAULT 46.6753000');
    }

    /**
     * Idempotent CHECK addition. SQLite (used by a couple of legacy setups)
     * cannot ALTER TABLE ADD CONSTRAINT, so this quietly no-ops there and the
     * MySQL path stays the one that matters.
     */
    private function addCheck(string $table, string $name, string $expression): void
    {
        $exists = DB::select(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $name]
        );

        if ($exists !== []) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK {$expression}");
    }
};
