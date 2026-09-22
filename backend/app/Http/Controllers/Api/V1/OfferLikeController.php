<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Offer;
use App\Models\OfferLike;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "This business is interested in this listing."
 *
 * A like is stored as a row, never as a counter column, so the number a buyer
 * reads is the number of rows that exist and the table is the audit trail of
 * who pressed it. One row per (offer, user, organization): the same person
 * acting for two companies produces two signals from two companies, which is
 * the honest reading, and re-liking cannot inflate a listing.
 *
 * What is NOT here: any star, score or "popular" verdict. Those are conclusions
 * about a seller, and this platform has never computed one.
 */
class OfferLikeController extends Controller
{
    public function store(Request $request, int $id): JsonResponse
    {
        $organizationId = TenantContext::id();

        if (!$organizationId) {
            return ApiResponse::error(
                'Active organization required',
                'TENANT_REQUIRED',
                Response::HTTP_FORBIDDEN
            );
        }

        $offer = Offer::find($id);

        // Only a listing a buyer could actually act on may be liked. A draft,
        // a withdrawn offer or one whose stock is gone is not a commercial
        // opportunity, and interest recorded against it would mislead the seller.
        if (!$offer || $offer->status !== Offer::STATUS_ACTIVE) {
            return ApiResponse::error(
                'العرض غير متاح للاهتمام',
                'OFFER_NOT_LIKEABLE',
                Response::HTTP_NOT_FOUND
            );
        }

        $like = OfferLike::firstOrCreate([
            'offer_id'        => $offer->id,
            'user_id'         => $request->user()->id,
            'organization_id' => $organizationId,
        ]);

        return ApiResponse::success([
            'liked'       => true,
            'likes_count' => $this->countFor($offer->id),
            'created'     => $like->wasRecentlyCreated,
        ], 'تم تسجيل الاهتمام', Response::HTTP_CREATED);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $offer = Offer::find($id);

        if (!$offer) {
            return ApiResponse::error('العرض غير موجود', 'NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        // Deleting by (offer, user, organization) rather than by a like id:
        // a caller who guesses another business's row id still only removes
        // their own.
        $removed = OfferLike::query()
            ->where('offer_id', $offer->id)
            ->where('user_id', $request->user()->id)
            ->where('organization_id', TenantContext::id())
            ->delete();

        return ApiResponse::success([
            'liked'       => false,
            'likes_count' => $this->countFor($offer->id),
            'removed'     => $removed > 0,
        ], 'أُلغي تسجيل الاهتمام');
    }

    /**
     * Which listings this account already marked, for the tenant in context.
     *
     * The public offer listing is deliberately anonymous — it answers the same
     * way whether or not a token was sent — so a client loads this set once and
     * fills its own buttons in from here.
     */
    public function mine(Request $request): JsonResponse
    {
        $ids = OfferLike::query()
            ->where('user_id', $request->user()->id)
            ->where('organization_id', TenantContext::id())
            ->orderByDesc('id')
            ->pluck('offer_id');

        return ApiResponse::success(['offer_ids' => $ids], 'قائمة العروض المهتم بها');
    }

    private function countFor(int $offerId): int
    {
        return OfferLike::query()->where('offer_id', $offerId)->count();
    }
}
