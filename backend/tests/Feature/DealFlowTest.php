<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStatusHistory;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * After the handshake: fulfilment, money-shaped claims, and who may say what.
 *
 * §26 and §28-§31: a deal is a snapshot of terms that must not drift, and a
 * state machine whose every step asserts something about the world — stock
 * moved, goods dispatched, money held. These tests refuse the steps the
 * platform has not earned and check the counters behind the ones it has.
 */
class DealFlowTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    /**
     * A deal already formed against held stock, which is the only way a deal
     * comes into existence.
     *
     * @return array{0: User, 1: Organization, 2: User, 3: Organization, 4: Offer, 5: Deal}
     */
    protected function dealInFlight(float $quantity = 40.0): array
    {
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'محل');
        [$sellerUser, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد');

        $offer = $this->offer($seller);
        $reservation = $this->reserveDirectly($offer, $buyerUser, $buyer, $quantity);

        Sanctum::actingAs($buyerUser);
        $deal = $this->postJson('/api/v1/deals', [
            'reservation_id' => $reservation->id,
            'delivery_method' => 'PICKUP',
        ], $this->withTenant($buyer))
            ->assertCreated()
            ->assertJsonPath('data.status', Deal::STATUS_PENDING_SELLER_APPROVAL);

        return [
            $buyerUser,
            $buyer,
            $sellerUser,
            $seller,
            $offer->fresh(),
            Deal::find($deal->json('data.id')),
        ];
    }

    protected function move(int $dealId, string $status, User $user, Organization $organization, array $payload = [])
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/v1/deals/' . $dealId . '/transition', array_merge([
            'status' => $status,
        ], $payload), $this->withTenant($organization));
    }

    /**
     * A colleague of the company whose only standing is the membership itself.
     *
     * There is no global `SellerStaff` any more — what a person may do inside a
     * company comes from that company's membership row — so the fixture that
     * used to hand out the retired role now just writes the standing it was
     * meant to model.
     */
    protected function staffIn(Organization $organization, string $standing = 'staff'): User
    {
        $user = $this->user('موظف');
        $this->membership($organization, $user, $standing);

        return $user;
    }

    public function test_a_deal_remembers_the_terms_it_was_made_on(): void
    {
        [$buyerUser, $buyer, , $seller, $offer, $deal] = $this->dealInFlight();

        $this->assertSame((float) $offer->unit_price, (float) $deal->unit_price);
        $this->assertSame('40.00', (string) $deal->quantity);
        $this->assertSame('YER', $deal->currency_code);
        $this->assertSame('1000000.0000', (string) $deal->base_amount);
        $this->assertNotNull($deal->reservation_id);
        $this->assertDatabaseCount('deal_items', 1);

        // The commercial terms are a photograph, not a live link: what the two
        // businesses agreed cannot be rewritten by whoever edits a setting next.
        $before = $deal->fresh()->toArray();

        $this->setSetting('commission.rate', '0.0500', 'decimal');
        Offer::where('id', $offer->id)->update(['unit_price' => 31000]);

        $deal->refresh();
        $this->assertSame((float) $before['unit_price'], (float) $deal->unit_price);
        $this->assertSame((float) $before['base_amount'], (float) $deal->base_amount);
        $this->assertSame((float) $before['platform_fee_amount'], (float) $deal->platform_fee_amount);
        $this->assertSame((float) $before['tax_amount'], (float) $deal->tax_amount);

        // A deal made afterwards does see the operator's new commission.
        $later = $this->reserveDirectly($offer->fresh(), $buyerUser, $buyer, 10);

        Sanctum::actingAs($buyerUser);
        $newDeal = $this->postJson('/api/v1/deals', [
            'reservation_id' => $later->id,
            'delivery_method' => 'PICKUP',
        ], $this->withTenant($buyer))->assertCreated();

        $this->assertSame('0.0500', (string) $newDeal->json('data.platform_fee_rate_snapshot'));
        $this->assertGreaterThan(0, (float) $newDeal->json('data.platform_fee_amount'));
    }

    public function test_only_the_seller_on_this_deal_can_approve_it(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, , $deal] = $this->dealInFlight();
        [, $otherSellerOrg] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد آخر');

        // The buyer cannot approve their own deal, however much they want to.
        $this->move($deal->id, Deal::STATUS_ACCEPTED, $buyerUser, $buyer)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'STATE_TRANSITION_FAILED');

        // A different seller holding the same permissions never gets that far.
        $this->move($deal->id, Deal::STATUS_ACCEPTED, $this->ownerOf($otherSellerOrg), $otherSellerOrg)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'DEAL_ACCESS_DENIED');

        // The seller who holds the stock is the one who says yes.
        $this->move($deal->id, Deal::STATUS_ACCEPTED, $sellerUser, $seller)
            ->assertOk()
            ->assertJsonPath('data.status', Deal::STATUS_ACCEPTED);

        $accepted = $deal->fresh();
        $this->assertNotNull($accepted->accepted_at);
        $this->assertSame($sellerUser->id, (int) $accepted->seller_user_id);

        $history = DealStatusHistory::where('deal_id', $deal->id)->latest('id')->first();
        $this->assertSame(Deal::STATUS_PENDING_SELLER_APPROVAL, $history->from_status);
        $this->assertSame(Deal::STATUS_ACCEPTED, $history->to_status);
        $this->assertSame($sellerUser->id, (int) $history->changed_by_user_id);
    }

    public function test_dispatching_is_the_sellers_job_but_approving_is_not(): void
    {
        [, , $sellerUser, $seller, , $deal] = $this->dealInFlight();

        $this->move($deal->id, Deal::STATUS_ACCEPTED, $sellerUser, $seller)->assertOk();
        $this->move($deal->id, Deal::STATUS_PENDING_PAYMENT, $sellerUser, $seller)->assertOk();

        // A warehouse employee of the same business: right company, wrong grant.
        $staff = $this->staffIn($seller);

        $this->move($deal->id, Deal::STATUS_COMPLETED, $staff, $seller)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'DEAL_TRANSITION_FORBIDDEN');

        $this->move($deal->id, Deal::STATUS_PROCESSING, $staff, $seller)
            ->assertOk()
            ->assertJsonPath('data.status', Deal::STATUS_PROCESSING);
    }

    public function test_a_state_the_product_does_not_define_moves_nothing(): void
    {
        [, , $sellerUser, $seller, $offer, $deal] = $this->dealInFlight();

        // A typo is not a state the actor rules were meant to guard, so it is
        // rejected by name before the machine is asked anything.
        foreach (['SHIPPED', 'accepted', 'Accepted'] as $notAState) {
            $this->move($deal->id, $notAState, $sellerUser, $seller)
                ->assertStatus(422)
                ->assertJsonPath('error_code', 'DEAL_STATE_UNKNOWN');
        }

        $this->move($deal->id, '', $sellerUser, $seller)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');

        $this->assertSame(Deal::STATUS_PENDING_SELLER_APPROVAL, $deal->fresh()->status);
        $this->assertDatabaseCount('deal_status_histories', 0);
        $offer->refresh();
        $this->assertSame('60.00', (string) $offer->available_quantity);
    }

    public function test_the_walk_to_settlement_moves_the_stock_exactly_once(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, $offer, $deal] = $this->dealInFlight();

        $this->move($deal->id, Deal::STATUS_ACCEPTED, $sellerUser, $seller)->assertOk();
        $this->move($deal->id, Deal::STATUS_PENDING_PAYMENT, $sellerUser, $seller)->assertOk();
        $this->move($deal->id, Deal::STATUS_PROCESSING, $sellerUser, $seller)->assertOk();
        $this->move($deal->id, Deal::STATUS_DISPATCHED, $sellerUser, $seller)->assertOk();
        $this->move($deal->id, Deal::STATUS_DELIVERED, $sellerUser, $seller)->assertOk();

        $delivered = $deal->fresh();
        $this->assertNotNull($delivered->dispatched_at);
        $this->assertNotNull($delivered->delivered_at);

        // The buyer takes delivery into inspection, whose deadline comes from the
        // configured window rather than a number invented per deal.
        $this->move($deal->id, Deal::STATUS_INSPECTION, $buyerUser, $buyer)->assertOk();
        $inspecting = $deal->fresh();
        $this->assertNotNull($inspecting->inspection_deadline_at);
        $this->assertTrue($inspecting->inspection_deadline_at->greaterThan(now()));

        $this->move($deal->id, Deal::STATUS_COMPLETED, $buyerUser, $buyer)->assertOk();

        // Settlement is the selling side's accounting act, and with one standing
        // vocabulary the refusal now has to hold at both layers: a finance
        // colleague of the buyer lacks `deals.manage` and is refused by grant,
        // while the buyer's owner holds it for their own trades and is still
        // refused by the actor rule, because holding a permission is not the same
        // as being the party who sold this stock.
        $this->move($deal->id, Deal::STATUS_SETTLED, $this->staffIn($buyer, 'finance'), $buyer)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'DEAL_TRANSITION_FORBIDDEN');

        $this->move($deal->id, Deal::STATUS_SETTLED, $buyerUser, $buyer)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'STATE_TRANSITION_FAILED');

        $this->assertSame(Deal::STATUS_COMPLETED, $deal->fresh()->status);

        $this->move($deal->id, Deal::STATUS_SETTLED, $sellerUser, $seller)
            ->assertOk()
            ->assertJsonPath('data.status', Deal::STATUS_SETTLED);

        $offer->refresh();
        $this->assertSame('60.00', (string) $offer->available_quantity);
        $this->assertSame('0.00', (string) $offer->reserved_quantity);
        $this->assertSame('40.00', (string) $offer->sold_quantity);
        $this->assertEqualsWithDelta(
            (float) $offer->total_quantity,
            (float) $offer->available_quantity + (float) $offer->reserved_quantity + (float) $offer->sold_quantity,
            0.001
        );

        // Settled is the end of the line: nothing further claims to have happened.
        $this->move($deal->id, Deal::STATUS_CANCELLED, $sellerUser, $seller)->assertStatus(422);

        $this->assertSame(
            [
                Deal::STATUS_ACCEPTED,
                Deal::STATUS_PENDING_PAYMENT,
                Deal::STATUS_PROCESSING,
                Deal::STATUS_DISPATCHED,
                Deal::STATUS_DELIVERED,
                Deal::STATUS_INSPECTION,
                Deal::STATUS_COMPLETED,
                Deal::STATUS_SETTLED,
            ],
            DealStatusHistory::where('deal_id', $deal->id)->orderBy('id')->pluck('to_status')->all()
        );
    }

    public function test_a_cancelled_deal_gives_the_stock_back_once(): void
    {
        [, $buyer, $sellerUser, $seller, $offer, $deal] = $this->dealInFlight();

        // Backing out needs a reason; the counterparty deserves to know why.
        $this->move($deal->id, Deal::STATUS_CANCELLED, $sellerUser, $seller)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'STATE_TRANSITION_FAILED');

        $offer->refresh();
        $this->assertSame('60.00', (string) $offer->available_quantity);

        $this->move($deal->id, Deal::STATUS_CANCELLED, $sellerUser, $seller, [
            'notes' => 'توقف التوريد هذا الشهر',
        ])->assertOk()->assertJsonPath('data.status', Deal::STATUS_CANCELLED);

        $offer->refresh();
        $this->assertSame('100.00', (string) $offer->available_quantity);
        $this->assertSame('0.00', (string) $offer->reserved_quantity);
        $this->assertSame('توقف التوريد هذا الشهر', $deal->fresh()->cancellation_reason);

        // A second cancellation is refused instead of restocking a second time.
        $this->move($deal->id, Deal::STATUS_CANCELLED, $this->buyerOf($deal), $buyer)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'STATE_TRANSITION_FAILED');

        $offer->refresh();
        $this->assertSame('100.00', (string) $offer->available_quantity);
        $this->assertSame('0.00', (string) $offer->reserved_quantity);
    }

    public function test_a_dispute_can_be_raised_by_either_side_and_closed_only_by_the_platform(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, , $deal] = $this->dealInFlight();

        $this->move($deal->id, Deal::STATUS_ACCEPTED, $sellerUser, $seller)->assertOk();
        $this->move($deal->id, Deal::STATUS_PENDING_PAYMENT, $sellerUser, $seller)->assertOk();
        $this->move($deal->id, Deal::STATUS_PROCESSING, $sellerUser, $seller)->assertOk();

        $this->move($deal->id, Deal::STATUS_DISPUTED, $buyerUser, $buyer)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'STATE_TRANSITION_FAILED');

        $this->move($deal->id, Deal::STATUS_DISPUTED, $buyerUser, $buyer, [
            'notes' => 'وصلت العبوات تالفة',
        ])->assertOk();

        // Neither party can close their own argument: the grant they would need
        // is not on either business's role.
        $this->move($deal->id, Deal::STATUS_RESOLVED, $buyerUser, $buyer)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'DEAL_TRANSITION_FORBIDDEN');

        $this->move($deal->id, Deal::STATUS_RESOLVED, $sellerUser, $seller)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'DEAL_TRANSITION_FORBIDDEN');

        [$platformUser] = $this->accountWithOrganization('SuperAdmin', 'seller', 'منصة حصّال');
        $this->move($deal->id, Deal::STATUS_RESOLVED, $platformUser, $seller, [
            'notes' => 'استرد Buyer جزءاً من المبلغ بالاتفاق',
        ])->assertOk()->assertJsonPath('data.status', Deal::STATUS_RESOLVED);
    }

    public function test_hassal_does_not_record_money_it_never_held(): void
    {
        [, , $sellerUser, $seller, $offer, $deal] = $this->dealInFlight();

        $this->move($deal->id, Deal::STATUS_ACCEPTED, $sellerUser, $seller)->assertOk();

        // escrow is off in this deployment, so ESCROW_FUNDED is a claim about a
        // bank balance HASSAL does not have.
        $this->move($deal->id, Deal::STATUS_ESCROW_FUNDED, $sellerUser, $seller)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'STATE_TRANSITION_FAILED');

        $this->assertDatabaseCount('escrows', 0);
        $this->assertSame(Deal::STATUS_ACCEPTED, $deal->fresh()->status);
        $this->assertNull($deal->fresh()->paid_at);

        $offer->refresh();
        $this->assertSame('40.00', (string) $offer->reserved_quantity);
    }

    public function test_each_side_sees_its_own_deals_and_nobody_elses(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, , $deal] = $this->dealInFlight();
        [$otherBuyerUser, $otherBuyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'محل آخر');

        Sanctum::actingAs($buyerUser);
        $this->getJson('/api/v1/deals', $this->withTenant($buyer))
            ->assertOk()
            ->assertJsonPath('data.0.id', $deal->id);

        // Another buyer with every deal permission in the world, looking at the
        // same endpoint, is shown nothing.
        Sanctum::actingAs($otherBuyerUser);
        $this->getJson('/api/v1/deals', $this->withTenant($otherBuyer))
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->getJson('/api/v1/deals/' . $deal->id, $this->withTenant($otherBuyer))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'DEAL_ACCESS_DENIED');

        // The seller is a party too, from their own side of the table.
        Sanctum::actingAs($sellerUser);
        $this->getJson('/api/v1/deals?perspective=seller', $this->withTenant($seller))
            ->assertOk()
            ->assertJsonPath('data.0.id', $deal->id);
    }

    /**
     * The buyer user recorded on a deal, for calls that must be made from
     * their side without threading them through every fixture.
     */
    protected function buyerOf(Deal $deal): User
    {
        return User::findOrFail($deal->buyer_user_id);
    }

    /**
     * The account that owns an organization built by the fixture helpers.
     */
    protected function ownerOf(Organization $organization): User
    {
        return User::findOrFail(
            \App\Models\OrganizationMembership::where('organization_id', $organization->id)->value('user_id')
        );
    }
}
