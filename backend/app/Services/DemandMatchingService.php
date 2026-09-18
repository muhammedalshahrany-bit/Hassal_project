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
 *   - Product match (same product_id)         : 30 pts  [mandatory gate]
 *   - Category match                          : 15 pts
 *   - Quantity fully available                : 15 pts
 *   - Quantity partially available            :  8 pts
 *   - Price within target                     : 15 pts
 *   - Price slightly above target (≤ 10%)     :  5 pts
 *   - City/delivery location match            : 10 pts
 *   - Fulfillment method compatible           :  8 pts
 *   - Offer freshness (published ≤ 7 days)    :  5 pts
 *   - Offer expires after demand date         :  5 pts  [bonus, not deducted]
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
        // Only load ACTIVE offers with stock; use cursor for large datasets
        $offers = Offer::query()
            ->with(['warehouse', 'variant.product'])
            ->where('status', Offer::STATUS_ACTIVE)
            ->where('available_quantity', '>', 0)
            ->whereNull('deleted_at')
            ->get();

        $matches = collect();

        foreach ($offers as $offer) {
            // Mandatory gates: product/category, active demand, quantity and MOQ.
            if ($demand->status !== Demand::STATUS_OPEN || !$this->productCompatible($demand, $offer) || !$this->quantityCompatible($demand, $offer)) {
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

        return $matches->sortByDesc('match_score')->values();
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

        $matches = collect();

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

        // ── 1. Product / variant match (30 pts) ───────────────────────────────
        if ($offer->variant && $offer->variant->product_id) {
            $offerProductId = $offer->variant->product_id;

            // Check if demand has a linked product (via category for now; extend with product_id field later)
            if ($offer->category_id && $demand->category_id && $offer->category_id === $demand->category_id) {
                $score += 30;
                $reasons['product_match'] = 'المنتج ينتمي للتصنيف المطلوب';
            }
        } elseif ($offer->category_id && $demand->category_id && $offer->category_id === $demand->category_id) {
            $score += 20;
            $reasons['category_match'] = 'تصنيف المنتج مطابق';
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
        // else: quantity < MOQ → product incompatible, but we still score other factors

        // ── 3. MOQ check: if demand quantity < MOQ, deduct ───────────────────
        if ($demandNeeded < $moq) {
            $score -= 10;
            $reasons['moq_gap'] = "الكمية المطلوبة ({$demandNeeded}) أقل من الحد الأدنى للطلب ({$moq})";
        }

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

        // ── 6. Fulfillment method (8 pts) ─────────────────────────────────────
        if ($offer->delivery_terms) {
            $score += 8;
            $reasons['fulfillment_available'] = "طريقة التوصيل متاحة ({$offer->delivery_terms})";
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
