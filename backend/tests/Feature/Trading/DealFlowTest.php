<?php

namespace Tests\Feature\Trading;

use App\Trading\Models\Deal;
use App\Trading\Models\DealHandover;
use App\Trading\Models\DealStatusHistory;
use App\Trading\Models\Dispute;
use App\Marketplace\Models\Offer;
use App\Organizations\Models\Organization;
use App\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * After the handshake: fulfilment, money-shaped claims, and who may say what.
 *
 * §26 and §28-§31: a deal is a snapshot of terms that must not drift, and a
 * state machine whose every step asserts something about the world — stock
 * moved, goods dispatched, the handover attested by a scan. These tests refuse
 * the steps the platform has not earned and check the counters behind the ones
 * it has.
 *
 * The chain they walk is the one the product actually sells:
 * accepted → prepared → out for delivery → delivered → the code scanned →
 * received → closed. Money is not in it. The two businesses settle outside
 * HASSAL, and an order here records what happened to the goods.
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
     * The buyer user recorded on a deal, for calls that must be made from
     * their side without threading them through every fixture.
     */
    protected function buyerOf(Deal $deal): User
    {
        return User::findOrFail($deal->buyer_user_id);
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

    public function test_moving_the_goods_and_closing_the_order_are_two_different_jobs(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, , $deal] = $this->dealInFlight();

        $this->move($deal->id, Deal::STATUS_ACCEPTED, $sellerUser, $seller)->assertOk();

        // A warehouse employee of the same business: right company, and the
        // grants that company's `staff` standing actually carries — dispatch and
        // the delivery scan, not the commercial close.
        $staff = $this->memberOf($seller, 'staff');

        $this->move($deal->id, Deal::STATUS_COMPLETED, $staff, $seller)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'DEAL_TRANSITION_FORBIDDEN');

        $this->move($deal->id, Deal::STATUS_PROCESSING, $staff, $seller)
            ->assertOk()
            ->assertJsonPath('data.status', Deal::STATUS_PROCESSING);

        // And the other direction, which is the more interesting one: the buyer's
        // own owner holds `deals.dispatch` — an owner holds every business name —
        // and is still refused, because a grant says what a person can do in
        // their company and the actor rule says whose order this is.
        $this->move($deal->id, Deal::STATUS_DISPATCHED, $buyerUser, $buyer)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'STATE_TRANSITION_FAILED');

        $this->assertSame(Deal::STATUS_PROCESSING, $deal->fresh()->status);
    }

    public function test_a_state_the_product_does_not_define_moves_nothing(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, $offer, $deal] = $this->dealInFlight();

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

    /**
     * The states this product used to offer, and no longer does.
     *
     * `SETTLED` and `ESCROW_FUNDED` said something about money HASSAL never
     * held, and they are refused here by name rather than by absence: a client
     * that still sends one gets a message that points at the vocabulary instead
     * of a 500 from an unhandled state. §1 of the brief is explicit that the
     * payment happens outside the platform while the order, the delivery and the
     * receipt are recorded inside it.
     */
    public function test_no_state_claims_money_the_platform_never_held(): void
    {
        [, , $sellerUser, $seller, , $deal] = $this->dealInFlight();

        foreach (['PENDING_PAYMENT', 'ESCROW_FUNDED', 'SETTLED', 'REFUNDED', 'PAID'] as $retired) {
            $this->move($deal->id, $retired, $sellerUser, $seller)
                ->assertStatus(422)
                ->assertJsonPath('error_code', 'DEAL_STATE_UNKNOWN');
        }

        // The vocabulary itself carries no money-holding state.
        foreach (Deal::STATUSES as $status) {
            $this->assertDoesNotMatchRegularExpression(
                '/PAY|ESCROW|SETTL|REFUND|WALLET|INVOICE/i',
                $status,
                "'{$status}' reads as a claim about money this platform moves."
            );
        }

        // Nor does the schema keep the timestamps that recorded such claims.
        foreach (['paid_at', 'settled_at', 'escrow_funded_at'] as $column) {
            $this->assertFalse(Schema::hasColumn('deals', $column));
        }

        // And no route offers a payment surface to hit.
        foreach (Route::getRoutes() as $route) {
            $this->assertDoesNotMatchRegularExpression(
                '#payment|escrow|wallet|refund#i',
                $route->uri(),
                'A payment-flavoured route is still wired up: ' . $route->uri()
            );
        }

        $this->assertSame(Deal::STATUS_PENDING_SELLER_APPROVAL, $deal->fresh()->status);
        $this->assertDatabaseCount('deal_status_histories', 0);
    }

    public function test_the_walk_to_completion_moves_the_stock_exactly_once(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, $offer, $deal] = $this->dealInFlight();

        $this->move($deal->id, Deal::STATUS_ACCEPTED, $sellerUser, $seller)->assertOk();
        $this->move($deal->id, Deal::STATUS_PROCESSING, $sellerUser, $seller)->assertOk();
        $this->move($deal->id, Deal::STATUS_DISPATCHED, $sellerUser, $seller)->assertOk();
        $this->move($deal->id, Deal::STATUS_DELIVERED, $sellerUser, $seller)->assertOk();

        $delivered = $deal->fresh();
        $this->assertNotNull($delivered->dispatched_at);
        $this->assertNotNull($delivered->delivered_at);

        // The seller's word that the goods arrived is not the buyer's proof that
        // they received them, and the order cannot close on the seller's alone.
        $this->move($deal->id, Deal::STATUS_COMPLETED, $buyerUser, $buyer)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'HANDOVER_NOT_VERIFIED');

        $this->move($deal->id, Deal::STATUS_INSPECTION, $buyerUser, $buyer)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'HANDOVER_NOT_VERIFIED');

        // Someone at the buyer's end puts the phone over the label on the pallet.
        $code = DealHandover::where('deal_id', $deal->id)->value('code');
        $this->assertNotNull($code, 'Acceptance is what issues the code.');

        $this->scan($deal->id, $code, $this->memberOf($buyer, 'staff'), $buyer)
            ->assertCreated()
            ->assertJsonPath('data.status', DealHandover::STATUS_USED)
            ->assertJsonPath('data.next_step', 'buyer_confirms_receipt');

        // The buyer takes delivery into inspection, whose deadline comes from the
        // configured window rather than a number invented per deal.
        $this->move($deal->id, Deal::STATUS_INSPECTION, $buyerUser, $buyer)->assertOk();
        $inspecting = $deal->fresh();
        $this->assertNotNull($inspecting->inspection_deadline_at);
        $this->assertTrue($inspecting->inspection_deadline_at->greaterThan(now()));

        $this->move($deal->id, Deal::STATUS_COMPLETED, $buyerUser, $buyer)->assertOk();

        // The selling side has no act left to perform: closing the order is what
        // the buyer's receipt does, and the platform's fee accrues on the way
        // through that step rather than at a settlement stage that is not here.
        $completed = $deal->fresh();
        $this->assertNotNull($completed->completed_at);

        $offer->refresh();
        $this->assertSame('60.00', (string) $offer->available_quantity);
        $this->assertSame('0.00', (string) $offer->reserved_quantity);
        $this->assertSame('40.00', (string) $offer->sold_quantity);
        $this->assertEqualsWithDelta(
            (float) $offer->total_quantity,
            (float) $offer->available_quantity + (float) $offer->reserved_quantity + (float) $offer->sold_quantity,
            0.001
        );

        // Completed is the end of the line: nothing further claims to have happened.
        $this->move($deal->id, Deal::STATUS_CANCELLED, $sellerUser, $seller)->assertStatus(422);

        $this->assertSame(
            [
                Deal::STATUS_ACCEPTED,
                Deal::STATUS_PROCESSING,
                Deal::STATUS_DISPATCHED,
                Deal::STATUS_DELIVERED,
                Deal::STATUS_INSPECTION,
                Deal::STATUS_COMPLETED,
            ],
            DealStatusHistory::where('deal_id', $deal->id)->orderBy('id')->pluck('to_status')->all()
        );
    }

    public function test_a_counter_pickup_can_close_without_an_inspection_window(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, , $deal] = $this->dealInFlight();

        // The other branch of the machine: the goods are ready at the seller's
        // door and go straight from `READY_FOR_PICKUP` to `DELIVERED`, with no
        // dispatch line in between.
        $this->move($deal->id, Deal::STATUS_ACCEPTED, $sellerUser, $seller)->assertOk();
        $this->move($deal->id, Deal::STATUS_READY_FOR_PICKUP, $sellerUser, $seller)->assertOk();
        $this->move($deal->id, Deal::STATUS_DELIVERED, $sellerUser, $seller)->assertOk();

        $code = DealHandover::where('deal_id', $deal->id)->value('code');

        // `DELIVERED → COMPLETED` is a real edge: the buyer's man counts the
        // cartons at the gate and there is nothing to check later. What is not
        // optional is the scan in front of it.
        $this->move($deal->id, Deal::STATUS_COMPLETED, $buyerUser, $buyer)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'HANDOVER_NOT_VERIFIED');

        $this->scan($deal->id, $code, $buyerUser, $buyer)->assertCreated();

        $this->move($deal->id, Deal::STATUS_COMPLETED, $buyerUser, $buyer)
            ->assertOk()
            ->assertJsonPath('data.status', Deal::STATUS_COMPLETED);

        // The inspection window still opened — from the moment the goods arrived,
        // which is when the buyer's clock on a complaint starts — but nobody had
        // to stand in it to close the order.
        $this->assertNotNull($deal->fresh()->inspection_deadline_at);
        $this->assertNotContains(
            Deal::STATUS_INSPECTION,
            DealStatusHistory::where('deal_id', $deal->id)->pluck('to_status')->all()
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

    public function test_a_dispute_needs_a_case_file_and_both_sides_can_open_one(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, , $deal] = $this->dealInFlight();

        $this->move($deal->id, Deal::STATUS_ACCEPTED, $sellerUser, $seller)->assertOk();
        $this->move($deal->id, Deal::STATUS_PROCESSING, $sellerUser, $seller)->assertOk();

        // The state machine will take an order to DISPUTED for either party, but
        // a complaint that reaches the platform's queue is the `disputes` route:
        // it is the one that writes the case with a reason and a claim on it.
        Sanctum::actingAs($buyerUser);
        $this->withHeaders($this->withTenant($buyer))
            ->postJson('/api/v1/disputes', [
                'deal_id' => $deal->id,
                'reason' => Dispute::REASONS[0],
                'description' => 'وصلت العبوات تالفة',
                'claimed_amount' => 250000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', Dispute::STATUS_OPEN)
            ->assertJsonPath('data.deal.status', Deal::STATUS_DISPUTED);

        $this->assertSame(Deal::STATUS_DISPUTED, $deal->fresh()->status);

        // One live case per order: a second filing must not put two rulings in
        // front of the platform for the same goods.
        $this->withHeaders($this->withTenant($buyer))
            ->postJson('/api/v1/disputes', [
                'deal_id' => $deal->id,
                'reason' => Dispute::REASONS[0],
                'description' => 'تكرار لنفس الشكوى',
                'claimed_amount' => 100,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'DISPUTE_ALREADY_OPEN');

        // An order that has not been accepted has nothing in motion to complain
        // about, and says so by name.
        [, $freshBuyer, , , , $fresh] = $this->dealInFlight(10.0);
        Sanctum::actingAs($this->buyerOf($fresh));
        $this->withHeaders($this->withTenant($freshBuyer))
            ->postJson('/api/v1/disputes', [
                'deal_id' => $fresh->id,
                'reason' => Dispute::REASONS[0],
                'description' => 'بانتظار البائع',
                'claimed_amount' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'DEAL_NOT_DISPUTABLE');
    }

    /**
     * A ruling that keeps the order alive must not strand it.
     *
     * `COMPLETED` demands a spent code, and a code can only be spent while the
     * goods are being handed over — so if `DISPUTED` and `RESOLVED` were not
     * scan-eligible, a complaint filed between the dock and the scan would leave
     * the order permanently unclosable. This walks that corner: dispute, ruling,
     * scan, close.
     */
    public function test_a_dispute_can_be_raised_by_either_side_and_closed_only_by_the_platform(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, , $deal] = $this->dealInFlight();

        $this->move($deal->id, Deal::STATUS_ACCEPTED, $sellerUser, $seller)->assertOk();
        $this->move($deal->id, Deal::STATUS_PROCESSING, $sellerUser, $seller)->assertOk();

        // Neither party may rule on their own argument: `disputes.resolve` is on
        // no business standing at all, so the refusal comes from the grant.
        $this->move($deal->id, Deal::STATUS_DISPUTED, $buyerUser, $buyer, [
            'notes' => 'وصلت العبوات تالفة',
        ])->assertOk();

        $this->move($deal->id, Deal::STATUS_RESOLVED, $buyerUser, $buyer)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'DEAL_TRANSITION_FORBIDDEN');

        $this->move($deal->id, Deal::STATUS_RESOLVED, $sellerUser, $seller)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'DEAL_TRANSITION_FORBIDDEN');

        $this->move($deal->id, Deal::STATUS_RESOLVED, $this->platformUser(), $seller, [
            'notes' => 'استرد Buyer جزءاً من المبلغ بالاتفاق',
        ])->assertOk()->assertJsonPath('data.status', Deal::STATUS_RESOLVED);

        $code = DealHandover::where('deal_id', $deal->id)->value('code');

        // The order survived the argument, so the delivery still has to be
        // proved — and the scan is still available to prove it.
        $this->move($deal->id, Deal::STATUS_COMPLETED, $buyerUser, $buyer)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'HANDOVER_NOT_VERIFIED');

        $this->scan($deal->id, $code, $buyerUser, $buyer)->assertCreated();

        $this->move($deal->id, Deal::STATUS_COMPLETED, $buyerUser, $buyer)
            ->assertOk()
            ->assertJsonPath('data.status', Deal::STATUS_COMPLETED);
    }

    public function test_a_ruling_that_calls_the_order_off_puts_the_quantity_back(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, $offer, $deal] = $this->dealInFlight();

        $this->move($deal->id, Deal::STATUS_ACCEPTED, $sellerUser, $seller)->assertOk();
        $this->move($deal->id, Deal::STATUS_PROCESSING, $sellerUser, $seller)->assertOk();

        Sanctum::actingAs($buyerUser);
        $disputeId = $this->withHeaders($this->withTenant($buyer))
            ->postJson('/api/v1/disputes', [
                'deal_id' => $deal->id,
                'reason' => Dispute::REASONS[0],
                'description' => 'لم تصل الشحنة',
                'claimed_amount' => 1000000,
            ])->assertCreated()->json('data.id');

        $platform = $this->platformUser();
        Sanctum::actingAs($platform);
        $this->withHeaders($this->withTenant($seller))
            ->postJson('/api/v1/disputes/' . $disputeId . '/resolve', [
                'status' => Dispute::STATUS_RESOLVED_CANCELLATION,
                'notes' => 'البضاعة لم تُشحن؛ ألغي الطلب',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Dispute::STATUS_RESOLVED_CANCELLATION)
            ->assertJsonPath('data.deal.status', Deal::STATUS_CANCELLED);

        $offer->refresh();
        $this->assertSame('100.00', (string) $offer->available_quantity);
        $this->assertSame('0.00', (string) $offer->reserved_quantity);

        // The same ruling twice is refused rather than restocked twice.
        $this->withHeaders($this->withTenant($seller))
            ->postJson('/api/v1/disputes/' . $disputeId . '/resolve', [
                'status' => Dispute::STATUS_RESOLVED_RELEASE,
                'notes' => 'تكرار الحكم',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'DISPUTE_ALREADY_RESOLVED');

        $offer->refresh();
        $this->assertSame('100.00', (string) $offer->available_quantity);

        // A ruling is a decision about a dispute, and the desk that takes it is
        // the platform's: a party holding every business permission still cannot.
        Sanctum::actingAs($sellerUser);
        $second = Dispute::create([
            'dispute_number' => 'DSP-TEST-2',
            'deal_id' => $deal->id,
            'initiator_organization_id' => $seller->id,
            'initiator_user_id' => $sellerUser->id,
            'reason' => Dispute::REASONS[0],
            'description' => 'شكوى أخرى على الطلب نفسه',
            'claimed_amount' => 1,
            'status' => Dispute::STATUS_OPEN,
        ]);

        $this->withHeaders($this->withTenant($seller))
            ->postJson('/api/v1/disputes/' . $second->id . '/resolve', [
                'status' => Dispute::STATUS_RESOLVED_RELEASE,
                'notes' => 'حكم من طرف له مصلحة',
            ])
            ->assertStatus(403);
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
}
