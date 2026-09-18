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
        protected SystemSettingsService $settingsService
    ) {}

    /**
     * Allowed state transitions map.
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
     * Transitions a deal to a new status with validation, lock, and financial side-effects.
     */
    public function transition(Deal $deal, string $toStatus, ?User $user = null, ?string $notes = null, ?array $metadata = null): Deal
    {
        return DB::transaction(function () use ($deal, $toStatus, $user, $notes, $metadata) {
            $lockedDeal = Deal::where('id', $deal->id)->lockForUpdate()->firstOrFail();
            $fromStatus = $lockedDeal->status;

            if ($fromStatus === $toStatus) {
                return $lockedDeal;
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
                    $this->ledgerService->recordEscrowFunding($lockedDeal, $user);
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
                    $this->ledgerService->recordDealSettlement($lockedDeal, $user);

                    // Permanently decrement offer reserved quantity and increment sold quantity
                    $offer = Offer::where('id', $lockedDeal->offer_id)->lockForUpdate()->first();
                    if ($offer) {
                        $qty = (float)$lockedDeal->quantity;
                        $newReserved = max(0, (float)$offer->reserved_quantity - $qty);
                        $newSold = (float)$offer->sold_quantity + $qty;
                        $offerStatus = ((float)$offer->available_quantity <= 0.0001 && $newReserved <= 0.0001) ? Offer::STATUS_SOLD_OUT : $offer->status;

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
                        $offerStatus = ($offer->status === Offer::STATUS_FULLY_RESERVED && $newAvailable > 0) ? Offer::STATUS_ACTIVE : $offer->status;

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
                    $this->ledgerService->recordEscrowRefund($lockedDeal, (float)$lockedDeal->buyer_total_amount, $user, $notes);
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
}
