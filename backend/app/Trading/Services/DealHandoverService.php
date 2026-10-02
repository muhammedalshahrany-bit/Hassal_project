<?php

namespace App\Trading\Services;

use App\Trading\Models\Deal;
use App\Trading\Models\DealHandover;
use App\Organizations\Models\Organization;
use App\Identity\Models\User;
use App\Trading\Support\DealNotices;
use App\Trading\Support\HandoverRefusal;
use App\Notifications\Services\NotificationManager;
use Illuminate\Support\Facades\DB;

/**
 * The one place a handover code is judged.
 *
 * Two doors ask the same question — "did these goods actually change hands for
 * this order?" — the scan endpoint and the state machine that will not close a
 * deal without an answer. Both come through here, so the day the rules grow (a
 * window in which a code may be scanned, a second signature, a photo of the
 * pallet) there is one file to grow and not two that drift apart.
 */
class DealHandoverService
{
    public function __construct(protected NotificationManager $notificationManager) {}

    /**
     * The order's code, issued if the order has one and does not yet hold it.
     *
     * Acceptance is what issues a code, so this is normally a read. The issuing
     * branch exists for an order that reached a later state by some path this
     * file has not been shown yet — a screen that cannot display a code because
     * a row is missing is a screen that will be told the deal cannot close, with
     * nothing to do about it.
     */
    public function forDeal(Deal $deal, ?int $issuedByUserId = null): ?DealHandover
    {
        $existing = DealHandover::where('deal_id', $deal->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        return in_array($deal->status, Deal::FULFILLMENT_STATUSES, true)
            ? DealHandover::issueFor($deal, $issuedByUserId)
            : null;
    }

    /**
     * Attest that the goods of this order were handed over, by scanning its code.
     *
     * The caller has already established that the acting organization is a party
     * to this order and holds the permission — that is the shape of every other
     * gate in this API, and the controller in front of this file keeps it.
     *
     * @throws HandoverRefusal
     */
    public function verify(
        Deal $deal,
        string $presentedCode,
        User $user,
        ?int $actingOrganizationId,
        ?string $notes = null
    ): DealHandover {
        $code = DealHandover::normalizeCode($presentedCode);

        if ($code === '') {
            throw new HandoverRefusal('No handover code was presented.', HandoverRefusal::CODE_INVALID);
        }

        return DB::transaction(function () use ($deal, $code, $user, $actingOrganizationId, $notes) {
            // The order's lock comes first, in `LockOrder` sequence, and the
            // state is read from the locked row rather than from the one the
            // controller happened to hydrate. Without this, a cancellation that
            // lands while a phone is over the label would let the scan spend a
            // code on an order that no longer exists to be delivered — and the
            // retirement rule in `DealStateMachineService` would have already
            // written INVALIDATED over it.
            $locked = Deal::where('id', $deal->id)->lockForUpdate()->firstOrFail();

            $handover = DealHandover::where('code', $code)->lockForUpdate()->first();

            // A code that is not this order's, and a code that does not exist,
            // get the same answer on purpose. "That code is valid but belongs to
            // another order" is a way to ask, one scan at a time, whether some
            // other business has an open order — and the person at the loading
            // bay who typed the wrong label does not need a different sentence,
            // they need to look at the label again.
            if ($handover === null || (int) $handover->deal_id !== (int) $deal->id) {
                throw new HandoverRefusal(
                    'This code was not issued for this order.',
                    HandoverRefusal::CODE_INVALID
                );
            }

            // What the code already is comes before what the order is, because it
            // is the more specific answer: a cancelled order's retired label
            // deserves "this order was called off" rather than a lecture about
            // which states a handover happens at.
            if ($handover->status === DealHandover::STATUS_USED) {
                throw new HandoverRefusal(
                    'This order\'s handover was already attested'
                    . ($handover->verified_at ? ' at ' . $handover->verified_at->toIso8601String() : '') . '.',
                    HandoverRefusal::CODE_ALREADY_USED
                );
            }

            if ($handover->status === DealHandover::STATUS_INVALIDATED) {
                throw new HandoverRefusal(
                    'This order was cancelled, and its handover code was retired with it.',
                    HandoverRefusal::CODE_INVALIDATED
                );
            }

            if (!in_array($locked->status, DealStateMachineService::handoverEligibleStatuses(), true)) {
                throw new HandoverRefusal(
                    "A handover cannot be attested while the order is {$locked->status}; the goods are handed over at "
                    . implode(', ', DealStateMachineService::handoverEligibleStatuses()) . '.',
                    HandoverRefusal::NOT_AT_DELIVERY
                );
            }

            // Written under the row lock taken above, which is what makes
            // "exactly one attestation per order" true when two phones scan the
            // same label at the same gate.
            $handover->forceFill([
                'status' => DealHandover::STATUS_USED,
                'verified_at' => now(),
                'verified_by_user_id' => $user->id,
                'verified_by_organization_id' => $actingOrganizationId,
                'verification_notes' => $notes,
            ])->save();

            $this->notifyCounterparty($deal, $actingOrganizationId);

            return $handover;
        });
    }

    /**
     * Tell the other business that their goods were signed for.
     *
     * The buyer's copy of that news is the deal screen; this is the seller's, and
     * it is the half that would otherwise never be heard — one side scans the
     * code and then sees nothing until the other closes the order.
     */
    protected function notifyCounterparty(Deal $deal, ?int $actingOrganizationId): void
    {
        $buyerOrgId = (int) $deal->buyer_organization_id;
        $sellerOrgId = (int) $deal->seller_organization_id;

        // The recipient is whichever party did not scan. A platform scan (no
        // acting organization of its own, or one that is neither party) is a
        // dispute settlement being carried out at the gate, and both sides need
        // to hear about it.
        $recipientOrgIds = $actingOrganizationId === null
            ? [$buyerOrgId, $sellerOrgId]
            : [(int) $actingOrganizationId === $buyerOrgId ? $sellerOrgId : $buyerOrgId];

        // Models, not ids: the manager notifies a company by walking its
        // memberships, and an integer arriving there is a fatal on the scan
        // route — the one request in this flow that a driver makes with a phone
        // in one hand at the back of a truck.
        $recipients = Organization::whereIn('id', array_unique(array_filter($recipientOrgIds)))->get();

        // The notice names the goods and the money, not only the order: the
        // person at the back of a truck has more than one deal open, and «رمز
        // التسليم للطلب DEAL-… تحقّق» does not say which one they just signed for.
        $deal->loadMissing(['items', 'buyerOrganization', 'sellerOrganization']);
        $notice = DealNotices::handoverVerified(
            $deal,
            $actingOrganizationId === null ? null : Organization::find($actingOrganizationId)
        );

        foreach ($recipients as $recipient) {
            $this->notificationManager->notifyOrganization(
                $recipient,
                $notice['title'],
                $notice['body'],
                $notice['data'] + ['organization_id' => $recipient->id]
            );
        }
    }
}
