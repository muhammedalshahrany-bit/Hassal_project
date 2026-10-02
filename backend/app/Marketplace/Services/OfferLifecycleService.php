<?php

namespace App\Marketplace\Services;

use App\Core\Exceptions\ApiRefused;
use App\Marketplace\Models\Offer;
use App\Marketplace\Support\OfferNotices;
use App\Notifications\Services\NotificationManager;
use App\Platform\Services\SystemSettingsService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The lifecycle of a listing after it exists: submit, transition, discard,
 * and the platform's moderation decision on one.
 *
 * Each method is a state change plus its consequences — matching, the market
 * announcement, the seller's notice — kept together so the three doors that
 * publish a listing (a direct store, a review-less submit, a moderator's
 * approval) cannot disagree about what publishing means.
 */
class OfferLifecycleService
{
    /**
     * The transitions a seller may drive themselves.
     *
     * Deliberately absent: `ACTIVE` from `PENDING_REVIEW`. A seller cannot
     * approve their own moderation queue — that decision is `review()` below.
     */
    private const SELLER_TRANSITIONS = [
        Offer::STATUS_DRAFT          => [Offer::STATUS_CANCELLED],
        Offer::STATUS_PENDING_REVIEW => [Offer::STATUS_CANCELLED],
        Offer::STATUS_ACTIVE         => [Offer::STATUS_PAUSED, Offer::STATUS_CANCELLED],
        Offer::STATUS_PAUSED         => [Offer::STATUS_ACTIVE, Offer::STATUS_CANCELLED],
        // Sold out is the shelf telling the buyer the truth. The seller can
        // still withdraw it, and restocking is a new offer, not a status.
        Offer::STATUS_SOLD_OUT       => [Offer::STATUS_CANCELLED],
        Offer::STATUS_EXPIRED        => [],
        Offer::STATUS_CANCELLED      => [],
    ];

    public function __construct(
        protected SystemSettingsService $settingsService,
        protected DemandMatchingService $matchingService,
        protected NotificationManager $notificationManager,
    ) {}

    /**
     * Put a draft in front of the platform (or straight on the shelf).
     *
     * This is the step that makes a returned offer recoverable. Moderation can
     * only work if RETURN sends the listing back to a state the seller can edit
     * and resubmit; without it, `review()` would be able to approve but never
     * to reject without destroying the seller's work.
     *
     * @return array{offer: Offer, requires_review: bool}
     *
     * @throws ApiRefused
     */
    public function submit(Offer $offer): array
    {
        if ($offer->status !== Offer::STATUS_DRAFT) {
            throw new ApiRefused(
                'المسودات فقط يمكن تقديمها للنشر',
                'OFFER_NOT_DRAFT',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ((float) $offer->available_quantity <= 0.0001) {
            throw new ApiRefused(
                'لا يمكن عرض كمية غير متاحة للبيع',
                'OFFER_HAS_NO_STOCK',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $requiresReview = (bool) $this->settingsService->get('offer.requires_review', false);

        $offer->forceFill([
            'status'       => $requiresReview ? Offer::STATUS_PENDING_REVIEW : Offer::STATUS_ACTIVE,
            'published_at' => $requiresReview ? $offer->published_at : ($offer->published_at ?? Carbon::now()),
        ])->save();

        if (!$requiresReview) {
            $this->announcePublication($offer);
        }

        return ['offer' => $offer, 'requires_review' => $requiresReview];
    }

    /**
     * Which states a listing may be put into by hand at all.
     *
     * The union of `SELLER_TRANSITIONS`, asked rather than retyped: the endpoint
     * that accepts a status and the machine that decides whether this offer may
     * move there are two halves of one rule, and a target added to the map while
     * the door still refused it would be a state nobody could reach.
     *
     * @return list<string>
     */
    public function sellerTransitionTargets(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::SELLER_TRANSITIONS))));
    }

