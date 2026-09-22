<?php

namespace App\Services;

use App\Models\Demand;
use App\Models\DemandMatch;
use App\Models\Offer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rule-Based Demand Matching Engine
 *
 * Matches active Offers against a given Demand using mandatory filters
 * followed by a weighted ranking score. Each match reason is stored
 * explicitly so the result is fully explainable to the buyer.
 *
 * Scoring breakdown (max 100):
 *   - Same category as the demand                 : 30 pts  [mandatory gate]
 *   - Quantity fully available                    : 15 pts
 *   - Quantity partially available                :  8 pts
 *   - Price within target                         : 15 pts
 *   - Price slightly above target (≤ 10%)         :  5 pts
 *   - City/delivery location match                : 10 pts
 *   - Offer freshness (published ≤ 7 days)        :  5 pts
 *   - Offer expires after demand date             :  5 pts  [bonus, not deducted]
 *
 * Fulfillment terms are reported as a fact but score nothing: a demand carries
 * no delivery preference to compare them against.
 */
class DemandMatchingService
{
    /** Minimum score to store a match record. */
    private const MIN_SCORE = 45.0;

    /**
     * Run the matching engine for a Demand and persist/update results.
     *
     * @return Collection<DemandMatch>
     */
    public function matchDemand(Demand $demand): Collection
    {
        // Only load ACTIVE offers with stock, from a business still allowed to
        // trade; use cursor for large datasets
        $offers = Offer::query()
            ->with(['warehouse', 'variant.product'])
            ->tradable()
            ->where('status', Offer::STATUS_ACTIVE)
            ->where('available_quantity', '>', 0)
            ->whereNull('deleted_at')
            ->get();

        // One row per (demand, offer) — the schema says so with a unique index —
        // and a USED row has already produced a purchase request. Re-running the
        // engine must not turn it back into an open opportunity, or the same
        // shelf could be asked for twice from the same match.
        $alreadyRequested = DemandMatch::where('demand_id', $demand->id)
            ->where('status', DemandMatch::STATUS_USED)
            ->pluck('offer_id')
            ->all();

        $matches = new Collection;

        foreach ($offers as $offer) {
            // Mandatory gates: product/category, active demand, quantity and MOQ.
            if ($demand->status !== Demand::STATUS_OPEN
                || in_array((int) $offer->id, array_map('intval', $alreadyRequested), true)
                || !$this->productCompatible($demand, $offer)
                || !$this->quantityCompatible($demand, $offer)) {
                continue;
            }

            [$score, $reasons] = $this->scoreAndExplain($demand, $offer);

            if ($score < self::MIN_SCORE) {
                continue;
            }

            $requestedQty = min(
                (float) $demand->target_quantity,
                (float) $offer->available_quantity
            );

            $match = DB::transaction(function () use ($demand, $offer, $score, $reasons, $requestedQty) {
                return DemandMatch::updateOrCreate(
                    [
                        'demand_id' => $demand->id,
                        'offer_id'  => $offer->id,
                    ],
                    [
                        'buyer_organization_id'  => $demand->buyer_organization_id,
                        'seller_organization_id' => $offer->seller_organization_id,
                        'requested_quantity'     => $requestedQty,
                        'match_score'            => round($score, 2),
                        'match_reasons'          => $reasons,
                        'estimated_total'        => round($requestedQty * (float) $offer->unit_price, 4),
                        'status'                 => DemandMatch::STATUS_ACTIVE,
                        'expires_at'             => Carbon::now()->addDays(7),
                    ]
                );
            });

            $matches->push($match->fresh(['offer.warehouse', 'offer.variant.product']));
        }

        // A match that stops being true has to stop being offered. The engine
        // re-runs on demand, so any ACTIVE row this pass did not reproduce —
        // the offer sold out, was paused, or moved out of price range — is
        // invalidated rather than left for a buyer to act on next week.
        // USED rows belong to a purchase request already and are not ours to undo.
        $this->invalidateStaleMatches($demand, $matches);

        return $matches->sortByDesc('match_score')->values();
    }

