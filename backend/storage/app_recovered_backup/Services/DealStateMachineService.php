<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\DealHandover;
use App\Models\DealStatusHistory;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\User;
use App\Services\Notifications\NotificationManager;
use App\Support\DealNotices;
use App\Support\HandoverRefusal;
use App\Support\LockOrder;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;

class DealStateMachineService
{
    public function __construct(
        protected DoubleEntryLedgerService $ledgerService,
        protected WebhookDispatcherService $webhookDispatcher,
        protected SystemSettingsService $settingsService,
        protected FeatureFlagService $featureFlags,
        protected NotificationManager $notifications
    ) {}

    /**
     * Allowed state transitions map.
     *
     * One line forward, and the line is the one a B2B trade actually walks:
     * accepted → prepared → handed over → received → closed. There is no branch
     * that says the money is inside HASSAL, because it never is — the two
     * businesses settle outside the platform, and the amounts on the deal are
     * the record of what they agreed, not a balance this system can release.
     *
     * `INSPECTION` stays because it is a real commercial window, not a payment
     * stage: the buyer takes the goods, checks them inside
     * `inspection_deadline_at`, and only then confirms. It is entered from
     * `DELIVERED` and leaves to `COMPLETED`, so the short path
     * (`DELIVERED → COMPLETED`) remains available for a counter pickup where
     * there is nothing to inspect later.
     */
    protected const ALLOWED_TRANSITIONS = [
        Deal::STATUS_DRAFT => [
            Deal::STATUS_PENDING_SELLER_APPROVAL,
            Deal::STATUS_CANCELLED,
        ],
        Deal::STATUS_PENDING_SELLER_APPROVAL => [
            Deal::STATUS_ACCEPTED,
            Deal::STATUS_CANCELLED,
        ],
        Deal::STATUS_ACCEPTED => [
            Deal::STATUS_PROCESSING,
            Deal::STATUS_READY_FOR_PICKUP,
            Deal::STATUS_DISPATCHED,
            Deal::STATUS_CANCELLED,
            Deal::STATUS_DISPUTED,
        ],
        Deal::STATUS_PROCESSING => [
            Deal::STATUS_READY_FOR_PICKUP,
            Deal::STATUS_DISPATCHED,
            Deal::STATUS_CANCELLED,
            Deal::STATUS_DISPUTED,
        ],
        Deal::STATUS_READY_FOR_PICKUP => [
            Deal::STATUS_DELIVERED,
            Deal::STATUS_CANCELLED,
            Deal::STATUS_DISPUTED,
        ],
        Deal::STATUS_DISPATCHED => [
            Deal::STATUS_DELIVERED,
            Deal::STATUS_DISPUTED,
        ],
        Deal::STATUS_DELIVERED => [
            Deal::STATUS_INSPECTION,
            Deal::STATUS_COMPLETED,
            Deal::STATUS_DISPUTED,
        ],
        Deal::STATUS_INSPECTION => [
            Deal::STATUS_COMPLETED,
            Deal::STATUS_DISPUTED,
        ],
        Deal::STATUS_COMPLETED => [],
        Deal::STATUS_CANCELLED => [],
        Deal::STATUS_DISPUTED => [
            Deal::STATUS_RESOLVED,
        ],
        // A resolution is a decision about a dispute, not a new state of the
        // goods: the order either carries on to the closure the goods deserved,
        // with the code still having to have been scanned for that, or it is
        // called off and the quantity goes back to the shelf.
        Deal::STATUS_RESOLVED => [
            Deal::STATUS_COMPLETED,
            Deal::STATUS_CANCELLED,
        ],
    ];

