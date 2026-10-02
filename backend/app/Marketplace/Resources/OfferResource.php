<?php

namespace App\Marketplace\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public/commercial shape of one offer.
 *
 * Why a resource exists at all: the marketplace endpoints used to return raw
 * `Offer` models with nested relations, while the web and mobile clients read
 * flat fields (`product_name_ar`, `warehouse_city`, `seller_name_ar`) that the
 * API never sent. The listing cards rendered blank names and blank suppliers,
 * and no test caught it because no test asserted the response shape.
 *
 * Two rules hold here:
 *   1. Statuses and reasons are passed through exactly as the domain stores
 *      them (UPPERCASE). The client normalises, the server does not translate
 *      — otherwise two places own the same vocabulary.
 *   2. Nothing is invented. A seller without a rating is `null`, not 5.0; a
 *      warehouse without coordinates has `null` coordinates, not a default
 *      city; an offer with no brand has no brand name.
 */
class OfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        // Relation access is optional-chained rather than gated on
        // `relationLoaded()`: the controllers eager-load these for lists, but
        // a caller that forgot should return the real name, not a blank.
        $variant  = $this->variant;
        $product  = $variant?->product;
        $brand    = $product?->brand;
        // The category is the product's. `offers` no longer keeps a copy of it,
        // so this reads one step further along the chain every listing was
        // already walking — and the answer now follows a reclassification the
        // moment the platform makes one.
        $category = $product?->category;
        $seller   = $this->seller;
        $wareh    = $this->warehouse;

        // A seller may publish a listing without advertising who published it.
        // This is the one place that decides it, so every read of one offer —
        // the list, the detail page, the nearby search, the seller's own shelf —
        // answers the same way instead of leaking through whichever one a
        // screen happened to use. See `sellerIdentityShownTo()`.
        $shows_identity = $this->sellerIdentityShownTo($request);

        return [
            'id'                     => $this->id,
            'seller_organization_id' => $this->seller_organization_id,
            'warehouse_id'           => $this->warehouse_id,
            'product_variant_id'     => $this->product_variant_id,
            // Still answered, and still under the same name — the answer is
            // simply read from the product instead of from a copy of it, so
            // clients that filter or group on it see no change.
            'category_id'            => $product?->category_id,

            'title_ar'               => $this->title_ar,
            'title_en'               => $this->title_en,
            'description_ar'         => $this->description_ar,
            'description_en'         => $this->description_en,

            'opportunity_reason'      => $this->opportunity_reason,
            'opportunity_reason_notes' => $this->opportunity_reason_notes,
            'status'                 => $this->status,

            // Quantities stay separate facts: what is on the shelf, what is
            // held by buyers, and what has already gone through.
            'total_quantity'         => $this->total_quantity,
            'available_quantity'     => $this->available_quantity,
            'reserved_quantity'      => $this->reserved_quantity,
            'sold_quantity'          => $this->sold_quantity,
            'min_order_quantity'     => $this->min_order_quantity,
            'max_order_quantity'     => $this->max_order_quantity,
            'unit'                   => $this->unit,

            'unit_price'             => $this->unit_price,
            'currency_code'          => $this->currency_code,

            'production_date'        => $this->production_date,
            'expiry_date'            => $this->expiry_date,
            'storage_condition'      => $this->storage_condition,
            'delivery_terms'         => $this->delivery_terms,
            'inspection_window_hours' => $this->inspection_window_hours,
            'published_at'           => $this->published_at,
            'expires_at'             => $this->expires_at,

            // The date, read out as a fact rather than left to be inferred from
            // the label.
            //
            // Nothing in this deployment rewrites `status` to EXPIRED when
            // `expires_at` passes — no scheduler, and the only writer of that
            // label is the reservation service, reaching for it after a hold has
            // already failed. So a listing whose validity ended can carry
            // `status: ACTIVE` forever, and a client that rendered the label
            // would draw a live «اطلب الشراء» button on a price that no longer
            // exists. The public lists hide such rows; this field is what the
            // detail page, a saved like, and a cached offline card use to say
            // «انتهى» instead.
            'is_expired'             => $this->isExpired(),

            // The platform's pin, not the seller's. `featured_at` is the decision
            // and its place in the carousel at once, so it is sent as a timestamp
            // rather than as a boolean: a client that only wants "is this one of
            // the promoted ones" asks whether it is non-null, and a client that
            // draws the strip can order by what it already has instead of
            // trusting the sequence the page arrived in.
            //
            // `featured_by` — the staff account that pinned it — is withheld from
            // everyone who cannot pin. A buyer needs to know the platform stands
            // behind the placement, not which employee clicked, and an internal
            // name on a public response is data nobody asked for.
            'featured_at'            => $this->featured_at,
            'featured_by'            => $this->when(
                $this->featurePlacementVisibleTo($request),
                fn () => $this->featuredBy?->only(['id', 'name'])
            ),

            'product_id'             => $product?->id,
            'product_name_ar'        => $product?->name_ar,
            'product_name_en'        => $product?->name_en,
            'variant_sku'            => $variant?->sku,
            'variant_name_ar'        => $variant?->name_ar,
            'variant_name_en'        => $variant?->name_en,
            'variant_image'          => $variant?->image_url,
            'weight_kg'              => $variant?->weight_kg,
            'packaging_spec'         => $variant?->packaging_spec,
            'brand_id'               => $brand?->id,
            'brand_name_ar'          => $brand?->name_ar,
            'brand_name_en'          => $brand?->name_en,
            'product_image'          => $product?->image_url,

            // The photographs a seller actually uploaded, as opposed to the
            // legacy single `image_url` above, which nothing in the application
            // writes. `product_images` is present only where the controller
            // loaded it; a caller that did not is not handed a relation it never
            // asked for, and a file missing from disk yields a null url rather
            // than a URL that 404s.
            'product_cover_image'    => $product?->images->firstWhere('is_cover', true)?->url()
                ?? $product?->images->first()?->url(),
            'product_images'         => $this->when(
                $product && $product->relationLoaded('images'),
                fn () => $product->images->map(fn ($image) => [
                    'id'         => $image->id,
                    'url'        => $image->url(),
                    'is_cover'   => $image->is_cover,
                    'sort_order' => $image->sort_order,
                    'width'      => $image->width,
                    'height'     => $image->height,
                ])->all()
            ),

            'category_name_ar'       => $category?->name_ar,
            'category_name_en'       => $category?->name_en,

            // The name of a warehouse and its address line are usually the
            // company's name and its door. The city and the coordinates stay:
            // a buyer choosing between two listings needs to know where the
            // goods physically are, and hiding that would break the discovery
            // path the platform exists for.
            'warehouse_name'         => $shows_identity ? $wareh?->name : null,
            'warehouse_city'         => $wareh?->city,
            'warehouse_district'     => $wareh?->district,
            'warehouse_address'      => $shows_identity ? $wareh?->address_line : null,
            'warehouse_lat'          => $wareh?->latitude,
            'warehouse_lon'          => $wareh?->longitude,

            // Status and rating are answers about behaviour on this platform,
            // not about who the company is, so they survive the mask: a buyer
            // comparing an anonymous listing still needs to know whether the
            // business behind it is one this platform has reviewed.
            'seller_name_ar'         => $shows_identity ? $seller?->name_ar : null,
            'seller_name_en'         => $shows_identity ? $seller?->name_en : null,
            'seller_status'          => $seller?->status,
            'seller_cr'              => $shows_identity ? $seller?->commercial_registration_number : null,
            'seller_vat'             => $shows_identity ? $seller?->vat_number : null,
            // null until completed deals produce a real score.
            'seller_rating'          => $seller?->rating,
            // The record itself, sent truthfully even to the owner whose read is
            // unmasked. Without it a blank name is ambiguous — withheld by the
            // seller, or simply never recorded — and the client would have to
            // guess which sentence to print.
            'show_seller_identity'   => (bool) ($this->getAttribute('show_seller_identity') ?? true),

            // Community signals, present only where the controller counted them
            // (`withCount`). Whether the CALLING account liked this listing is
            // deliberately not here: this endpoint answers the same way signed-in
            // or not, and `GET /marketplace/liked-offers` is the authenticated
            // door to that fact.
            'likes_count'            => $this->whenCounted('likes'),
            'comments_count'         => $this->whenCounted('published_comments_count'),

            // Only the geo endpoint computes distance.
            'distance_km'            => $this->when(isset($this->distance_km), fn () => round((float) $this->distance_km, 2)),

            'items'                  => $this->when(
                $this->relationLoaded('items'),
                fn () => $this->items->map(fn ($item) => [
                    'id'                 => $item->id,
                    'product_id'         => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'quantity'           => $item->quantity,
                    'unit_price'         => $item->unit_price,
                    'currency_code'      => $item->currency_code,
                ])->all()
            ),
        ];
    }

    /**
     * Whether this caller is shown who the seller is.
     *
     * Three answers, in this order:
     *
     *   * The listing opts in (the default, and the value every offer carried
     *     before the column existed) — nothing is hidden from anyone.
     *   * The caller belongs to the selling organization. Its own shelf, its own
     *     detail page and its own edit form must keep reading real names; a
     *     seller hiding their name from the market is not hiding it from
     *     themselves.
     *   * The caller holds `offers.moderate`. A reviewer who cannot see whose
     *     listing is in front of them cannot review it, and the moderation queue
     *     would otherwise be asked to decide an anonymised claim.
     *
     * Everyone else — including a signed-in buyer with no relation to the
     * seller, and an anonymous visitor — reads the masked shape.
     *
     * The marketplace routes are public, so neither the auth nor the tenant
     * middleware has necessarily run: the bearer is resolved on `sanctum`
     * explicitly, the same way `OfferController::show()` does.
     */
    private function sellerIdentityShownTo($request): bool
    {
        if ($this->getAttribute('show_seller_identity') ?? true) {
            return true;
        }

        $viewer = $request->user('sanctum');
        if (!$viewer) {
            return false;
        }

        if ($viewer->can('offers.moderate')) {
            return true;
        }

        return $viewer->belongsToOrganization((int) $this->seller_organization_id);
    }

    /**
     * Whether this caller is told *who* pinned the listing.
     *
     * The pin itself is public and stays in every response — hiding the fact that
     * the platform promoted a listing would make the carousel unexplainable.
     * The staff account behind it is the platform's internal record, and the
     * people who need it are the ones who can place or remove it.
     */
    private function featurePlacementVisibleTo($request): bool
    {
        if ($this->featured_at === null) {
            return false;
        }

        $viewer = $request->user('sanctum');

        return $viewer ? $viewer->can('offers.feature') : false;
    }
}
