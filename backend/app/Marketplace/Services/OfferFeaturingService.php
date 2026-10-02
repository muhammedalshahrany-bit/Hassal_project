<?php

namespace App\Marketplace\Services;

use App\Core\Exceptions\ApiRefused;
use App\Identity\Models\User;
use App\Marketplace\Models\Offer;
use Symfony\Component\HttpFoundation\Response;

/**
 * The promoted strip on the market front: what may be pinned, and by whose hand.
 *
 * Pinning is a platform act, not a listing edit, so it is not in
 * `OfferWritingService` where the seller's own words and prices are decided —
 * the two columns behind it are outside `$fillable` precisely so a seller's
 * `PUT` cannot carry a pin. Which listings the strip holds, and in what order,
 * is `OfferQueries::featuredOffers()`; this class only changes the pin.
 */
class OfferFeaturingService
{
    /**
     * Pin or un-pin one listing.
     *
     * Only a live listing may be pinned, and the reason is not tidiness. A pin
     * on a draft would sit in the table doing nothing until the draft happened
     * to be published, at which point the market would promote a listing nobody
     * approved in that moment — a decision made twice, once remembered.
     * Refusing here means the admin's click and the buyer's screen are about
     * the same listing.
     *
     * Un-pinning asks nothing of the state: removing a pin from a listing that
     * has since sold out is exactly what the platform wants to be able to do.
     *
     * @throws ApiRefused
     */
    public function feature(int $offerId, bool $featured, User $actor): Offer
    {
        $offer = Offer::find($offerId);

        if (!$offer) {
            throw new ApiRefused('العرض غير موجود', 'NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        if (!$featured) {
            $offer->forceFill(['featured_at' => null, 'featured_by' => null])->save();

            return $offer;
        }

        if ($offer->status !== Offer::STATUS_ACTIVE) {
            throw new ApiRefused(
                'لا يمكن تمييز عرض غير منشور وجاهز للبيع',
                'OFFER_NOT_FEATUREABLE',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['status' => $offer->status]
            );
        }

        // `forceFill`, not `update`: these two columns are deliberately absent
        // from `$fillable`, so a seller's own `PUT /marketplace/offers/{id}`
        // cannot carry a pin in its payload.
        //
        // The stamp is always "now", including on a listing that was already
        // pinned. The order of the strip *is* the recency of the decision, so
        // re-pinning has to move the listing to the front — a pin that
        // preserved its original timestamp would make "put this back on top" a
        // click that visibly did nothing.
        $offer->forceFill([
            'featured_at' => now(),
            'featured_by' => $actor->id,
        ])->save();

        return $offer;
    }
}