    /**
     * Who may perform each transition, and what the transition asserts.
     *
     * `actors` are resolved from the acting organization's relationship to the
     * deal: 'buyer', 'seller', or 'platform' (SuperAdmin). A route-level
     * permission cannot express this, because the same endpoint serves both
     * counterparties — so the identity check lives here, next to the rule it
     * protects.
     *
     * `requires_handover` is the deal's proof of delivery: a state that closes
     * the order cannot be reached while the order's code is unspent. It is
     * declared per target rather than inferred from "which state comes after
     * which", because `DISPUTED` and `CANCELLED` also come after delivery and
     * must stay reachable when the goods never arrived.
     */
    protected const TRANSITION_RULES = [
        Deal::STATUS_PENDING_SELLER_APPROVAL => ['actors' => ['buyer', 'platform'], 'requires_handover' => false],
        Deal::STATUS_ACCEPTED => ['actors' => ['seller', 'platform'], 'requires_handover' => false],
        Deal::STATUS_PROCESSING => ['actors' => ['seller', 'platform'], 'requires_handover' => false],
        Deal::STATUS_READY_FOR_PICKUP => ['actors' => ['seller', 'platform'], 'requires_handover' => false],
        Deal::STATUS_DISPATCHED => ['actors' => ['seller', 'platform'], 'requires_handover' => false],
        Deal::STATUS_DELIVERED => ['actors' => ['seller', 'platform'], 'requires_handover' => false],
        Deal::STATUS_INSPECTION => ['actors' => ['buyer', 'platform'], 'requires_handover' => true],
        Deal::STATUS_COMPLETED => ['actors' => ['buyer', 'platform'], 'requires_handover' => true],
        Deal::STATUS_DISPUTED => ['actors' => ['buyer', 'seller', 'platform'], 'requires_handover' => false],
        Deal::STATUS_RESOLVED => ['actors' => ['platform'], 'requires_handover' => false],
        Deal::STATUS_CANCELLED => ['actors' => ['buyer', 'seller', 'platform'], 'requires_handover' => false],
    ];

    /**
     * The states at which a scan can attest this order's handover.
     *
     * `DELIVERED` and `INSPECTION` are the obvious ones: the goods are physically
     * changing hands, or have just. `DISPUTED` and `RESOLVED` are here because of
     * what happens if they are not — a dispute raised between delivery and the
     * scan leaves the order unable to be closed after it is resolved, since
     * closure demands a spent code and the code would have no state in which it
     * could be spent. The gate is "no order closes without proof of handover",
     * not "the scan must happen on the same afternoon as the drop".
     *
     * Before `DELIVERED` there is nothing to prove; after `COMPLETED` the order
     * is closed and its code has already been spent.
     */
    protected const HANDOVER_ELIGIBLE_STATUSES = [
        Deal::STATUS_DELIVERED,
        Deal::STATUS_INSPECTION,
        Deal::STATUS_DISPUTED,
        Deal::STATUS_RESOLVED,
    ];

