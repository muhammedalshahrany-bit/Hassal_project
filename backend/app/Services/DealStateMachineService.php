<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\DealStatusHistory;
use App\Models\Escrow;
use App\Models\Offer;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;

class DealStateMachineService
{
    public function __construct(
        protected DoubleEntryLedgerService $ledgerService,
        protected WebhookDispatcherService $webhookDispatcher,
        protected SystemSettingsService $settingsService,
        protected FeatureFlagService $featureFlags
    ) {}

    /**
     * Allowed state transitions map.
     *
     * PENDING_PAYMENT forks. With the escrow capability switched on the money
     * passes through HASSAL and the deal goes via ESCROW_FUNDED; without it the
     * two businesses settle between themselves and the deal simply moves into
     * fulfilment. The second branch is not a shortcut around payment — it is the
     * ordinary B2B path, and removing it would strand every deal at ACCEPTED in
     * a deployment that does not hold customer funds.
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
            Deal::STATUS_PENDING_PAYMENT,
            Deal::STATUS_ESCROW_FUNDED,
            Deal::STATUS_CANCELLED,
        ],
        Deal::STATUS_PENDING_PAYMENT => [
            Deal::STATUS_ESCROW_FUNDED,
            Deal::STATUS_PROCESSING,
            Deal::STATUS_CANCELLED,
        ],
        Deal::STATUS_ESCROW_FUNDED => [
            Deal::STATUS_PROCESSING,
            Deal::STATUS_CANCELLED,
            Deal::STATUS_DISPUTED,
        ],
        Deal::STATUS_PROCESSING => [
            Deal::STATUS_READY_FOR_PICKUP,
            Deal::STATUS_DISPATCHED,
            Deal::STATUS_DELIVERED,
            Deal::STATUS_DISPUTED,
            Deal::STATUS_CANCELLED,
        ],
        Deal::STATUS_READY_FOR_PICKUP => [
            Deal::STATUS_DELIVERED,
            Deal::STATUS_DISPUTED,
            Deal::STATUS_CANCELLED,
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
        Deal::STATUS_COMPLETED => [
            Deal::STATUS_SETTLED,
        ],
        Deal::STATUS_DISPUTED => [
            Deal::STATUS_RESOLVED,
            Deal::STATUS_REFUNDED,
            Deal::STATUS_SETTLED,
        ],
        Deal::STATUS_RESOLVED => [
            Deal::STATUS_SETTLED,
            Deal::STATUS_REFUNDED,
        ],
        Deal::STATUS_SETTLED => [],
        Deal::STATUS_REFUNDED => [],
        Deal::STATUS_CANCELLED => [],
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
     * `feature` gates transitions that claim a capability the deployment may
     * not have. ESCROW_FUNDED asserts HASSAL is holding somebody's money; with
     * no payment provider wired that claim is false, so the transition is
     * unreachable until the escrow flag is deliberately switched on.
     */
    protected const TRANSITION_RULES = [
        Deal::STATUS_PENDING_SELLER_APPROVAL => ['actors' => ['buyer', 'platform'], 'feature' => null],
        Deal::STATUS_ACCEPTED => ['actors' => ['seller', 'platform'], 'feature' => null],
        Deal::STATUS_PENDING_PAYMENT => ['actors' => ['buyer', 'seller', 'platform'], 'feature' => null],
        Deal::STATUS_ESCROW_FUNDED => ['actors' => ['buyer', 'platform'], 'feature' => 'escrow'],
        Deal::STATUS_PROCESSING => ['actors' => ['seller', 'platform'], 'feature' => null],
        Deal::STATUS_READY_FOR_PICKUP => ['actors' => ['seller', 'platform'], 'feature' => null],
        Deal::STATUS_DISPATCHED => ['actors' => ['seller', 'platform'], 'feature' => null],
        Deal::STATUS_DELIVERED => ['actors' => ['buyer', 'seller', 'platform'], 'feature' => null],
        Deal::STATUS_INSPECTION => ['actors' => ['buyer', 'platform'], 'feature' => null],
        Deal::STATUS_COMPLETED => ['actors' => ['buyer', 'platform'], 'feature' => null],
        Deal::STATUS_SETTLED => ['actors' => ['seller', 'platform'], 'feature' => null],
        Deal::STATUS_DISPUTED => ['actors' => ['buyer', 'seller', 'platform'], 'feature' => null],
        Deal::STATUS_RESOLVED => ['actors' => ['platform'], 'feature' => null],
        Deal::STATUS_REFUNDED => ['actors' => ['platform'], 'feature' => 'escrow'],
        Deal::STATUS_CANCELLED => ['actors' => ['buyer', 'seller', 'platform'], 'feature' => null],
    ];

