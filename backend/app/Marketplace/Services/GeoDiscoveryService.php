<?php

namespace App\Marketplace\Services;

use App\Marketplace\Resources\OfferResource;
use App\Marketplace\Models\Offer;
use App\Core\Support\Geo;
use App\Platform\Services\SystemSettingsService;

/**
 * Geo Discovery Service
 *
 * Finds active marketplace offers within a radius from given coordinates
 * using the Haversine formula via a raw MySQL sub-query.
 *
 * Supports rich filters compatible with the "Nearby Offers" marketplace page:
 *   - radius_km        : search radius, bounded by `resolveRadius()` below
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
     * The request keys that mean something to a discovery read.
     *
     * Anything else a caller sends is not a filter, and is dropped rather than
     * reaching a `where` clause that was never written for it — see `search()`.
     */
    private const FILTER_KEYS = [
        'category_id',
        'opportunity_reason',
        'min_price',
        'max_price',
        'min_quantity',
        'max_moq',
        'currency_code',
        'delivery_terms',
        'sort_by',
        'per_page',
    ];

    public function __construct(
        protected SystemSettingsService $settings,
    ) {}

    /**
     * The radius a search actually runs at.
     *
     * Whose number this is, and what happens to a caller who asks for more:
     * the default comes from the platform's matching setting rather than a
     * client-side constant, so an operator who widens the market's reach changes
     * every map at once; and a request for ten thousand kilometres is answered
     * with the deployment's ceiling instead of an error, because the caller
     * asked for "everything near me" and a refusal would tell them nothing
     * about what the market can show.
     */
    public function resolveRadius(?float $requestedKm = null): float
    {
        $radius = $requestedKm ?? (float) $this->settings->get('matching.geo_radius_km', 100);
        $ceiling = (float) $this->settings->get('geo.max_radius_km', 500);

        return min($radius, $ceiling);
    }

    /**
     * A nearby-offers search, asked in the shape a validated request arrives in.
     *
     * This is the discovery door an endpoint uses, and it owns the two decisions
     * that were being made at the endpoint: what radius the search actually runs
     * at (`resolveRadius()`), and which half of the request is a filter at all
     * (`FILTER_KEYS`). A caller asking for ten thousand kilometres and a caller
     * inventing a key are both answered by the same rules here, whichever door
     * they came through.
     *
     * @param  array<string, mixed>  $validated
     * @return array{offers: array<int, mixed>, search_center: array{latitude: float, longitude: float}, radius_km: float, total_found: int}
     */
    public function search(array $validated): array
    {
        $latitude  = (float) $validated['latitude'];
        $longitude = (float) $validated['longitude'];

        $radius = $this->resolveRadius(
            isset($validated['radius_km']) ? (float) $validated['radius_km'] : null
        );

        $filters = array_filter(
            array_intersect_key($validated, array_flip(self::FILTER_KEYS)),
            fn ($value) => $value !== null && $value !== ''
        );

        $offers = $this->findOffersNearby($latitude, $longitude, $radius, $filters);

        return [
            'offers'        => $offers,
            'search_center' => ['latitude' => $latitude, 'longitude' => $longitude],
            'radius_km'     => $radius,
            'total_found'   => count($offers),
        ];
    }
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
        // The formula is `App\Core\Support\Geo`'s, shared with the warehouse board:
        // two implementations of one distance is two numbers that will not agree
        // in front of a buyer comparing a listing against the shed it sits in.
        $haversine = Geo::distanceSql('warehouses.latitude', 'warehouses.longitude');

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
            ->tradable()
            ->where('offers.status', Offer::STATUS_ACTIVE)
            ->where('offers.available_quantity', '>', 0)
            ->whereNull('offers.deleted_at')
            // Rank only offers with a real, live location. Coordinates are nullable
            // by design; an unknown warehouse must not be scored at a fabricated
            // distance, and soft-deleted warehouses must never surface offers.
            ->whereNull('warehouses.deleted_at')
            ->whereNotNull('warehouses.latitude')
            ->whereNotNull('warehouses.longitude')
            ->having('distance_km', '<=', $radiusKm);

        // ── Category filter ───────────────────────────────────────────────────
        // The category belongs to the product, not to the listing: the offer
        // reaches it through its variant. Same filter to the caller, one fewer
        // copy of the answer to keep in step.
        if (!empty($filters['category_id'])) {
            $query->whereHas(
                'variant.product',
                fn ($q) => $q->where('category_id', (int) $filters['category_id'])
            );
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
            if (in_array($terms, Offer::DELIVERY_TERMS, true)) {
                $query->where(function ($q) use ($terms) {
                    $q->where('offers.delivery_terms', $terms)
                      ->orWhere('offers.delivery_terms', Offer::DELIVERY_BOTH);
                });
            }
        }

        // ── Expiry: skip already-expired offers ───────────────────────────────
        // The offer's own ending, not its label: nothing in this deployment
        // rewrites `status` to EXPIRED when `expires_at` passes — no scheduler
        // does it — so the date is the only fact that records a listing having
        // ended. `OfferController::index` bounds the same date in its opening
        // chain; this query keeps it down here among the other filters, which is
        // how it can be mistaken for an omission by the next reader.
        // `NearbyOffersTest` is what stops that mistake from becoming a deletion.
        $query->where(function ($q) {
            $q->whereNull('offers.expires_at')
              ->orWhere('offers.expires_at', '>', now());
        });

        // ── Sorting ───────────────────────────────────────────────────────────
        // Distance is the default because this is the "near me" path; the same
        // intent names the plain listing endpoint accepts work here too.
        $sortBy = $filters['sort_by'] ?? 'distance';
        match ($sortBy) {
            'price', 'cheapest'   => $query->orderBy('offers.unit_price', 'asc'),
            'freshness', 'newest' => $query->orderBy('offers.published_at', 'desc'),
            'expiring'            => $query->orderBy('offers.expires_at', 'asc'),
            default               => $query->orderBy('distance_km', 'asc'),
        };

        // ── Pagination limit ──────────────────────────────────────────────────
        $perPage = min(50, max(1, (int) ($filters['per_page'] ?? 20)));

        // The list is presented through the same resource as every other offer
        // response, so the client reads one shape for offers everywhere.
        return $query
            ->with(['variant.product.brand', 'variant.product.category', 'warehouse', 'seller'])
            ->limit($perPage)
            ->get()
            ->map(fn (Offer $offer) => OfferResource::make($offer)->resolve())
            ->all();
    }
}