    /**
     * Transitions a deal to a new status with validation, lock, and side effects.
     *
     * The locking follows `App\Support\LockOrder`: `offers` before `deals`. Two
     * of the states below move stock counters, and a transition that reaches for
     * the offer *after* holding the deal's lock walks through those two tables in
     * the opposite direction to `InventoryReservationService` — a confirm and a
     * cancel on the same offer would then deadlock, with MySQL killing whichever
     * transaction had already written the ledger lines.
     *
     * Reading `deal.offer_id` before taking any lock is safe: the column is
     * assigned when the deal is created and never rewritten, so it cannot change
     * underneath us. The *state* checks that decide whether the transition is
     * legal are all made after the deal's lock is held, which is the part that
     * has to be under a lock.
     */
    public function transition(
        Deal $deal,
        string $toStatus,
        ?User $user = null,
        ?string $notes = null,
        ?array $metadata = null,
        ?int $actingOrganizationId = null
    ): Deal {
        return DB::transaction(function () use ($deal, $toStatus, $user, $notes, $metadata, $actingOrganizationId) {
            // Only these two states touch the offer's counters, so only these
            // two take its lock: `COMPLETED` converts a hold into sold stock and
            // `CANCELLED` returns a hold to the shelf. Locking the offer on a
            // `DISPATCHED` update would serialise every shipment of every deal
            // against one listing's row for no reason, and "no reason" is what
            // makes a rule like this get broken the first time someone needs
            // throughput.
            $touchesStock = in_array($toStatus, [Deal::STATUS_COMPLETED, Deal::STATUS_CANCELLED], true);

            // Read the pointer from the row rather than from `$deal`, which is
            // whatever the caller happened to hydrate. A caller that selected a
            // subset of columns would otherwise leave `$offerId` at zero and the
            // stock counters quietly un-decremented at exactly the moment a deal
            // completes — a wrong number in the safest-looking place.
            $offerId = $touchesStock ? (int) Deal::where('id', $deal->id)->value('offer_id') : 0;

            $lockedOffer = null;

            if ($touchesStock && $offerId > 0) {
                $lockedOffer = Offer::where('id', $offerId)->lockForUpdate()->first();
            }

            $lockedDeal = Deal::where('id', $deal->id)->lockForUpdate()->firstOrFail();

            LockOrder::assertBefore('offers', 'deals');

            $fromStatus = $lockedDeal->status;

            $this->authorizeTransition($lockedDeal, $toStatus, $user, $actingOrganizationId, $notes);

            if ($fromStatus === $toStatus) {
                // Not a silent success: the acting party is told the state they
                // asked for is already the state on the record. Returning the
                // deal here would let a double cancellation look like a second
                // release of stock.
                throw new Exception("This deal is already {$toStatus}; nothing changed.");
            }

            $allowed = self::ALLOWED_TRANSITIONS[$fromStatus] ?? [];
            if (!in_array($toStatus, $allowed, true)) {
                throw new Exception("Invalid deal state transition from '{$fromStatus}' to '{$toStatus}'.");
            }

            $updateData = ['status' => $toStatus];
            $now = Carbon::now();

            // Side effects based on target status
            switch ($toStatus) {
                case Deal::STATUS_ACCEPTED:
                    $updateData['accepted_at'] = $now;
                    // The deal says which account raised it; acceptance is the
                    // other signature, and a document with one is incomplete.
                    $updateData['seller_user_id'] = $user?->id ?? $lockedDeal->seller_user_id;
                    break;

                case Deal::STATUS_DISPATCHED:
                    $updateData['dispatched_at'] = $now;
                    break;

                case Deal::STATUS_DELIVERED:
                case Deal::STATUS_INSPECTION:
                    $updateData['delivered_at'] = $now;
                    $inspectionHours = $lockedDeal->inspection_window_hours ?? $this->settingsService->get('inspection.window', 48);
                    $updateData['inspection_deadline_at'] = $now->copy()->addHours($inspectionHours);
                    break;

                case Deal::STATUS_COMPLETED:
                    $updateData['completed_at'] = $now;

                    // The code is spent at the handover, before this line is
                    // reached, and its row stays the evidence: this machine does
                    // not copy that fact onto the deal, because a second place to
                    // say the same thing is a second place to be wrong.

                    // The order is closed with the goods received, so the held
                    // quantity stops being a hold. This is the only place a
                    // reservation becomes a sale, and it is the deal's last
                    // effect rather than a later accounting step: with settlement
                    // outside the platform there is no later event here to wait
                    // for.
                    $offer = $lockedOffer;
                    if ($offer) {
                        $qty = (float)$lockedDeal->quantity;
                        $newReserved = max(0, (float)$offer->reserved_quantity - $qty);
                        $newSold = (float)$offer->sold_quantity + $qty;
                        $offerStatus = Offer::statusAfterQuantityChange($offer->status, (float)$offer->available_quantity);

                        $offer->forceFill([
                            'reserved_quantity' => $newReserved,
                            'sold_quantity' => $newSold,
                            'status' => $offerStatus,
                        ])->save();
                    }

                    // Accrue the platform's own fee on a trade that happened.
                    // Money is not moving here — nobody paid HASSAL anything — so
                    // the entry is a receivable and a revenue line, and the flag
                    // still decides whether this installation keeps that book.
                    if ($this->featureFlags->isEnabled('double_entry_ledger')) {
                        $this->ledgerService->recordDealFee($lockedDeal, $user);
                    }
                    break;

                case Deal::STATUS_CANCELLED:
                    $updateData['cancelled_at'] = $now;
                    $updateData['cancellation_reason'] = $notes;

                    // Return reserved inventory to available stock
                    $offer = $lockedOffer;
                    if ($offer) {
                        $qty = (float)$lockedDeal->quantity;
                        $newReserved = max(0, (float)$offer->reserved_quantity - $qty);
                        $newAvailable = (float)$offer->available_quantity + $qty;
                        $offerStatus = Offer::statusAfterQuantityChange($offer->status, $newAvailable);

                        $offer->forceFill([
                            'reserved_quantity' => $newReserved,
                            'available_quantity' => $newAvailable,
                            'status' => $offerStatus,
                        ])->save();
                    }
                    break;
            }

            // A code that will never be scanned is a code that should not be
            // scannable: closing the order's life retires its proof of delivery.
            // A code already spent is left spent — it happened, and overwriting
            // an attested handover with a cancellation would delete the one fact
            // this table exists to keep.
            if ($toStatus === Deal::STATUS_CANCELLED) {
                DealHandover::where('deal_id', $lockedDeal->id)->first()?->invalidate();
            }

            $lockedDeal->update($updateData);

            // The order document begins when the seller signs it. Issuing the
            // code here — inside the same transaction that writes `ACCEPTED` —
            // is what makes "every accepted order has exactly one code" a
            // property of the machine rather than a habit of the screens.
            if ($toStatus === Deal::STATUS_ACCEPTED) {
                DealHandover::issueFor($lockedDeal, $user?->id);
            }

            // Create status history entry
            DealStatusHistory::create([
                'deal_id' => $lockedDeal->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'changed_by_user_id' => $user?->id,
                'notes' => $notes,
                'metadata' => $metadata,
            ]);

            // Dispatch webhooks to both buyer and seller organizations
            $payload = [
                'deal_id' => $lockedDeal->id,
                'deal_number' => $lockedDeal->deal_number,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'updated_at' => $now->toIso8601String(),
            ];
            $this->webhookDispatcher->dispatch('deal.status_changed', $payload, $lockedDeal->buyer_organization_id);
            $this->webhookDispatcher->dispatch('deal.status_changed', $payload, $lockedDeal->seller_organization_id);

            $this->announceStatusChange($lockedDeal, $toStatus, $actingOrganizationId);

            return $lockedDeal;
        });
    }

