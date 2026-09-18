<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Escrow;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\User;
use App\Services\DealStateMachineService;
use Exception;
use Tests\TestCase;

class DealStateMachineAndEscrowTest extends TestCase
{
    protected DealStateMachineService $stateMachine;
    protected Deal $deal;
    protected User $operatorUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateMachine = app(DealStateMachineService::class);
        $this->operatorUser = User::first();

        $seller = Organization::where('type', 'seller')->first() ?? Organization::first();
        $buyer = Organization::where('type', 'buyer')->first() ?? Organization::first();
        $offer = Offer::first();

        $this->deal = Deal::create([
            'deal_number' => 'DEAL-SM-' . rand(10000, 99999),
            'seller_organization_id' => $seller->id,
            'buyer_organization_id' => $buyer->id,
            'offer_id' => $offer->id,
            'status' => Deal::STATUS_PENDING_SELLER_APPROVAL,
            'quantity' => 10,
            'unit_price' => 50,
            'subtotal_amount' => 500,
            'vat_rate' => 0.15,
            'vat_amount' => 75,
            'platform_commission_rate' => 0.025,
            'platform_fee_amount' => 12.5,
            'buyer_total_amount' => 575,
            'seller_net_amount' => 562.5,
            'currency_code' => 'SAR',
            'inspection_window_hours' => 48,
        ]);
    }

    public function test_full_deal_lifecycle_state_transitions(): void
    {
        // 1. Pending Seller Approval -> Accepted
        $this->stateMachine->transition($this->deal, Deal::STATUS_ACCEPTED, $this->operatorUser, 'Seller approved terms');
        $this->assertEquals(Deal::STATUS_ACCEPTED, $this->deal->fresh()->status);
        $this->assertNotNull($this->deal->fresh()->accepted_at);

        // 2. Accepted -> Escrow Funded
        $this->stateMachine->transition($this->deal, Deal::STATUS_ESCROW_FUNDED, $this->operatorUser, 'Buyer escrow deposit verified');
        $this->assertEquals(Deal::STATUS_ESCROW_FUNDED, $this->deal->fresh()->status);
        $this->assertNotNull($this->deal->fresh()->paid_at);

        $escrow = Escrow::where('deal_id', $this->deal->id)->first();
        $this->assertNotNull($escrow);
        $this->assertEquals(Escrow::STATUS_HELD, $escrow->status);
        $this->assertEquals(575.00, (float) $escrow->gross_amount);

        // 3. Escrow Funded -> Processing -> Dispatched -> Delivered
        $this->stateMachine->transition($this->deal, Deal::STATUS_PROCESSING, $this->operatorUser);
        $this->stateMachine->transition($this->deal, Deal::STATUS_DISPATCHED, $this->operatorUser);
        $this->stateMachine->transition($this->deal, Deal::STATUS_DELIVERED, $this->operatorUser);
        $this->assertEquals(Deal::STATUS_DELIVERED, $this->deal->fresh()->status);
        $this->assertNotNull($this->deal->fresh()->inspection_deadline_at);

        // 4. Delivered -> Completed -> Settled
        $this->stateMachine->transition($this->deal, Deal::STATUS_COMPLETED, $this->operatorUser);
        $this->stateMachine->transition($this->deal, Deal::STATUS_SETTLED, $this->operatorUser, 'Funds released to seller');

        $this->assertEquals(Deal::STATUS_SETTLED, $this->deal->fresh()->status);
        $this->assertNotNull($this->deal->fresh()->settled_at);
        $this->assertEquals(Escrow::STATUS_RELEASED, $escrow->fresh()->status);
    }

    public function test_invalid_illegal_transition_is_blocked(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Invalid deal state transition");

        // Attempt illegal jump directly from PENDING_SELLER_APPROVAL to SETTLED
        $this->stateMachine->transition($this->deal, Deal::STATUS_SETTLED, $this->operatorUser);
    }
}
