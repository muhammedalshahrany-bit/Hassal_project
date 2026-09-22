<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `offers.status` may only hold the seven commercial states the product defines.
 *
 * Two values had crept into the column and were never part of the offer
 * lifecycle:
 *
 *   FULLY_RESERVED — a *quantity* fact stored as a *status*. The same
 *     information already lived in `available_quantity`/`reserved_quantity`, so
 *     the two could disagree, and a buyer looking at a "fully reserved" offer
 *     was shown an internal bookkeeping detail instead of "nothing left".
 *     It now maps to SOLD_OUT, which is what the customer actually needs to
 *     know, or ACTIVE if stock had already returned.
 *
 *   ARCHIVED — a terminal state nobody defined a way into or out of. It maps
 *     to CANCELLED, the one withdrawal the seller can perform deliberately.
 *
 * The CHECK constraint is the point of this migration. A vocabulary that only
 * exists in a PHP docstring is a convention; in the column definition it is a
 * rule, and the next service that invents an eighth status fails loudly at the
 * database instead of quietly producing an offer no state machine can move.
 */
return new class extends Migration
{
    private const CANONICAL = [
        'DRAFT',
        'PENDING_REVIEW',
        'ACTIVE',
        'PAUSED',
        'SOLD_OUT',
        'EXPIRED',
        'CANCELLED',
    ];

    public function up(): void
    {
        DB::statement("
            UPDATE `offers`
               SET `status` = CASE WHEN `available_quantity` <= 0.0001
                                   THEN 'SOLD_OUT' ELSE 'ACTIVE' END
             WHERE `status` = 'FULLY_RESERVED'
        ");

        DB::statement("UPDATE `offers` SET `status` = 'CANCELLED' WHERE `status` = 'ARCHIVED'");

        $values = implode(',', array_map(
            static fn (string $status): string => "'{$status}'",
            self::CANONICAL
        ));

        DB::statement("ALTER TABLE `offers` ADD CONSTRAINT `offers_status_is_canonical` CHECK (`status` IN ({$values}))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `offers` DROP CHECK `offers_status_is_canonical`');
    }
};
