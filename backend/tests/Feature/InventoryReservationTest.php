<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Offer;
use App\Models\PurchaseRequest;
use App\Models\Reservation;
use App\Models\Organization;
use App\Models\User;
use App\Services\SystemSettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * Inventory that is promised is inventory that is gone — and only the seller
 * promises it.
 *
 * §19-§26: the buyer's first write against an offer is a *request*, and the
 * quantity leaves the shelf when the seller accepts. `POST /marketplace/
 * reservations` used to let a buyer move `reserved_quantity` on someone else's
 * listing without ever being answered, so it is gone and every hold below is
 * produced by the review path. The assertions did not get weaker for it: the
 * service repeats each gate inside the row lock, so the acceptance is checked
 * against the shelf as it is at that moment, not as it was when the ask arrived.
 *
 * These tests assert the counters and the rows, not the status codes, and one of
 * them asks MySQL itself whether the offer row is really locked while two claims
 * collide.
 */
class InventoryReservationTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    /** The account that owns a business, as the fixtures created it. */
    protected function ownerOf(Organization $organization): User
    {
        return $organization->memberships()->where('role', 'owner')->firstOrFail()->user;
    }

    /** A buyer's ask against a listing, as the marketplace fast path raises it. */
    protected function ask(User $buyerUser, Organization $buyer, Offer $offer, float $quantity, array $headers = []): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($buyerUser);

        return $this->postJson('/api/v1/purchase-requests', [
            'offer_id' => $offer->id,
            'requested_quantity' => $quantity,
        ], $headers + $this->withTenant($buyer));
    }

    /**
     * Ask, then have the seller answer.
     *
     * Returns both responses so a test can assert on whichever step the rule it
     * checks belongs to: the ask refuses a buyer's impossibility, the acceptance
     * refuses a shelf that changed underneath the ask.
     *
     * @return array{ask: \Illuminate\Testing\TestResponse, accepted: ?\Illuminate\Testing\TestResponse}
     */
    protected function hold(User $buyerUser, Organization $buyer, Offer $offer, float $quantity, array $headers = []): array
    {
        $ask = $this->ask($buyerUser, $buyer, $offer, $quantity, $headers);

        if ($ask->status() !== 201) {
            return ['ask' => $ask, 'accepted' => null];
        }

        $seller = $offer->seller;

        Sanctum::actingAs($this->ownerOf($seller));
        $accepted = $this->postJson(
            '/api/v1/purchase-requests/' . $ask->json('data.id') . '/accept',
            [],
            $this->withTenant($seller)
        );

        return ['ask' => $ask, 'accepted' => $accepted];
    }

    /** The reservation a successful review produced. */
    protected function heldReservation(array $chain): Reservation
    {
        $chain['accepted']->assertOk();

        $reservation = Reservation::find($chain['accepted']->json('data.reservation.id'));
        $this->assertNotNull($reservation, 'The acceptance reported a hold that was never stored.');

        return $reservation;
    }

    /**
     * The invariant the schema enforces and the service must never break.
     */
    protected function assertShelfBalances(Offer $offer): void
    {
        $this->assertEqualsWithDelta(
            (float) $offer->total_quantity,
            (float) $offer->available_quantity + (float) $offer->reserved_quantity + (float) $offer->sold_quantity,
            0.001,
            'The offer claims more or less stock than it has accounted for.'
        );
    }

    public function test_an_ask_holds_nothing_until_the_seller_answers(): void
    {
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'محل');
        [, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد');

        $offer = $this->offer($seller);

        $ask = $this->ask($buyerUser, $buyer, $offer, 40);

        $ask->assertCreated()
            ->assertJsonPath('data.status', PurchaseRequest::STATUS_REQUESTED)
            ->assertJsonPath('data.reservation_id', null);

        // The whole point of the review: asking is not taking.
        $offer->refresh();
        $this->assertSame('100.00', (string) $offer->available_quantity);
        $this->assertSame('0.00', (string) $offer->reserved_quantity);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_a_hold_records_what_it_claims_and_remembers_the_price(): void
    {
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'محل');
        [, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد');

        $offer = $this->offer($seller);

        $reservation = $this->heldReservation($this->hold($buyerUser, $buyer, $offer, 40));

        $this->assertSame('40.00', (string) $reservation->quantity);

        // The deal terms are frozen on the hold, not read from the offer later.
        $this->assertSame('25000.0000', (string) $reservation->unit_price);
        $this->assertSame('1000000.0000', (string) $reservation->total_amount);
        $this->assertSame('YER', $reservation->currency_code);
        $this->assertMatchesRegularExpression('/^RES-\d{6}-[A-Z0-9]{8}$/', $reservation->reservation_number);

        // The hold has a deadline, and its length comes from the setting an
        // admin edits — not a number written into a service class.
        $timeoutMinutes = (int) app(SystemSettingsService::class)->get('reservation.timeout', 0);
        $this->assertGreaterThan(0, $timeoutMinutes, 'The hold length must come from configuration.');
        $this->assertTrue($reservation->expires_at->between(
            now()->addMinutes($timeoutMinutes - 1),
            now()->addMinutes($timeoutMinutes + 1)
        ), "The hold was given {$reservation->expires_at->diffForHumans()} instead of the configured {$timeoutMinutes} minutes.");

        $offer->refresh();
        $this->assertSame('60.00', (string) $offer->available_quantity);
        $this->assertSame('40.00', (string) $offer->reserved_quantity);
        $this->assertSame(Offer::STATUS_ACTIVE, $offer->status);
        $this->assertShelfBalances($offer);

        // The buyer sees their own hold in the list.
        Sanctum::actingAs($buyerUser);
        $this->getJson('/api/v1/marketplace/reservations', $this->withTenant($buyer))
            ->assertOk()
            ->assertJsonPath('data.0.id', $reservation->id);
    }

    public function test_stock_that_is_not_there_cannot_be_asked_for(): void
    {
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'محل');
        [, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد');

        $offer = $this->offer($seller);

        $this->ask($buyerUser, $buyer, $offer, 120)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'QUANTITY_EXCEEEDS_AVAILABLE');

        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('purchase_requests', 0);
        $offer->refresh();
        $this->assertSame('100.00', (string) $offer->available_quantity);
        $this->assertSame('0.00', (string) $offer->reserved_quantity);
    }

    public function test_a_shelf_that_emptied_after_the_ask_is_not_held_by_the_acceptance(): void
    {
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'محل');
        [$sellerUser, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد');
        [$otherBuyerUser, $otherBuyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'محل آخر');

        $offer = $this->offer($seller, ['total_quantity' => 100]);

        // Two buyers ask for the same last 60 units; both asks are legal because
        // neither takes anything. The seller deciding who to hold for is the
        // only thing that can make the second one impossible.
        foreach ([[$buyerUser, $buyer], [$otherBuyerUser, $otherBuyer]] as [$askerUser, $organization]) {
            $this->ask($askerUser, $organization, $offer, 60)->assertCreated();
        }

        $firstAsk = PurchaseRequest::where('buyer_organization_id', $buyer->id)->firstOrFail();
        $secondAsk = PurchaseRequest::where('buyer_organization_id', $otherBuyer->id)->firstOrFail();

        Sanctum::actingAs($sellerUser);
        $this->postJson('/api/v1/purchase-requests/' . $firstAsk->id . '/accept', [], $this->withTenant($seller))
            ->assertOk();

        // The shelf now has 40 left, so honouring the second ask would either
        // overbook it or quietly shrink the quantity the buyer asked for.
        $this->postJson('/api/v1/purchase-requests/' . $secondAsk->id . '/accept', [], $this->withTenant($seller))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'PURCHASE_REQUEST_ACCEPT_FAILED');

        $offer->refresh();
        $this->assertSame('40.00', (string) $offer->available_quantity);
        $this->assertSame('60.00', (string) $offer->reserved_quantity);
        $this->assertShelfBalances($offer);
        $this->assertSame(1, Reservation::count(), 'The refused acceptance created a hold.');
        $secondAsk->refresh();
        $this->assertSame(PurchaseRequest::STATUS_REQUESTED, $secondAsk->status, 'A refused acceptance answered the buyer as if it had.');
    }

    public function test_a_line_below_the_sellers_minimum_is_not_opened(): void
    {
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'محل');
        [, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد');

        $offer = $this->offer($seller, ['min_order_quantity' => 25]);

        $this->ask($buyerUser, $buyer, $offer, 10)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'QUANTITY_BELOW_MIN_ORDER');

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_a_seller_cannot_raise_an_ask_against_their_own_shelf(): void
    {
        [$sellerUser, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد');
        $offer = $this->offer($seller);

        // It looks like a buyer with a valid organization. It is the seller
        // moving their own stock in a circle, which is not a transaction.
        $this->ask($sellerUser, $seller, $offer, 10)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'SELF_PURCHASE_FORBIDDEN');

        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('purchase_requests', 0);
    }

    public function test_a_withdrawn_listing_cannot_be_asked_for_through_the_back_door(): void
    {
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'محل');
        [$sellerUser, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد');

        $offer = $this->offer($seller);

        Sanctum::actingAs($sellerUser);
        $this->putJson('/api/v1/marketplace/offers/' . $offer->id . '/status', [
            'status' => Offer::STATUS_PAUSED,
        ], $this->withTenant($seller))->assertOk();

        // The buyer has the id from before the pause; knowing an id is not a
        // right to the stock behind it.
        $this->ask($buyerUser, $buyer, $offer, 10)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'OFFER_NOT_TRADABLE');

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_an_ask_is_not_honoured_when_the_seller_was_stopped(): void
    {
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'محل');
        [$sellerUser, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد');

        $offer = $this->offer($seller);

        $ask = $this->ask($buyerUser, $buyer, $offer, 40)->assertCreated();

        // The platform stops the business after the ask arrived. A hold made
        // now would be a transaction the platform has forbidden.
        $seller->forceFill(['status' => Organization::STATUS_SUSPENDED])->save();

        Sanctum::actingAs($sellerUser);
        $this->postJson('/api/v1/purchase-requests/' . $ask->json('data.id') . '/accept', [], $this->withTenant($seller))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'ORGANIZATION_SUSPENDED');

        $this->assertDatabaseCount('reservations', 0);

        // The same stop applies on the way in: a buyer cannot open a new ask
        // against a business the platform has suspended.
        $this->ask($buyerUser, $buyer, $offer, 20)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'SELLER_NOT_TRADING');
    }

    public function test_the_database_waits_for_the_first_claim_to_finish(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Row-level locking is asserted against MySQL, the engine this product runs on.');
        }

        [, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد');
        $offer = $this->offer($seller, ['total_quantity' => 10]);

        // This test's own connection is inside the transaction RefreshDatabase
        // opened, and it holds the offer row. A second, independent connection
        // must not be able to read it for update until the first one is done —
        // that wait is what stops two acceptances taking the same 10 cartons.
        DB::table('offers')->where('id', $offer->id)->lockForUpdate()->get();

        config(['database.connections.hassal_racer' => config('database.connections.' . config('database.default'))]);
        $racer = DB::connection('hassal_racer');
        $racer->statement('SET SESSION innodb_lock_wait_timeout = 1');

        $blocked = false;
        $startedAt = microtime(true);

        try {
            $racer->beginTransaction();
            $racer->table('offers')->where('id', $offer->id)->lockForUpdate()->get();
        } catch (QueryException $e) {
            $blocked = str_contains($e->getMessage(), 'Lock wait timeout');
        } finally {
            $waited = microtime(true) - $startedAt;
            $racer->rollBack();
            DB::purge('hassal_racer');
        }

        $this->assertTrue($blocked, 'A second connection read the offer row for update without waiting.');
        $this->assertGreaterThanOrEqual(0.9, $waited, 'The wait was too short to be a real lock contention.');

        // Nothing was double-counted while the second connection was waiting.
        $this->assertSame('10.00', (string) Offer::find($offer->id)->available_quantity);
    }

    public function test_cancelling_returns_the_stock_once_and_only_once(): void
    {
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'محل');
        [, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد');

        $offer = $this->offer($seller);
        $reservation = $this->heldReservation($this->hold($buyerUser, $buyer, $offer, 40));

        Sanctum::actingAs($buyerUser);
        $this->postJson('/api/v1/marketplace/reservations/' . $reservation->id . '/cancel', [
            'reason' => 'لن أكمّل الصفقة',
        ], $this->withTenant($buyer))
            ->assertOk()
            ->assertJsonPath('data.status', Reservation::STATUS_CANCELLED);

        $offer->refresh();
        $this->assertSame('100.00', (string) $offer->available_quantity);
        $this->assertSame('0.00', (string) $offer->reserved_quantity);
        $this->assertShelfBalances($offer);

        $cancelled = Reservation::find($reservation->id);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame('لن أكمّل الصفقة', $cancelled->cancellation_reason);

        // A second cancellation must not put the stock back a second time, and
        // must not be reported as if it had done something.
        $this->postJson('/api/v1/marketplace/reservations/' . $reservation->id . '/cancel', [], $this->withTenant($buyer))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'RESERVATION_NOT_ACTIVE')
            ->assertJsonPath('error.details.status', Reservation::STATUS_CANCELLED);

        $offer->refresh();
        $this->assertSame('100.00', (string) $offer->available_quantity);
        $this->assertSame('0.00', (string) $offer->reserved_quantity);
        $this->assertShelfBalances($offer);
    }

    public function test_an_unanswered_hold_expires_and_the_shelf_reopens(): void
    {
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'محل');
        [, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد');

        $offer = $this->offer($seller);
        $reservation = $this->heldReservation($this->hold($buyerUser, $buyer, $offer, 40));

        // A hold the buyer left inside a running offer must come back to market
        // on its own; otherwise a shelf dies from a purchase nobody made.
        Reservation::where('id', $reservation->id)->update(['expires_at' => now()->subMinutes(5)]);

        $this->artisan('app:expire-reservations')->assertSuccessful();

        $expired = Reservation::find($reservation->id);
        $this->assertSame(Reservation::STATUS_EXPIRED, $expired->status);

        // A timeout is not somebody's cancellation: writing it into
        // cancelled_at would leave a history that claims a decision nobody made.
        $this->assertNull($expired->cancelled_at);
        $this->assertNull($expired->cancellation_reason);

        $offer->refresh();
        $this->assertSame('100.00', (string) $offer->available_quantity);
        $this->assertSame(Offer::STATUS_ACTIVE, $offer->status);
        $this->assertShelfBalances($offer);

        // Sweeping twice must not restock twice.
        $this->assertSame(0, app(\App\Services\InventoryReservationService::class)->sweepExpiredReservations());
        $offer->refresh();
        $this->assertSame('100.00', (string) $offer->available_quantity);
    }

    public function test_a_hold_still_inside_its_grace_window_is_left_alone(): void
    {
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'محل');
        [, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد');

        $offer = $this->offer($seller);
        $reservation = $this->heldReservation($this->hold($buyerUser, $buyer, $offer, 40));

        // The sweep runs every minute. A hold that crossed its deadline seconds
        // ago may belong to a buyer completing a deal right now.
        Reservation::where('id', $reservation->id)->update(['expires_at' => now()->subSeconds(10)]);

        $this->assertSame(0, app(\App\Services\InventoryReservationService::class)->sweepExpiredReservations());

        $this->assertSame(Reservation::STATUS_ACTIVE, Reservation::find($reservation->id)->status);
        $offer->refresh();
        $this->assertSame('60.00', (string) $offer->available_quantity);
    }

    public function test_an_expired_hold_cannot_be_turned_into_a_deal(): void
    {
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'محل');
        [, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد');

        $offer = $this->offer($seller);
        $reservation = $this->heldReservation($this->hold($buyerUser, $buyer, $offer, 40));

        Reservation::where('id', $reservation->id)->update(['expires_at' => now()->subMinute()]);

        Sanctum::actingAs($buyerUser);
        $this->postJson('/api/v1/deals', [
            'reservation_id' => $reservation->id,
            'delivery_method' => 'PICKUP',
        ], $this->withTenant($buyer))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'RESERVATION_EXPIRED');

        $this->assertDatabaseCount('deals', 0);
        $this->assertSame(Reservation::STATUS_ACTIVE, Reservation::find($reservation->id)->status);
    }

    public function test_a_replayed_ask_asks_once(): void
    {
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'محل');
        [, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد');

        $offer = $this->offer($seller);
        $key = 'idem-' . uniqid();

        $first = $this->ask($buyerUser, $buyer, $offer, 40, ['X-Idempotency-Key' => $key])->assertCreated();

        // A client that retries after a lost response must not ask twice, which
        // would leave the seller deciding between two records for one intention.
        $second = $this->ask($buyerUser, $buyer, $offer, 40, ['X-Idempotency-Key' => $key])
            ->assertCreated()
            ->assertHeader('X-Idempotency-Replay', 'true');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('purchase_requests', 1);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_the_last_of_a_shelf_goes_to_one_buyer_and_can_come_back(): void
    {
        [$buyerA, $orgA] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'محل أ');
        [$buyerB, $orgB] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'محل ب');
        [$sellerUser, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد');

        $offer = $this->offer($seller, ['total_quantity' => 100]);

        $chain = $this->hold($buyerA, $orgA, $offer, 100);
        $reservation = $this->heldReservation($chain);

        // Two claims on one pallet. The first emptied the listing, so the second
        // buyer's ask is refused because the listing is closed — not because the
        // platform is holding a queue nobody agreed to keep.
        $this->ask($buyerB, $orgB, $offer, 100)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'OFFER_NOT_TRADABLE')
            ->assertJsonPath('error.details.status', Offer::STATUS_SOLD_OUT);

        $offer->refresh();
        $this->assertSame('0.00', (string) $offer->available_quantity);
        $this->assertSame(Offer::STATUS_SOLD_OUT, $offer->status);
        $this->assertDatabaseCount('reservations', 1);

        // When the first buyer walks away, the shelf is genuinely market again.
        Sanctum::actingAs($buyerA);
        $this->postJson('/api/v1/marketplace/reservations/' . $reservation->id . '/cancel', [], $this->withTenant($orgA))
            ->assertOk();

        $this->hold($buyerB, $orgB, $offer->refresh(), 100);

        $offer->refresh();
        $this->assertSame('0.00', (string) $offer->available_quantity);
        $this->assertSame('100.00', (string) $offer->reserved_quantity);
        $this->assertShelfBalances($offer);
    }

    public function test_a_deal_converts_the_hold_instead_of_releasing_it(): void
    {
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'محل');
        [, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد');

        $offer = $this->offer($seller);
        $reservation = $this->heldReservation($this->hold($buyerUser, $buyer, $offer, 40));

        Sanctum::actingAs($buyerUser);
        $this->postJson('/api/v1/deals', [
            'reservation_id' => $reservation->id,
            'delivery_method' => 'PICKUP',
        ], $this->withTenant($buyer))
            ->assertCreated()
            ->assertJsonPath('data.status', Deal::STATUS_PENDING_SELLER_APPROVAL);

        $reservation = Reservation::find($reservation->id);
        $this->assertSame(Reservation::STATUS_CONVERTED_TO_DEAL, $reservation->status);
        $this->assertNotNull($reservation->converted_at);
        $this->assertNull($reservation->cancelled_at);

        // The stock stays held while the seller decides; a deal is not a release.
        $offer->refresh();
        $this->assertSame('60.00', (string) $offer->available_quantity);
        $this->assertSame('40.00', (string) $offer->reserved_quantity);

        // The same hold cannot fund a second deal.
        $this->postJson('/api/v1/deals', [
            'reservation_id' => $reservation->id,
            'delivery_method' => 'PICKUP',
        ], $this->withTenant($buyer))->assertStatus(404);

        $this->assertDatabaseCount('deals', 1);
    }
}
