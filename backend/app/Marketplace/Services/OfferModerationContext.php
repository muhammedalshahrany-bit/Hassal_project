<?php

namespace App\Marketplace\Services;

use App\Trading\Models\Deal;
use App\Marketplace\Models\Offer;
use App\Core\Support\WaitingTime;

/**
 * The reviewer's dossier: what a moderation decision needs that is not on the
 * offer itself.
 *
 * A reviewer deciding on a listing is deciding on the business behind it, so
 * each row carries that business — the legal numbers it filed, the papers filed
 * against them and what staff concluded, its record on the platform so far, and
 * the photographs attached to the goods. Before this, the queue showed a title
 * and a price, and the reviewer had to open three other screens — if they knew
 * to — to see anything else.
 */
class OfferModerationContext
{
    /**
     * Memo for the queue's seller-history counts.
     *
     * A service instance is built per request, so this is request-scoped
     * caching — a page of listings from one seller counts that seller once —
     * and nothing survives the response.
     */
    private array $historyCache = [];

    /**
     * @return array<string, mixed>
     */
    public function forOffer(Offer $offer): array
    {
        $seller = $offer->seller;
        $documents = $seller?->documents ?? collect();

        return [
            'seller_legal_record' => $seller ? [
                'organization_id'  => $seller->id,
                'name_ar'          => $seller->name_ar,
                'type'             => $seller->type,
                'country_code'     => $seller->country_code,
                'city'             => $seller->city,
                'platform_status'  => $seller->status,
                'registration_no'  => $seller->commercial_registration_number,
                'tax_no'           => $seller->vat_number,
                // When this platform's own staff accepted the business's papers.
                // No government register is consulted by this codebase, so this
                // field is a record of an internal decision and nothing more.
                'reviewed_at'      => $seller->kyc_verified_at,
                'website'          => $seller->website,
                'phone'            => $seller->phone,
                'member_count'     => $seller->members?->count() ?? 0,
            ] : null,

            'documents' => $documents->map(fn ($document) => [
                'id'            => $document->id,
                'type'          => $document->type,
                'status'        => $document->status,
                'reference_number' => $document->reference_number,
                'original_name' => $document->original_name,
                'url'           => $document->url(),
                'uploaded_at'   => $document->created_at,
                'reviewed_at'   => $document->reviewed_at,
                'reviewed_by'   => $document->reviewer?->name,
                'review_reason' => $document->review_reason,
            ])->values()->all(),

            'seller_history' => $seller ? $this->sellerHistory((int) $seller->id) : null,

            // The offers table has no `submitted_at`, so this is the row's last
            // write — an upper bound on how long it has waited, and labelled as
            // one rather than presented as a submission timestamp.
            'waiting_since'     => $offer->updated_at,
            'waiting_hours'     => WaitingTime::hoursSince($offer->updated_at),
            'image_count'       => $offer->variant?->product?->images?->count() ?? 0,
            'warehouse'         => $offer->warehouse ? [
                'id'    => $offer->warehouse->id,
                'name'  => $offer->warehouse->name,
                'city'  => $offer->warehouse->city,
                'code'  => $offer->warehouse->code,
                'lat'   => $offer->warehouse->latitude,
                'lon'   => $offer->warehouse->longitude,
            ] : null,
        ];
    }

    /**
     * The business's record on the platform, counted from the rows that exist.
     *
     * Cached per request and per organization because a page of listings from
     * the same seller must not run the same four counts once per row.
     *
     * @return array<string, int>
     */
    private function sellerHistory(int $organizationId): array
    {
        if (isset($this->historyCache[$organizationId])) {
            return $this->historyCache[$organizationId];
        }

        return $this->historyCache[$organizationId] = [
            'offers_total'   => Offer::query()->where('seller_organization_id', $organizationId)->count(),
            'offers_active'  => Offer::query()->where('seller_organization_id', $organizationId)->where('status', Offer::STATUS_ACTIVE)->count(),
            'offers_expired' => Offer::query()->where('seller_organization_id', $organizationId)->where('status', Offer::STATUS_EXPIRED)->count(),
            'deals_total'    => Deal::query()->where('seller_organization_id', $organizationId)->count(),
            'deals_completed' => Deal::query()->where('seller_organization_id', $organizationId)->where('status', Deal::STATUS_COMPLETED)->count(),
            'deals_disputed' => Deal::query()->where('seller_organization_id', $organizationId)->where('status', Deal::STATUS_DISPUTED)->count(),
        ];
    }
}
