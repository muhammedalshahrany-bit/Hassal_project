<?php

namespace App\Trading\Services;

use App\Core\Exceptions\ApiRefused;
use App\Identity\Models\User;
use App\Trading\Models\Deal;
use App\Trading\Models\Dispute;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * A complaint on an order: filing it, and ruling on it.
 *
 * Both halves exist to keep two records honest at once. A dispute is a row with
 * a state of its own, and the order it names has a state too, and the pair is
 * only meaningful while they agree — which is why neither half is allowed to
 * write one column without the other. The last version of this platform had
 * filings that left a live complaint on an order the platform was never told
 * about, and rulings that cancelled an order by hand instead of through the
 * machine that releases the held quantity and retires the handover code.
 *
 * The vocabulary of reasons, rulings and open states lives on `Dispute`; what
 * lives here is only the question of when a filing or a ruling may happen, and
 * what else has to move with it.
 */
class DisputeCaseService
{
    public function __construct(
        protected DealStateMachineService $stateMachine
    ) {}

    /**
     * File the complaint the caller is allowed to file about the order they own
     * a side of.
     *
     * The order is looked up here rather than passed in because the scope is a
     * rule, not a convenience: a caller may only dispute an order they stand on,
     * and a controller that fetched it first would be deciding that rule twice.
     *
     * @param array<string, mixed> $validated
     *
     * @throws ApiRefused with the exact envelope the API answers with
     */
    public function file(int $dealId, array $validated, int $orgId, User $user): Dispute
    {
        $deal = Deal::where(function ($query) use ($orgId) {
            $query->where('buyer_organization_id', $orgId)->orWhere('seller_organization_id', $orgId);
        })->findOrFail($dealId);

        // One open case per order. Without this, the second filing on the same
        // order skipped the transition (the deal was already DISPUTED) and left
        // two live disputes for the platform to rule on separately — which is how
        // one order ends up cancelled by one ruling and released by another.
        // Checked before the state gate below, because an order sitting in
        // DISPUTED is exactly the case this message is for.
        if (Dispute::where('deal_id', $deal->id)->whereIn('status', Dispute::OPEN_STATUSES)->exists()) {
            throw new ApiRefused(
                'This order already has a dispute open. Add to it rather than filing another.',
                'DISPUTE_ALREADY_OPEN',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // A complaint can only attach to an order that is still in play. On a
        // draft there is nothing to complain about yet; on a completed or
        // cancelled order the chain has already closed, and the machine would
        // refuse the transition anyway — better to say so here, where the answer
        // names the order's state, than to let an exception surface as a 500.
        if (!in_array($deal->status, DealStateMachineService::disputableStatuses(), true)) {
            throw new ApiRefused(
                "An order that is {$deal->status} cannot be disputed.",
                'DEAL_NOT_DISPUTABLE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return DB::transaction(function () use ($validated, $deal, $orgId, $user): Dispute {
            $dispute = Dispute::create([
                'dispute_number' => 'DSP-' . date('ymd') . '-' . strtoupper(Str::random(4)),
                'deal_id' => $deal->id,
                'initiator_organization_id' => $orgId,
                'initiator_user_id' => $user->id,
                'reason' => $validated['reason'],
                'description' => $validated['description'],
                'claimed_amount' => $validated['claimed_amount'],
                'evidence_urls' => $validated['evidence_urls'] ?? null,
                'status' => Dispute::STATUS_OPEN,
            ]);

            // The acting organization is passed because the machine decides who
            // may move a deal by which side of the table they sit on. Left out,
            // every filing from a buyer or a seller would be refused for having
            // no context rather than for being wrong, and the complaint that
            // never reaches `DISPUTED` would leave a live dispute row on an order
            // the platform is not told about.
            $this->stateMachine->transition(
                $deal,
                Deal::STATUS_DISPUTED,
                $user,
                'Dispute opened: ' . $validated['reason'],
                null,
                $orgId
            );

            return $dispute;
        });
    }

    /**
     * Rule on a case as the platform's neutral, and carry the order to whatever
     * the ruling implies.
     *
     * @param array<string, mixed> $validated
     *
     * @throws ApiRefused with the exact envelope the API answers with
     */
    public function rule(Dispute $dispute, array $validated, User $user): Dispute
    {
        if (in_array($dispute->status, Dispute::RESOLVED_STATUSES, true)) {
            throw new ApiRefused(
                'This dispute has already been ruled on.',
                'DISPUTE_ALREADY_RESOLVED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $deal = $dispute->deal;
        $cancels = $validated['status'] === Dispute::STATUS_RESOLVED_CANCELLATION;

        DB::transaction(function () use ($dispute, $validated, $user, $deal, $cancels): void {
            $dispute->update([
                'status' => $validated['status'],
                'resolution_notes' => $validated['notes'],
                'resolved_by_user_id' => $user->id,
                'resolved_at' => Carbon::now(),
            ]);

            // `RESOLVED` is the dispute's ending, not the order's: the row says a
            // neutral has ruled, and the goods then either carry on to the
            // buyer's receipt or are called off. A deal that is not sitting in
            // DISPUTED — the complaint was filed, then the order was closed some
            // other way — is left where it is; the ruling is still on the record.
            if ($deal && $deal->status === Deal::STATUS_DISPUTED) {
                $transitioned = $this->stateMachine->transition(
                    $deal,
                    Deal::STATUS_RESOLVED,
                    $user,
                    "Dispute {$dispute->dispute_number} ruled: {$validated['notes']}"
                );

                if ($cancels) {
                    // Two steps, in the machine's own order, so the cancellation
                    // runs the release of stock and the retirement of the
                    // handover code instead of this file doing either by hand.
                    $this->stateMachine->transition(
                        $transitioned,
                        Deal::STATUS_CANCELLED,
                        $user,
                        "Dispute {$dispute->dispute_number}: order called off"
                    );
                }
            }
        });

        return $dispute;
    }

    /**
     * Whether this ruling calls the order off, which is the only thing the
     * answer says differently.
     *
     * Written as a question about the vocabulary rather than left as a
     * comparison in the controller, so the sentence a party reads cannot drift
     * from the state that was written.
     */
    public function callsTheOrderOff(string $rulingStatus): bool
    {
        return $rulingStatus === Dispute::STATUS_RESOLVED_CANCELLATION;
    }
}