    /**
     * Retire ACTIVE match records the current pass did not reproduce.
     *
     * @param Collection<DemandMatch> $currentMatches
     */
    private function invalidateStaleMatches(Demand $demand, Collection $currentMatches): void
    {
        DemandMatch::where('demand_id', $demand->id)
            ->where('status', DemandMatch::STATUS_ACTIVE)
            ->whereNotIn('offer_id', $currentMatches->pluck('offer_id')->all())
            ->update([
                'status' => DemandMatch::STATUS_INVALIDATED,
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * Run matching for a newly created Offer against all open Demands.
     * Used to proactively notify buyers when a relevant offer appears.
     *
     * @return Collection<DemandMatch>
     */
    public function matchOffer(Offer $offer): Collection
    {
        $offer->load(['warehouse', 'variant.product']);

        $demands = Demand::query()
            ->where('status', Demand::STATUS_OPEN)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', Carbon::now());
            })
            ->get();

        $matches = new Collection;

        foreach ($demands as $demand) {
            if ($demand->status !== Demand::STATUS_OPEN || $offer->status !== Offer::STATUS_ACTIVE || !$this->productCompatible($demand, $offer) || !$this->quantityCompatible($demand, $offer)) {
                continue;
            }

            [$score, $reasons] = $this->scoreAndExplain($demand, $offer);

            if ($score < self::MIN_SCORE) {
                continue;
            }

            $requestedQty = min(
                (float) $demand->target_quantity,
                (float) $offer->available_quantity
            );

            $match = DB::transaction(function () use ($demand, $offer, $score, $reasons, $requestedQty) {
                return DemandMatch::updateOrCreate(
                    [
                        'demand_id' => $demand->id,
                        'offer_id'  => $offer->id,
                    ],
                    [
                        'buyer_organization_id'  => $demand->buyer_organization_id,
                        'seller_organization_id' => $offer->seller_organization_id,
                        'requested_quantity'     => $requestedQty,
                        'match_score'            => round($score, 2),
                        'match_reasons'          => $reasons,
                        'estimated_total'        => round($requestedQty * (float) $offer->unit_price, 4),
                        'status'                 => DemandMatch::STATUS_ACTIVE,
                        'expires_at'             => Carbon::now()->addDays(7),
                    ]
                );
            });

            $matches->push($match->fresh(['demand.buyerOrganization']));
        }

        return $matches->sortByDesc('match_score')->values();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Mandatory gate: Offer must share the same product or category.
     * Without at least a category overlap the match is meaningless.
     */
    private function productCompatible(Demand $demand, Offer $offer): bool
    {
        // Direct product match via variant → product
        if ($offer->variant && $demand->category_id) {
            $offerProduct = $offer->variant->product;

            // Same product (strongest match)
            if ($offerProduct && $demand->category_id === $offerProduct->category_id) {
                return true;
            }
        }

        // Category-level match (weaker but acceptable gate)
        if ($offer->category_id && $demand->category_id && $offer->category_id === $demand->category_id) {
            return true;
        }

        return false;
    }

    private function quantityCompatible(Demand $demand, Offer $offer): bool
    {
        $demandQuantity = (float) $demand->target_quantity;
        $availableQuantity = (float) $offer->available_quantity;
        $minimumOrderQuantity = (float) $offer->min_order_quantity;

        return $availableQuantity >= $minimumOrderQuantity && $demandQuantity >= $minimumOrderQuantity;
    }

    /**
     * Compute a weighted score (0-100) and build an explainable reasons map.
     *
     * @return array{float, array<string, string>}
     */
    private function scoreAndExplain(Demand $demand, Offer $offer): array
    {
        $score   = 0.0;
        $reasons = [];

        // ── 1. Category match (30 pts) ────────────────────────────────────────
        // A demand records a category, not a product id, so the strongest thing
        // that can honestly be said about the goods is that they sit in the
        // category the buyer asked for. This rule used to report itself as
        // `product_match`, which claimed a precision the data does not have.
        if ($offer->category_id && $demand->category_id && $offer->category_id === $demand->category_id) {
            $score += 30;
            $reasons['category_match'] = 'العرض ضمن التصنيف المطلوب';
        }

        // ── 2. Quantity compatibility (15 pts full / 8 pts partial) ──────────
        $offerAvail    = (float) $offer->available_quantity;
        $demandNeeded  = (float) $demand->target_quantity;
        $moq           = (float) $offer->min_order_quantity;

        if ($offerAvail >= $demandNeeded) {
            $score += 15;
            $reasons['quantity_available'] = "الكمية المطلوبة ({$demandNeeded}) متوفرة بالكامل";
        } elseif ($offerAvail >= $moq) {
            $score += 8;
            $reasons['quantity_partial'] = "كمية جزئية متوفرة ({$offerAvail} من {$demandNeeded})";
        }

        // A demand under the offer's minimum order quantity never reaches
        // scoring: quantityCompatible() rejects it as a gate, which is the
        // honest answer, instead of ranking it 10 points lower than a match
        // that could not happen either.

        // ── 4. Price within target (15 pts / 5 pts near-miss) ─────────────────
        if ($demand->target_unit_price && $demand->target_unit_price > 0) {
            $offerPrice  = (float) $offer->unit_price;
            $targetPrice = (float) $demand->target_unit_price;

            if ($offerPrice <= $targetPrice) {
                $score += 15;
                $reasons['price_in_range'] = "السعر ({$offerPrice}) ضمن النطاق المستهدف ({$targetPrice})";
            } elseif ($offerPrice <= $targetPrice * 1.10) {
                // Within 10% over target
                $score += 5;
                $reasons['price_near_target'] = "السعر ({$offerPrice}) قريب من الهدف ({$targetPrice})";
            }
        }

        // ── 5. City / location match (10 pts) ────────────────────────────────
        if ($offer->warehouse && $demand->delivery_city) {
            $warehouseCity = mb_strtolower(trim((string) $offer->warehouse->city));
            $demandCity    = mb_strtolower(trim((string) $demand->delivery_city));

            if ($warehouseCity === $demandCity) {
                $score += 10;
                $reasons['city_match'] = "موقع المستودع ({$offer->warehouse->city}) يطابق مدينة التسليم";
            }
        }

        // ── 6. Fulfillment terms: recorded, not scored ────────────────────────
        // A demand states *what* it needs and *where*, not how it wants goods
        // delivered, so there is nothing here to compare the offer against.
        // Scoring this used to hand every offer 8 points for filling in one
        // field. The terms are shown as a fact the buyer can act on; they will
        // earn points the day a demand carries a preference to match.
        if ($offer->delivery_terms) {
            $reasons['offer_delivery_terms'] = "العرض يسمح بـ ({$offer->delivery_terms})";
        }

        // ── 7. Offer freshness (5 pts if published within 7 days) ─────────────
        if ($offer->published_at && Carbon::parse($offer->published_at)->gte(Carbon::now()->subDays(7))) {
            $score += 5;
            $reasons['offer_fresh'] = 'العرض حديث (نُشر خلال آخر 7 أيام)';
        }

        // ── 8. Offer availability covers demand date (5 pts bonus) ────────────
        if ($demand->target_delivery_date && $offer->expires_at) {
            if (Carbon::parse($offer->expires_at)->gte(Carbon::parse($demand->target_delivery_date))) {
                $score += 5;
                $reasons['availability_timing'] = 'العرض متاح حتى تاريخ التسليم المطلوب';
            }
        }

        // ── 9. Offer confirmed active (already filtered, but affirm) ──────────
        if ($offer->status === Offer::STATUS_ACTIVE) {
            $reasons['offer_active'] = 'العرض نشط ومتاح للطلب';
        }

        return [min(100.0, $score), $reasons];
    }
}
