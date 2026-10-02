<?php

use App\Marketplace\Models\Offer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `offers.delivery_terms` goes back to holding the codes it is compared against.
 *
 * The column is a `varchar(50)` whose vocabulary is `Offer::DELIVERY_TERMS` —
 * `BUYER_PICKUP`, `SELLER_DELIVERY`, `BOTH` — and three readers depend on that:
 * the ask sheet opens or closes its two delivery halves by comparing the value
 * (`src/features/trading/order-request.ts`), `GET /marketplace/offers?delivery_terms=`
 * and the nearby search match on it (`OfferQueries`, `GeoDiscoveryService`), and
 * `DemandMatchingService` prints it as a reason a match was offered. Each of those
 * is an equality test against a code.
 *
 * `SandboxSeeder` wrote a display phrase instead — 'الاستلام من مستودع البائع',
 * "collection from the seller's warehouse" — and because it builds its rows inside
 * `Offer::unguarded()`, the `Rule::in(Offer::DELIVERY_TERMS)` that every HTTP write
 * passes never ran. Measured on `hassal_production` before this migration: 16 offers,
 * 15 carrying that phrase and 1 carrying `BOTH`. The fault was visible on the market
 * screen — an ask sheet on a seeded listing greyed out both delivery halves, because
 * the value matched neither code, while its send button stayed live. A buyer with no
 * way to say how they want the goods, on a listing the server would have accepted
 * either answer on.
 *
 * So: repair the words this platform's own screens use, correct the codes that only
 * drifted in case or spacing, and refuse everything else. A value outside the
 * vocabulary that is not one of these phrases is a seller's statement nobody recorded
 * how to read, and guessing it would put the migration's opinion into the data.
 */
return new class extends Migration
{
    private const TABLE = 'offers';

    private const COLUMN = 'delivery_terms';

    /**
     * The Arabic label words for the three codes — the seeder's phrase and the
     * wording `features/marketplace/labels.ts` puts under each of them — mapped to
     * the code they were describing.
     */
    private const PHRASES = [
        'الاستلام من مستودع البائع' => Offer::DELIVERY_BUYER_PICKUP,
        'استلام من المستودع' => Offer::DELIVERY_BUYER_PICKUP,
        'توصيل من البائع' => Offer::DELIVERY_SELLER_DELIVERY,
        'استلام أو توصيل' => Offer::DELIVERY_BOTH,
    ];

    public function up(): void
    {
        if (!Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        foreach (self::PHRASES as $phrase => $code) {
            DB::statement(
                'UPDATE `' . self::TABLE . '` SET `' . self::COLUMN . '` = ? WHERE `' . self::COLUMN . '` = ?',
                [$code, $phrase]
            );
        }

        // `both` is the code `BOTH` and none of the three readers folds case, so a
        // value that differs from a code only in spelling or padding is corrected
        // rather than treated as a statement of its own.
        DB::statement(
            'UPDATE `' . self::TABLE . '` SET `' . self::COLUMN . '` = UPPER(TRIM(`' . self::COLUMN . '`)) '
            . 'WHERE `' . self::COLUMN . '` IS NOT NULL '
            . 'AND `' . self::COLUMN . '` <> UPPER(TRIM(`' . self::COLUMN . '`))'
        );

        $codes = Offer::DELIVERY_TERMS;
        $placeholders = implode(', ', array_fill(0, count($codes), '?'));

        // NULL reads as `BOTH` to every one of those readers — it is what the
        // column's own default says about a listing that declared nothing — so the
        // rows that predate that default are given the value it already behaved as.
        DB::statement(
            'UPDATE `' . self::TABLE . '` SET `' . self::COLUMN . '` = ? WHERE `' . self::COLUMN . '` IS NULL',
            [Offer::DELIVERY_BOTH]
        );

        $unrecognised = DB::select(
            'SELECT DISTINCT `' . self::COLUMN . '` FROM `' . self::TABLE . '` '
            . 'WHERE `' . self::COLUMN . '` NOT IN (' . $placeholders . ')',
            $codes
        );

        if ($unrecognised !== []) {
            throw new RuntimeException(
                'offers.delivery_terms still holds ' . count($unrecognised) . ' value(s) outside '
                . implode('/', $codes) . ': '
                . implode(', ', array_map(
                    static fn (object $row): string => json_encode($row->{self::COLUMN}, JSON_UNESCAPED_UNICODE),
                    $unrecognised
                ))
                . '. Record which half of the transaction each seller meant, correct the row, then migrate again: '
                . 'this migration repairs the phrases the platform wrote for itself, and chooses nothing for anybody else.'
            );
        }
    }

    /**
     * There is no honest reversal.
     *
     * The phrases replaced here were never seller statements — no route accepts
     * them, because `OfferInputRules` validates the three codes on every write — so
     * putting them back would re-create values the platform cannot read in order to
     * reproduce a seeder's mistake. Undoing this means undoing `SandboxSeeder`,
     * which is where the phrase actually came from.
     */
    public function down(): void
    {
        // deliberately empty
    }
};