    /**
     * The seller's own control over an offer they published.
     *
     * @throws ApiRefused
     */
    public function transition(Offer $offer, string $toStatus): Offer
    {
        $currentStatus = $offer->status;

        if (!in_array($toStatus, self::SELLER_TRANSITIONS[$currentStatus] ?? [], true)) {
            throw new ApiRefused(
                "لا يمكن تغيير حالة العرض من '{$currentStatus}' إلى '{$toStatus}'",
                'INVALID_OFFER_TRANSITION',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $offer->forceFill([
            'status' => $toStatus,
            // Restore published_at if reactivating
            'published_at' => ($toStatus === Offer::STATUS_ACTIVE && !$offer->published_at) ? Carbon::now() : $offer->published_at,
        ])->save();

        return $offer;
    }

    /**
     * Discard a draft.
     *
     * Only a DRAFT can be deleted, and only by its seller — ownership is the
     * controller's question, state is this one's. Once an offer has been seen by
     * the market it is history, not a scratch note: withdrawing a published
     * listing is CANCELLED through `transition()`, which keeps the row and the
     * audit trail that buyers and disputes may still refer to.
     *
     * @throws ApiRefused
     */
    public function discard(Offer $offer): void
    {
        if ($offer->status !== Offer::STATUS_DRAFT) {
            throw new ApiRefused(
                'المسودات فقط يمكن حذفها; اسحب العرض المنشور بدل حذفه',
                'OFFER_NOT_DELETABLE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $offer->delete();
    }

    /**
     * The platform's moderation decision on a submitted offer.
     *
     * Review is meaningless if the only way out of PENDING_REVIEW is for the
     * seller to publish themselves, so this is a separate action with a
     * separate gate: it approves, which publishes the offer, or returns it to
     * DRAFT with a reason the seller can act on. It never edits the offer's
     * commercial terms — the platform moderates, it does not sell.
     *
     * @throws ApiRefused
     */
    public function review(Offer $offer, string $decision, ?string $reason): Offer
    {
        if ($offer->status !== Offer::STATUS_PENDING_REVIEW) {
            throw new ApiRefused(
                "لا تُراجَع إلا قائمة بانتظار القرار؛ حالة هذا العرض الآن {$offer->status}.",
                'OFFER_NOT_AWAITING_REVIEW',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($decision === Offer::REVIEW_APPROVE) {
            $offer->forceFill([
                'status' => Offer::STATUS_ACTIVE,
                'published_at' => $offer->published_at ?? Carbon::now(),
            ])->save();

            // Publishing is the moment this offer becomes a commercial fact:
            // only now does it enter the matching engine.
            try {
                $this->matchingService->matchOffer($offer);
            } catch (Exception $e) {
                Log::error('Offer matching failed after approval', [
                    'offer_id' => $offer->id,
                    'exception' => $e,
                ]);
            }

            $this->notifySellerOfDecision($offer, null);

            return $offer;
        }

        $offer->forceFill([
            'status' => Offer::STATUS_DRAFT,
            'metadata' => array_merge($offer->metadata ?? [], [
                'returned_reason' => $reason,
                'returned_at' => Carbon::now()->toIso8601String(),
            ]),
        ])->save();

        $this->notifySellerOfDecision($offer, $reason);

        return $offer;
    }

    /**
     * A listing that just became a commercial fact: matching and the market.
     *
     * Both doors that publish directly (a store that needs no review, a submit
     * that needs no review) come through here, because the announcement that
     * lives in one of them is the announcement the other forgets.
     */
    public function announcePublication(Offer $offer): void
    {
        if ($offer->status === Offer::STATUS_ACTIVE) {
            try {
                $this->matchingService->matchOffer($offer);
            } catch (Exception $e) {
                Log::error('Offer matching failed after offer creation', [
                    'offer_id' => $offer->id,
                    'exception' => $e,
                ]);
            }
        }

        $this->announceToMarket($offer);
    }

    /**
     * Tell the market that a listing has just appeared on it.
     *
     * The seller's own accounts are excluded: they are the ones who pressed the
     * button, and a notice that repeats what a person just did is noise that
     * teaches them to ignore the ones that are not.
     *
     * Pausing and resuming a listing is deliberately *not* an announcement. A
     * shelf that came back is the same offer the market already saw, and telling
     * everybody about it twice is how a useful notice becomes a spam one.
     */
    private function announceToMarket(Offer $offer): void
    {
        if ($offer->status !== Offer::STATUS_ACTIVE) {
            return;
        }

        $offer->loadMissing(['seller', 'warehouse', 'variant.product']);

        $notice = OfferNotices::published($offer);

        $ownAccounts = $offer->seller
            ?->activeMemberships()
            ->pluck('user_id')
            ->all() ?? [];

        try {
            $this->notificationManager->notifyEveryone(
                $notice['title'],
                $notice['body'],
                $notice['data'],
                $ownAccounts
            );
        } catch (Exception $e) {
            // A listing that published is a commercial fact; a notice that could
            // not be written is not a reason to roll it back. The market itself
            // still shows it, and the failure is left in the log rather than
            // turned into an error the seller is shown for something they did
            // correctly.
            Log::error('Offer announcement failed', [
                'offer_id' => $offer->id,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Tell the business whose listing was decided on, either way.
     *
     * A moderation decision is the one thing in this flow a seller is waiting
     * for, and until now the only way to find it was to open the shelf and look.
     * The notice goes to the organization's active members rather than to
     * `created_by`: a listing is filed by a person but answered by a company,
     * and a returned offer still has to be fixed by whoever is at the desk.
     *
     * Sent after the write, never inside the transaction — a notice that
     * reaches a handset about a decision the database then rolled back is worse
     * than no notice, because it cannot be taken back.
     */
    private function notifySellerOfDecision(Offer $offer, ?string $reason): void
    {
        $seller = $offer->seller;

        if ($seller === null) {
            return;
        }

        $title = $reason === null
            ? 'تمت الموافقة على عرضك ونشره'
            : 'عرضك بحاجة إلى تعديل';

        $body = $reason === null
            ? "العرض «{$offer->title_ar}» منشور الآن في السوق."
            : "العرض «{$offer->title_ar}» أُعيد إليك. السبب: {$reason}";

        $this->notificationManager->notifyOrganization(
            $seller,
            $title,
            $body,
            [
                'type' => 'OFFER',
                'offer_id' => $offer->id,
                'organization_id' => $seller->id,
                'decision' => $reason === null ? Offer::REVIEW_APPROVE : Offer::REVIEW_RETURN,
            ]
        );
    }
}