    /**
     * Tell the side that did not move the order that it moved.
     *
     * This lives in the machine rather than at the endpoints for the reason the
     * `NotificationManager` gives for its own preference check: a rule applied at
     * every call site is a rule the next call site will forget. Two of the four
     * doors that move a deal — a filed dispute and a platform ruling on one —
     * announced nothing at all while this sat in `DealController`, so the person
     * whose order had just been called off learned it by opening the app.
     *
     * It runs inside the transition's transaction, which is the point: a notice
     * about a state change that rolled back is a lie that happens to be
     * deliverable, and a notice written before the row committed can describe a
     * change that never took place.
     *
     * The acting organisation is left out rather than included. Its own screen
     * has just performed the change, and a notice that repeats what a person did
     * one second ago is how a shade turns into an echo. A platform decision has
     * no acting side, so both parties hear about that one.
     */
    protected function announceStatusChange(Deal $deal, string $toStatus, ?int $actingOrganizationId): void
    {
        $deal->load(['items', 'buyerOrganization', 'sellerOrganization']);

        $buyerId = (int) $deal->buyer_organization_id;
        $sellerId = (int) $deal->seller_organization_id;

        $recipientIds = $actingOrganizationId === null
            ? [$buyerId, $sellerId]
            : [$actingOrganizationId === $buyerId ? $sellerId : $buyerId];

        $moving = $actingOrganizationId === null
            ? null
            : ($actingOrganizationId === $buyerId ? $deal->buyerOrganization : $deal->sellerOrganization);

        $notice = DealNotices::moved($deal, $toStatus, $moving);

        Organization::whereIn('id', array_unique(array_filter($recipientIds)))
            ->get()
            ->each(function (Organization $recipient) use ($notice): void {
                $this->notifications->notifyOrganization(
                    $recipient,
                    $notice['title'],
                    $notice['body'],
                    $notice['data'] + ['organization_id' => $recipient->id]
                );
            });
    }

