<?php

namespace App\Services;

use App\Models\Offer;
use Illuminate\Support\Collection;

/**
 * Geo Discovery Service
 *
 * Finds active marketplace offers within a radius from given coordinates
 * using the Haversine formula via a raw MySQL sub-query.
 *
 * Supports rich filters compatible with the "Nearby Offers" marketplace page:
 *   - radius_km        : search radius (default 50 km, max configurable)
 *   - category_id      : filter by category
 *   - opportunity_reason: filter by offer reason (SURPLUS_STOCK, etc.)
 *   - min_price        : minimum unit price
 *   - max_price        : maximum unit price
 *   - min_quantity     : minimum available quantity
 *   - max_moq          : only offers whose MOQ ≤ this value
 *   - currency_code    : filter by currency
 *   - delivery_terms   : BUYER_PICKUP | SELLER_DELIVERY | BOTH
 *   - sort_by          : distance | price | freshness (default: distance)
 *   - per_page         : result limit (default 20, max 50)
 */
class GeoDiscoveryService
{
    /**
     * Earth radius in kilometres for Haversine formula.
     */
    private const EARTH_RADIUS_KM = 6371;

    /**
     * Finds active offers within a radius using Haversine formula.
     *
     * @param  float       $latitude         Buyer's latitude
     * @param  float       $longitude        Buyer's longitude
     * @param  float       $radiusKm         Search radius in km
     * @param  array<string, mixed> $filters Optional filters (see class docblock)
     * @return array<int, mixed>             Ordered array of offers with distance_km
     */
    public function findOffersNearby(
        float $latitude,
        float $longitude,
        float $radiusKm = 50.0,
        array $filters  = []
    ): array {
        $haversine = sprintf(
            '(%d * acos(GREATEST(-1, LEAST(1, cos(radians(?)) * cos(radians(warehouses.latitude)) * cos(radians(warehouses.longitude) - radians(?)) + sin(radians(?)) * sin(radians(warehouses.latitude))))))',
            self::EARTH_RADIUS_KM
        );

        $query = Offer::query()
            ->join('warehouses', 'offers.warehouse_id', '=', 'warehouses.id')
            ->select(
                'offers.*',
                'warehouses.name as warehouse_name',
                'warehouses.city as warehouse_city',
                'warehouses.district as warehouse_district',
                'warehouses.latitude',
                'warehouses.longitude'
            )
            ->selectRaw("{$haversine} AS distance_km", [$latitude, $longitude, $latitude])
            ->where('offers.status', Offer::STATUS_ACTIVE)
            ->where('offers.available_quantity', '>', 0)
            ->whereNull('offers.deleted_at')
            ->having('distance_km', '<=', $radiusKm);

        // ── Category filter ───────────────────────────────────────────────────
        if (!empty($filters['category_id'])) {
            $query->where('offers.category_id', (int) $filters['category_id']);
        }

        // ── Opportunity reason filter ─────────────────────────────────────────
        if (!empty($filters['opportunity_reason'])) {
            $query->where('offers.opportunity_reason', $filters['opportunity_reason']);
        }

        // ── Price range filters ───────────────────────────────────────────────
        if (isset($filters['min_price']) && is_numeric($filters['min_price'])) {
            $query->where('offers.unit_price', '>=', (float) $filters['min_price']);
        }

        if (isset($filters['max_price']) && is_numeric($filters['max_price'])) {
            $query->where('offers.unit_price', '<=', (float) $filters['max_price']);
        }

        // ── Minimum available quantity ────────────────────────────────────────
        if (isset($filters['min_quantity']) && is_numeric($filters['min_quantity'])) {
            $query->where('offers.available_quantity', '>=', (float) $filters['min_quantity']);
        }

        // ── MOQ filter: only offers where MOQ ≤ buyer's specified quantity ───
        if (isset($filters['max_moq']) && is_numeric($filters['max_moq'])) {
            $query->where('offers.min_order_quantity', '<=', (float) $filters['max_moq']);
        }

        // ── Currency filter ───────────────────────────────────────────────────
        if (!empty($filters['currency_code'])) {
            $query->where('offers.currency_code', strtoupper($filters['currency_code']));
        }

        // ── Fulfillment / delivery terms ──────────────────────────────────────
        if (!empty($filters['delivery_terms'])) {
            $terms = strtoupper($filters['delivery_terms']);
            if (in_array($terms, ['BUYER_PICKUP', 'SELLER_DELIVERY', 'BOTH'], true)) {
                $query->where(function ($q) use ($terms) {
                    $q->where('offers.delivery_terms', $terms)
                      ->orWhere('offers.delivery_terms', 'BOTH');
                });
            }
        }

        // ── Expiry: skip already-expired offers ───────────────────────────────
        $query->where(function ($q) {
            $q->whereNull('offers.expires_at')
              ->orWhere('offers.expires_at', '>', now());
        });

        // ── Sorting ───────────────────────────────────────────────────────────
        $sortBy = $filters['sort_by'] ?? 'distance';
        match ($sortBy) {
            'price'     => $query->orderBy('offers.unit_price', 'asc'),
            'freshness' => $query->orderBy('offers.published_at', 'desc'),
            default     => $query->orderBy('distance_km', 'asc'),
        };

        // ── Pagination limit ──────────────────────────────────────────────────
        $perPage = min(50, max(1, (int) ($filters['per_page'] ?? 20)));

        return $query
            ->with(['variant.product', 'seller', 'category'])
            ->limit($perPage)
            ->get()
            ->map(function ($offer) {
                $arr                 = $offer->toArray();
                $arr['distance_km']  = round((float) $offer->distance_km, 2);
                return $arr;
            })
            ->toArray();
    }

    /**
     * Returns offers within standard radius bands (5/10/25/50 km).
     * Useful for the "Nearest First" discovery UI tabs.
     */
    public function findOffersByBands(float $latitude, float $longitude, array $filters = []): array
    {
        $bands = [5, 10, 25, 50];

        $result = [];
        foreach ($bands as $km) {
            $result["within_{$km}km"] = $this->findOffersNearby($latitude, $longitude, (float) $km, array_merge($filters, ['per_page' => 5]));
        }

        return $result;
    }
}
