<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An offer's category is a fact about its product, so the offer stops holding it.
 *
 * `offers.category_id` was a non-key column determined by another non-key
 * column — the textbook transitive dependency 3NF forbids:
 *
 *   offers.product_variant_id → product_variants.product_id → products.category_id
 *
 * The platform already treats the product as the source: `store()` accepts a
 * `category_id` from the seller only to refuse the request with
 * `CATEGORY_PRODUCT_MISMATCH` when it differs from `$variant->product->category_id`,
 * and falls back to the product's when it is absent (§4.4 of the contract
 * describes exactly that). A column that can only ever be written with a value
 * some other row already holds is not data — it is a copy, and the copy is the
 * one that goes stale: when an administrator reclassifies a product, every
 * listing of it stayed behind in the category the platform had just left. That
 * is a discovery bug the schema was compounding, and `DemandMatchingService`
 * noticed it independently — its "mandatory gate" tested the product's category
 * and the offer's copy in two consecutive blocks, which is one comparison
 * written twice because the two were supposed to be the same number.
 *
 * What the data said, taken before this migration was written: `hassal_production`
 * held 7 offers, 0 of them carrying a category that disagreed with their
 * product's, 0 carrying NULL. Dropping a column nobody can distinguish from its
 * source is the cheapest normalization there is, and this migration is the
 * evidence that it was safe *then* as well as now.
 *
 * Nothing observable moves. `OfferResource` still answers `category_id`, still
 * answers it on every offer surface, and `GET /marketplace/offers?category_id=`
 * and the nearby search still take the same filter — they now read the product
 * through the join instead of reading the copy. The request field is accepted
 * and validated exactly as before, so a client that sends it gets the same
 * `CATEGORY_PRODUCT_MISMATCH` refusal and, additionally, can no longer be the
 * reason a listing sits in the wrong category.
 */
return new class extends Migration
{
    private const TABLE = 'offers';

    private const COLUMN = 'category_id';

    public function up(): void
    {
        if (!Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        // Dropping a copy is only information-preserving while the copy and its
        // source agree. Where they do not, the column holds the last statement
        // someone made about that listing, and it must be reconciled by hand
        // before this migration is allowed to erase the evidence.
        $disagreements = (int) DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM `offers`
               JOIN `product_variants` ON `product_variants`.`id` = `offers`.`product_variant_id`
               JOIN `products` ON `products`.`id` = `product_variants`.`product_id`
              WHERE NOT (`offers`.`category_id` = `products`.`category_id`
                       OR (`offers`.`category_id` IS NULL AND `products`.`category_id` IS NULL))'
        )?->aggregate;

        if ($disagreements > 0) {
            throw new RuntimeException(
                "Refusing to drop offers.category_id: {$disagreements} offer(s) name a category their product does not. "
                . 'Decide which one is right for each, then migrate again.'
            );
        }

        // MySQL refuses to drop a column an active foreign key still guards,
        // and it built an index of the same name to hold that key.
        foreach ($this->foreignKeysOn(self::TABLE, self::COLUMN) as $name) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` DROP FOREIGN KEY `' . $name . '`');
        }

        DB::statement('ALTER TABLE `' . self::TABLE . '` DROP COLUMN `' . self::COLUMN . '`');
    }

    public function down(): void
    {
        if (Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        DB::statement('ALTER TABLE `' . self::TABLE . '` ADD COLUMN `category_id` BIGINT UNSIGNED NULL AFTER `product_variant_id`');

        // Refilled from its real owner, so a rollback returns copies that agree
        // rather than a column of NULLs the readers would have to tolerate.
        DB::statement(
            'UPDATE `offers`
                JOIN `product_variants` ON `product_variants`.`id` = `offers`.`product_variant_id`
                JOIN `products` ON `products`.`id` = `product_variants`.`product_id`
                SET `offers`.`category_id` = `products`.`category_id`'
        );

        DB::statement(
            'ALTER TABLE `' . self::TABLE . '` ADD CONSTRAINT `offers_category_id_foreign` '
            . 'FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL ON UPDATE CASCADE'
        );
    }

    /**
     * The names of the foreign keys one column of one table belongs to.
     */
    private function foreignKeysOn(string $table, string $column): array
    {
        return array_map(
            fn ($row) => $row->CONSTRAINT_NAME,
            DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
                    AND REFERENCED_TABLE_NAME IS NOT NULL',
                [$table, $column]
            )
        );
    }
};