    /**
     * Reject a transition the acting party is not entitled to make.
     *
     * @throws Exception
     */
    protected function authorizeTransition(Deal $deal, string $toStatus, ?User $user, ?int $actingOrganizationId, ?string $notes): void
    {
        $rule = self::TRANSITION_RULES[$toStatus] ?? null;

        if ($rule === null) {
            throw new Exception(" '{$toStatus}' is not a state this deal can be moved to.");
        }

        $isPlatform = $user?->hasRole('SuperAdmin') ?? false;
        $actor = 'platform';

        if (!$isPlatform) {
            if ($actingOrganizationId === null) {
                throw new Exception('An active organization is required to act on a deal.');
            }

            $actor = match ((int) $actingOrganizationId) {
                (int) $deal->buyer_organization_id => 'buyer',
                (int) $deal->seller_organization_id => 'seller',
                default => throw new Exception('This deal does not belong to your organization.'),
            };
        }

        if (!in_array($actor, $rule['actors'], true)) {
            $expected = implode(' or ', $rule['actors']);
            throw new Exception("Only the {$expected} may move a deal to {$toStatus}; you are acting as the {$actor}.");
        }

        if (in_array($toStatus, [Deal::STATUS_CANCELLED, Deal::STATUS_DISPUTED, Deal::STATUS_RESOLVED], true)
            && blank($notes)) {
            throw new Exception("A reason is required to mark a deal as {$toStatus}.");
        }

        // The handover gate speaks about an order that is still in play. On a
        // completed or cancelled one the transition map refuses the step anyway,
        // and answering `HANDOVER_NOT_VERIFIED` there would send a client to a
        // scanner whose only reply is that the code is dead.
        if ($rule['requires_handover']
            && in_array($deal->status, Deal::FULFILLMENT_STATUSES, true)
            && !$this->handoverVerified($deal)) {
            throw new HandoverRefusal(
                "A deal cannot be closed as received until its handover code has been scanned for this order.",
                HandoverRefusal::NOT_VERIFIED
            );
        }
    }

    /**
     * Has this order's delivery been attested by a scan?
     *
     * `USED` is the whole answer: the code was issued for this deal, it matched
     * at the moment of the scan, and it has not been spent before. Nothing here
     * re-reads the code itself — the state machine is asked whether the proof
     * exists, not whether it can be reproduced.
     */
    public function handoverVerified(Deal $deal): bool
    {
        return DealHandover::where('deal_id', $deal->id)
            ->where('status', DealHandover::STATUS_USED)
            ->exists();
    }

    /**
     * The states a scan is accepted at, and the reason each of them qualifies.
     *
     * @return array<int, string>
     */
    public static function handoverEligibleStatuses(): array
    {
        return self::HANDOVER_ELIGIBLE_STATUSES;
    }

    /**
     * The states an order can still be complained about from.
     *
     * Read off the transition map instead of written again next to it: the day a
     * new state is allowed to fall into `DISPUTED`, this answer follows, and the
     * gate in `DisputeController` does not quietly keep refusing a complaint the
     * machine would have accepted.
     *
     * What comes out is the fulfillment span — accepted through inspection. A
     * draft or an unapproved request has no goods in motion to dispute, and a
     * completed or cancelled order has already been closed out.
     *
     * @return array<int, string>
     */
    public static function disputableStatuses(): array
    {
        return array_values(array_filter(
            array_keys(self::ALLOWED_TRANSITIONS),
            static fn (string $from): bool => in_array(Deal::STATUS_DISPUTED, self::ALLOWED_TRANSITIONS[$from], true)
        ));
    }
}
