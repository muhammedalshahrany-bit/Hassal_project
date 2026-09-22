<?php

namespace Tests\Feature;

use App\Models\DemandMatch;
use App\Models\Offer;
use App\Models\PurchaseRequest;
use App\Models\Reservation;
use App\Services\DemandMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * The ask, the answer, and the exact moment stock is committed.
 *
 * §23-§26: choosing an offer makes a purchase request that a seller reviews.
 * Nothing before the seller's acceptance may hold inventory, and nothing after
 * it may pretend the buyer alone decided. These tests watch the counters and the
 * state, not the status codes.
 */
class PurchaseRequestFlowTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    /**
     * A real match between a real demand and a real offer, produced by the same
     * engine the endpoints call.
     *
     * @return array{0: \App\Models\User, 1: \App\Models\Organization, 2: \App\Models\User, 3: \App\Models\Organization, 4: \App\Models\Offer, 5: \App\Models\DemandMatch}
     */
    protected function matched(float $target = 40.0, array $offerAttributes = []): array
    {
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'محل');
        [$sellerUser, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد');

        $category = $this->category();
        $offer = $this->offer($seller, $offerAttributes, $this->warehouse($seller), $this->productVariant($seller, $category));
        $demand = $this->demand($buyer, $buyerUser, [
            'category_id' => $category->id,
            'target_quantity' => $target,
        ]);

        $matches = app(DemandMatchingService::class)->matchDemand($demand);
        $this->assertNotEmpty($matches, 'Fixture could not produce a match; the scenario under test is unreachable.');

        return [$buyerUser, $buyer, $sellerUser, $seller, $offer, $matches->first()];
    }

    protected function ask(\App\Models\User $buyerUser, \App\Models\Organization $buyer, int $matchId, array $payload = [])
    {
        Sanctum::actingAs($buyerUser);

        return $this->postJson('/api/v1/purchase-requests/matches/' . $matchId, $payload, $this->withTenant($buyer));
    }

    public function test_asking_holds_nothing_and_consumes_the_match(): void
    {
        [$buyerUser, $buyer, , $seller, $offer, $match] = $this->matched();

        $response = $this->ask($buyerUser, $buyer, $match->id, ['requested_quantity' => 25])
            ->assertCreated()
            ->assertJsonPath('data.status', PurchaseRequest::STATUS_REQUESTED)
            ->assertJsonPath('data.requested_quantity', '25.00');

        $requestId = $response->json('data.id');

        // The shelf is untouched: an ask is a question, not a purchase.
        $offer->refresh();
        $this->assertSame('100.00', (string) $offer->available_quantity);
        $this->assertSame('0.00', (string) $offer->reserved_quantity);
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('deals', 0);

        // The terms come from the offer, not from whatever the buyer typed.
        $this->assertSame('25000.0000', (string) $response->json('data.requested_price'));
        $this->assertSame('YER', $response->json('data.currency_code'));
        $this->assertNotNull($response->json('data.purchase_request_number'));

        // The match is spent, and cannot fund a second ask.
        $this->assertSame(DemandMatch::STATUS_USED, DemandMatch::find($match->id)->status);

        $this->ask($buyerUser, $buyer, $match->id)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'MATCH_NOT_ACTIONABLE');

        // A member of an unrelated organization reaches the controller and is
        // refused by its own party check — the tenant middleware cannot help here,
        // because their context is legitimate for their own business.
        [$strangerUser, $stranger] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'غريب');
        Sanctum::actingAs($strangerUser);
        $this->getJson('/api/v1/purchase-requests/' . $requestId, $this->withTenant($stranger))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PURCHASE_REQUEST_ACCESS_DENIED');

        // Someone with no organization at all is stopped before the controller.
        Sanctum::actingAs($this->user());
        $this->getJson('/api/v1/purchase-requests/' . $requestId, $this->withTenant($stranger))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'ORGANIZATION_ACCESS_DENIED');
    }

    public function test_the_quantity_is_checked_against_what_the_seller_will_actually_do(): void
    {
        [$buyerUser, $buyer, , $seller, , $match] = $this->matched(40.0, ['min_order_quantity' => 10]);

        $this->ask($buyerUser, $buyer, $match->id, ['requested_quantity' => 500])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'QUANTITY_EXCEEEDS_AVAILABLE');

        $this->ask($buyerUser, $buyer, $match->id, ['requested_quantity' => 5])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'QUANTITY_BELOW_MIN_ORDER');

        // Refusals leave no trace to clean up.
        $this->assertDatabaseCount('purchase_requests', 0);
    }

    public function test_only_the_seller_answers_and_acceptance_is_what_holds_stock(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, $offer, $match] = $this->matched();

        $requestId = $this->ask($buyerUser, $buyer, $match->id)
            ->assertCreated()
            ->json('data.id');

        // The buyer cannot accept their own ask.
        Sanctum::actingAs($buyerUser);
        $this->postJson('/api/v1/purchase-requests/' . $requestId . '/accept', [], $this->withTenant($buyer))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'SELLER_ACCEPT_DENIED');

        Sanctum::actingAs($sellerUser);
        $this->postJson('/api/v1/purchase-requests/' . $requestId . '/accept', [], $this->withTenant($seller))
            ->assertOk()
            ->assertJsonPath('data.status', PurchaseRequest::STATUS_ACCEPTED);

        $purchaseRequest = PurchaseRequest::with('reservation')->find($requestId);
        $this->assertNotNull($purchaseRequest->reservation_id);
        $this->assertSame(Reservation::STATUS_ACTIVE, $purchaseRequest->reservation->status);
        $this->assertSame(40.0, (float) $purchaseRequest->reservation->quantity);
        $this->assertSame($buyerUser->id, (int) $purchaseRequest->reservation->buyer_user_id);
        $this->assertNotNull($purchaseRequest->seller_user_id);

        $offer->refresh();
        $this->assertSame('60.00', (string) $offer->available_quantity);
        $this->assertSame('40.00', (string) $offer->reserved_quantity);

        // Answering twice is not a second hold.
        $this->postJson('/api/v1/purchase-requests/' . $requestId . '/accept', [], $this->withTenant($seller))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'PURCHASE_REQUEST_NOT_ACCEPTABLE');

        $offer->refresh();
        $this->assertSame('60.00', (string) $offer->available_quantity);
    }

    public function test_a_seller_cannot_accept_more_than_the_shelf_holds(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, $offer, $firstMatch] = $this->matched(80.0);

        $firstId = $this->ask($buyerUser, $buyer, $firstMatch->id)->assertCreated()->json('data.id');

        // A second buyer asks for the same shelf through their own match.
        [$otherBuyerUser, $otherBuyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'محل ثانٍ');
        $otherDemand = $this->demand($otherBuyer, $otherBuyerUser, [
            'category_id' => $offer->category_id,
            'target_quantity' => 80,
        ]);
        $secondMatch = app(DemandMatchingService::class)->matchDemand($otherDemand)->first();

        $secondId = $this->ask($otherBuyerUser, $otherBuyer, $secondMatch->id)->assertCreated()->json('data.id');

        Sanctum::actingAs($sellerUser);
        $this->postJson('/api/v1/purchase-requests/' . $firstId . '/accept', [], $this->withTenant($seller))
            ->assertOk();

        // The seller who answers second is told the truth, not shown success.
        $this->postJson('/api/v1/purchase-requests/' . $secondId . '/accept', [], $this->withTenant($seller))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'PURCHASE_REQUEST_ACCEPT_FAILED');

        $this->assertSame(PurchaseRequest::STATUS_REQUESTED, PurchaseRequest::find($secondId)->status);
        $offer->refresh();
        $this->assertSame('20.00', (string) $offer->available_quantity);
        $this->assertSame('80.00', (string) $offer->reserved_quantity);
    }

    public function test_a_refusal_needs_a_reason_and_ends_the_conversation(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, , $match] = $this->matched();

        $requestId = $this->ask($buyerUser, $buyer, $match->id)->assertCreated()->json('data.id');

        Sanctum::actingAs($sellerUser);
        $this->postJson('/api/v1/purchase-requests/' . $requestId . '/reject', [], $this->withTenant($seller))
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason.0', 'The reason field is required.');

        $this->postJson('/api/v1/purchase-requests/' . $requestId . '/reject', [
            'reason' => 'الكمية غير متوفرة هذا الأسبوع',
        ], $this->withTenant($seller))
            ->assertOk()
            ->assertJsonPath('data.status', PurchaseRequest::STATUS_REJECTED);

        // Rejected is terminal: it cannot be accepted afterwards.
        $this->postJson('/api/v1/purchase-requests/' . $requestId . '/accept', [], $this->withTenant($seller))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'PURCHASE_REQUEST_NOT_ACCEPTABLE');

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_negotiation_records_the_proposed_terms_without_rewriting_the_ask(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, $offer, $match] = $this->matched(40.0);

        $requestId = $this->ask($buyerUser, $buyer, $match->id)
            ->assertCreated()
            ->assertJsonPath('data.requested_quantity', '40.00')
            ->assertJsonPath('data.requested_price', '25000.0000')
            ->json('data.id');

        Sanctum::actingAs($sellerUser);

        // A "counter-offer" that changes nothing is the seller dodging the ask.
        $this->postJson('/api/v1/purchase-requests/' . $requestId . '/propose', [
            'proposed_terms' => ['quantity' => 40, 'unit_price' => 25000],
        ], $this->withTenant($seller))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'PROPOSAL_HAS_NO_CHANGE');

        $this->postJson('/api/v1/purchase-requests/' . $requestId . '/propose', [
            'proposed_terms' => ['quantity' => 30, 'unit_price' => 26500],
            'notes' => 'متوفر ٣٠ كرتونة لهذا الأسبوع',
        ], $this->withTenant($seller))
            ->assertOk()
            ->assertJsonPath('data.status', PurchaseRequest::STATUS_PROPOSED)
            ->assertJsonPath('data.proposed_terms.quantity', 30)
            ->assertJsonPath('data.proposed_terms.unit_price', 26500);

        // The buyer's original ask is still on the record, untouched.
        $record = PurchaseRequest::find($requestId);
        $this->assertSame('40.00', (string) $record->requested_quantity);
        $this->assertSame('25000.0000', (string) $record->requested_price);

        // The seller cannot adopt their own proposal.
        $this->postJson('/api/v1/purchase-requests/' . $requestId . '/accept-proposal', [], $this->withTenant($seller))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'BUYER_PROPOSAL_DENIED');

        Sanctum::actingAs($buyerUser);
        $this->postJson('/api/v1/purchase-requests/' . $requestId . '/accept-proposal', [], $this->withTenant($buyer))
            ->assertOk()
            ->assertJsonPath('data.status', PurchaseRequest::STATUS_REQUESTED)
            ->assertJsonPath('data.requested_quantity', '30.00')
            ->assertJsonPath('data.requested_price', '26500.0000')
            ->assertJsonPath('data.proposed_terms', null);

        // Adopting a proposal does not hold stock — only the seller's acceptance does.
        $offer->refresh();
        $this->assertSame('100.00', (string) $offer->available_quantity);
        $this->assertDatabaseCount('reservations', 0);

        Sanctum::actingAs($sellerUser);
        $this->postJson('/api/v1/purchase-requests/' . $requestId . '/accept', [], $this->withTenant($seller))
            ->assertOk();

        $offer->refresh();
        $this->assertSame('70.00', (string) $offer->available_quantity);
        $this->assertSame('30.00', (string) $offer->reserved_quantity);
    }

    public function test_a_buyer_withdraws_only_an_unanswered_ask(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, , $match] = $this->matched();

        $requestId = $this->ask($buyerUser, $buyer, $match->id)->assertCreated()->json('data.id');

        // Two layers answer a seller who wants to withdraw someone else's ask.
        // A colleague of the seller who is granted no buying vocabulary at all is
        // stopped by the route itself — §36 says the right lives there and not in
        // a hidden button.
        Sanctum::actingAs($this->memberOf($seller, 'auditor'));
        $this->postJson('/api/v1/purchase-requests/' . $requestId . '/cancel', [], $this->withTenant($seller))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PERMISSION_DENIED')
            ->assertJsonPath('error.details.required_permission', 'requests.cancel');

        // The seller's own owner is granted `requests.cancel`, because the same
        // company places asks when it buys, and is stopped one layer further
        // down: holding the grant is not the same as being the party who made
        // this request. A single standing vocabulary makes that trade-off
        // deliberately — a route permission cannot read which side of a row the
        // caller is on.
        Sanctum::actingAs($sellerUser);
        $this->postJson('/api/v1/purchase-requests/' . $requestId . '/cancel', [], $this->withTenant($seller))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'BUYER_CANCEL_DENIED');

        // ...and holding every grant is still not being the buyer. Platform staff
        // reach the controller and are stopped by its own party check.
        [$platformUser] = $this->accountWithOrganization('SuperAdmin', 'seller', 'منصة حصّال');
        Sanctum::actingAs($platformUser);
        $this->postJson('/api/v1/purchase-requests/' . $requestId . '/cancel', [], $this->withTenant($seller))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'BUYER_CANCEL_DENIED');

        Sanctum::actingAs($buyerUser);
        $this->postJson('/api/v1/purchase-requests/' . $requestId . '/cancel', [
            'reason' => 'وجدت مورداً أقرب',
        ], $this->withTenant($buyer))
            ->assertOk()
            ->assertJsonPath('data.status', PurchaseRequest::STATUS_CANCELLED);

        $this->postJson('/api/v1/purchase-requests/' . $requestId . '/cancel', [], $this->withTenant($buyer))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'PURCHASE_REQUEST_NOT_CANCELLABLE');

        // Once the seller has answered by holding stock, leaving is a reservation
        // and deal decision — not an edit to the ask that started it.
        [$askerUser, $asker, $otherSellerUser, $otherSeller, , $otherMatch] = $this->matched();

        $held = $this->ask($askerUser, $asker, $otherMatch->id)->assertCreated()->json('data.id');

        Sanctum::actingAs($otherSellerUser);
        $this->postJson('/api/v1/purchase-requests/' . $held . '/accept', [], $this->withTenant($otherSeller))
            ->assertOk();

        Sanctum::actingAs($askerUser);
        $this->postJson('/api/v1/purchase-requests/' . $held . '/cancel', [], $this->withTenant($asker))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'PURCHASE_REQUEST_NOT_CANCELLABLE');

        // The hold survives the refused withdrawal; nothing leaked.
        $this->assertSame(PurchaseRequest::STATUS_ACCEPTED, PurchaseRequest::find($held)->status);
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_a_match_is_worthless_once_the_offer_leaves_the_market(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, $offer, $match] = $this->matched();

        Sanctum::actingAs($sellerUser);
        $this->putJson('/api/v1/marketplace/offers/' . $offer->id . '/status', [
            'status' => Offer::STATUS_PAUSED,
        ], $this->withTenant($seller))->assertOk();

        $this->ask($buyerUser, $buyer, $match->id)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'OFFER_NOT_AVAILABLE');

        $this->assertSame(DemandMatch::STATUS_INVALIDATED, DemandMatch::find($match->id)->status);
    }

    public function test_an_expired_match_and_an_expired_offer_are_reported_differently(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, $offer, $match] = $this->matched();

        $match->update(['expires_at' => Carbon::now()->subMinute()]);

        $this->ask($buyerUser, $buyer, $match->id)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'MATCH_EXPIRED');

        $this->assertSame(DemandMatch::STATUS_EXPIRED, DemandMatch::find($match->id)->status);

        // Same buyer, fresh match, and this time the offer's own validity ends.
        $offer->update(['expires_at' => Carbon::now()->subSecond()]);

        $demand = $this->demand($buyer, $buyerUser, ['category_id' => $offer->category_id]);
        $fresh = app(DemandMatchingService::class)->matchDemand($demand)->first();

        $requestId = $this->ask($buyerUser, $buyer, $fresh->id)->assertCreated()->json('data.id');

        Sanctum::actingAs($sellerUser);
        $this->postJson('/api/v1/purchase-requests/' . $requestId . '/accept', [], $this->withTenant($seller))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'OFFER_EXPIRED');

        $this->assertDatabaseCount('reservations', 0);
    }
}