    /**
     * Transitions a deal to a new status with validation, lock, and financial side-effects.
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
            $lockedDeal = Deal::where('id', $deal->id)->lockForUpdate()->firstOrFail();
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

                case Deal::STATUS_ESCROW_FUNDED:
                    $updateData['paid_at'] = $now;

                    // Create or activate Escrow record
                    Escrow::updateOrCreate(
                        ['deal_id' => $lockedDeal->id],
                        [
                            'escrow_number' => 'ESC-' . $lockedDeal->deal_number,
                            'buyer_organization_id' => $lockedDeal->buyer_organization_id,
                            'seller_organization_id' => $lockedDeal->seller_organization_id,
                            'gross_amount' => $lockedDeal->buyer_total_amount,
                            'platform_fee_amount' => $lockedDeal->platform_fee_amount,
                            'seller_net_amount' => $lockedDeal->seller_net_amount,
                            'currency_code' => $lockedDeal->currency_code,
                            'status' => Escrow::STATUS_HELD,
                            'funded_at' => $now,
                        ]
                    );

                    // Post Double-Entry Ledger for Escrow Funding
                    if ($this->featureFlags->isEnabled('double_entry_ledger')) {
                        $this->ledgerService->recordEscrowFunding($lockedDeal, $user);
                    }
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
                    break;

                case Deal::STATUS_SETTLED:
                    $updateData['settled_at'] = $now;

                    // Release escrow record
                    if ($escrow = $lockedDeal->escrow) {
                        $escrow->update([
                            'status' => Escrow::STATUS_RELEASED,
                            'released_at' => $now,
                            'release_notes' => 'Settlement confirmed.',
                        ]);
                    }

                    // Post Double-Entry Ledger for Settlement
                    if ($this->featureFlags->isEnabled('double_entry_ledger')) {
                        $this->ledgerService->recordDealSettlement($lockedDeal, $user);
                    }

                    // Permanently decrement offer reserved quantity and increment sold quantity
                    $offer = Offer::where('id', $lockedDeal->offer_id)->lockForUpdate()->first();
                    if ($offer) {
                        $qty = (float)$lockedDeal->quantity;
                        $newReserved = max(0, (float)$offer->reserved_quantity - $qty);
                        $newSold = (float)$offer->sold_quantity + $qty;
                        $offerStatus = Offer::statusAfterQuantityChange($offer->status, (float)$offer->available_quantity);

                        $offer->update([
                            'reserved_quantity' => $newReserved,
                            'sold_quantity' => $newSold,
                            'status' => $offerStatus,
                        ]);
                    }
                    break;

                case Deal::STATUS_CANCELLED:
                    $updateData['cancelled_at'] = $now;
                    $updateData['cancellation_reason'] = $notes;

                    // Return reserved inventory to available stock
                    $offer = Offer::where('id', $lockedDeal->offer_id)->lockForUpdate()->first();
                    if ($offer) {
                        $qty = (float)$lockedDeal->quantity;
                        $newReserved = max(0, (float)$offer->reserved_quantity - $qty);
                        $newAvailable = (float)$offer->available_quantity + $qty;
                        $offerStatus = Offer::statusAfterQuantityChange($offer->status, $newAvailable);

                        $offer->update([
                            'reserved_quantity' => $newReserved,
                            'available_quantity' => $newAvailable,
                            'status' => $offerStatus,
                        ]);
                    }
                    break;

                case Deal::STATUS_REFUNDED:
                    if ($escrow = $lockedDeal->escrow) {
                        $escrow->update([
                            'status' => Escrow::STATUS_REFUNDED,
                            'refunded_at' => $now,
                            'release_notes' => 'Refund processed to buyer.',
                        ]);
                    }
                    if ($this->featureFlags->isEnabled('double_entry_ledger')) {
                        $this->ledgerService->recordEscrowRefund($lockedDeal, (float)$lockedDeal->buyer_total_amount, $user, $notes);
                    }
                    break;
            }

            $lockedDeal->update($updateData);

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

            return $lockedDeal;
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

        if (in_array($toStatus, [Deal::STATUS_CANCELLED, Deal::STATUS_DISPUTED, Deal::STATUS_RESOLVED, Deal::STATUS_REFUNDED], true)
            && blank($notes)) {
            throw new Exception("A reason is required to mark a deal as {$toStatus}.");
        }

        if ($rule['feature'] !== null && !$this->featureFlags->isEnabled($rule['feature'])) {
            throw new Exception("Marking a deal as {$toStatus} requires the '{$rule['feature']}' capability, which is not enabled in this deployment.");
        }
    }
}
