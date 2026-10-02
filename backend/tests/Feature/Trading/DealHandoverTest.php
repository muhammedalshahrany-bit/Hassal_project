<?php

namespace Tests\Feature\Trading;

use App\Trading\Models\Deal;
use App\Trading\Models\DealHandover;
use App\Marketplace\Models\Offer;
use App\Organizations\Models\Organization;
use App\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * The QR code, as a step in the sale rather than a picture on a screen.
 *
 * §4.7 and the sales cycle the brief fixes for HASSAL: the delivery is attested
 * by scanning the order's own code, once, and an order cannot be closed as
 * received until that has happened. What that claim is worth depends entirely on
 * the refusals below holding — a code that could be spent twice, or spent on the
 * wrong order, or spent by someone with no reason to be at the loading bay,
 * would make `COMPLETED` a record of an opinion instead of a record of an event.
 *
 * These tests go through the endpoints rather than the service, because the
 * thing under test is the surface a driver's phone actually talks to.
 */
class DealHandoverTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function show(int $dealId, User $user, Organization $organization)
    {
        Sanctum::actingAs($user);

        return $this->getJson('/api/v1/deals/' . $dealId . '/handover', $this->withTenant($organization));
    }

    /**
     * An accepted order whose goods are on the buyer's dock, with its code.
     *
     * @return array{0: User, 1: Organization, 2: User, 3: Organization, 4: Deal, 5: string}
     */
    protected function atTheDoorstep(): array
    {
        [$buyerUser, $buyer, $sellerUser, $seller, , $deal] = $this->dealInFlight();

        foreach ([Deal::STATUS_ACCEPTED, Deal::STATUS_PROCESSING, Deal::STATUS_DISPATCHED, Deal::STATUS_DELIVERED] as $status) {
            $this->move($deal->id, $status, $sellerUser, $seller)->assertOk();
        }

        return [$buyerUser, $buyer, $sellerUser, $seller, $deal->fresh(), $this->codeOf($deal)];
    }

    protected function codeOf(Deal $deal): string
    {
        return (string) DealHandover::where('deal_id', $deal->id)->value('code');
    }

    public function test_there_is_no_code_to_scan_until_the_seller_accepts_the_order(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, , $deal] = $this->dealInFlight();

        // The order is a request the seller has not signed yet, so it has no
        // delivery to attest. The client needs that as a name, not an empty box.
        $this->show($deal->id, $buyerUser, $buyer)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'HANDOVER_NOT_ISSUED');

        $this->assertSame(0, DealHandover::where('deal_id', $deal->id)->count());

        $this->move($deal->id, Deal::STATUS_ACCEPTED, $sellerUser, $seller)->assertOk();

        // Acceptance issues it, inside the same step — not when a screen asks.
        $this->assertSame(1, DealHandover::where('deal_id', $deal->id)->count());

        $handover = DealHandover::where('deal_id', $deal->id)->first();
        $this->assertSame(DealHandover::STATUS_ACTIVE, $handover->status);
        $this->assertSame($sellerUser->id, (int) $handover->issued_by_user_id);
        $this->assertMatchesRegularExpression('/^[' . DealHandover::ALPHABET . ']{' . DealHandover::CODE_LENGTH . '}$/', $handover->code);

        $this->show($deal->id, $buyerUser, $buyer)
            ->assertOk()
            ->assertJsonPath('data.status', DealHandover::STATUS_ACTIVE)
            ->assertJsonPath('data.deal_number', $deal->deal_number)
            ->assertJsonPath('data.qr_payload', 'HASSAL:HANDOVER:' . $deal->deal_number . ':' . $handover->code)
            // Not yet: nothing has been handed over, and a scan now would be a
            // statement about goods nobody has moved.
            ->assertJsonPath('data.verifiable', false);
    }

    public function test_a_code_is_not_spent_before_the_goods_are_moving(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, , $deal] = $this->dealInFlight();

        $this->move($deal->id, Deal::STATUS_ACCEPTED, $sellerUser, $seller)->assertOk();
        $code = $this->codeOf($deal);

        $this->scan($deal->id, $code, $buyerUser, $buyer)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'HANDOVER_NOT_AT_DELIVERY');

        $this->assertSame(
            DealHandover::STATUS_ACTIVE,
            DealHandover::where('deal_id', $deal->id)->value('status'),
            'A refused scan must not spend anything.'
        );

        // The order is still open, so the same code works when it is delivered.
        $this->move($deal->id, Deal::STATUS_PROCESSING, $sellerUser, $seller)->assertOk();
        $this->move($deal->id, Deal::STATUS_DISPATCHED, $sellerUser, $seller)->assertOk();
        $this->move($deal->id, Deal::STATUS_DELIVERED, $sellerUser, $seller)->assertOk();
        $this->scan($deal->id, $code, $buyerUser, $buyer)->assertCreated();
    }

    public function test_a_code_proves_one_delivery_and_no_more(): void
    {
        [$buyerUser, $buyer, , , $deal, $code] = $this->atTheDoorstep();

        $this->scan($deal->id, $code, $buyerUser, $buyer, ['notes' => 'استلم السائق وليد من المحاسب'])
            ->assertCreated()
            ->assertJsonPath('data.status', DealHandover::STATUS_USED)
            ->assertJsonPath('data.verified_by_organization_id', $buyer->id)
            ->assertJsonPath('data.verification_notes', 'استلم السائق وليد من المحاسب')
            ->assertJsonPath('data.deal_status', Deal::STATUS_DELIVERED);

        $handover = DealHandover::where('deal_id', $deal->id)->first();
        $this->assertNotNull($handover->verified_at);
        $this->assertSame($buyerUser->id, (int) $handover->verified_by_user_id);

        // The second scan is refused with the timestamp of the first, because the
        // person holding the phone needs to hear "this already happened", not
        // "your code is invalid".
        $this->scan($deal->id, $code, $buyerUser, $buyer)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'HANDOVER_ALREADY_USED');

        $this->assertSame(1, DealHandover::where('deal_id', $deal->id)->where('status', DealHandover::STATUS_USED)->count());

        // Once the order is closed there is nothing left for a code to prove, and
        // the answer is still the specific one: this handover happened.
        $this->move($deal->id, Deal::STATUS_COMPLETED, $buyerUser, $buyer)->assertOk();

        $this->scan($deal->id, $code, $buyerUser, $buyer)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'HANDOVER_ALREADY_USED');
    }

    public function test_another_orders_code_does_not_close_this_one(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, , $deal] = $this->dealInFlight();

        // A second order, with its own label: the ordinary case at a dock is two
        // pallets and one phone, and the mix-up this protects against is the
        // driver scanning the wrong sticker.
        [$otherBuyerUser, $otherBuyer, $otherSellerUser, $otherSeller, , $otherDeal] = $this->dealInFlight(10.0);
        $this->move($otherDeal->id, Deal::STATUS_ACCEPTED, $otherSellerUser, $otherSeller)->assertOk();
        $this->move($otherDeal->id, Deal::STATUS_DISPATCHED, $otherSellerUser, $otherSeller)->assertOk();
        $this->move($otherDeal->id, Deal::STATUS_DELIVERED, $otherSellerUser, $otherSeller)->assertOk();
        $otherCode = $this->codeOf($otherDeal);

        $this->move($deal->id, Deal::STATUS_ACCEPTED, $sellerUser, $seller)->assertOk();
        $this->move($deal->id, Deal::STATUS_DISPATCHED, $sellerUser, $seller)->assertOk();
        $this->move($deal->id, Deal::STATUS_DELIVERED, $sellerUser, $seller)->assertOk();

        $this->scan($deal->id, $otherCode, $buyerUser, $buyer)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'HANDOVER_CODE_INVALID');

        // A code that is not this order's and a code that was never issued get the
        // same answer, so the endpoint cannot be used to ask whether some other
        // business has an open delivery.
        $this->scan($deal->id, 'ZZZZZZZZZZZZZZZZ', $buyerUser, $buyer)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'HANDOVER_CODE_INVALID');

        $this->assertSame(
            DealHandover::STATUS_ACTIVE,
            DealHandover::where('deal_id', $deal->id)->value('status')
        );

        $this->move($deal->id, Deal::STATUS_COMPLETED, $buyerUser, $buyer)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'HANDOVER_NOT_VERIFIED');

        // The other order's code is still unspent, as it should be: the wrong scan
        // proved nothing anywhere.
        $this->assertSame(
            DealHandover::STATUS_ACTIVE,
            DealHandover::where('deal_id', $otherDeal->id)->value('status')
        );

        $this->scan($otherDeal->id, $otherCode, $otherBuyerUser, $otherBuyer)->assertCreated();
        $this->move($otherDeal->id, Deal::STATUS_COMPLETED, $otherBuyerUser, $otherBuyer)->assertOk();
    }

    public function test_a_cancelled_order_loses_its_code_with_the_order(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, $offer, $deal] = $this->dealInFlight();

        $this->move($deal->id, Deal::STATUS_ACCEPTED, $sellerUser, $seller)->assertOk();
        $code = $this->codeOf($deal);

        $this->move($deal->id, Deal::STATUS_CANCELLED, $sellerUser, $seller, [
            'notes' => 'أُلغيت الشحنة',
        ])->assertOk();

        $this->assertSame(DealHandover::STATUS_INVALIDATED, DealHandover::where('deal_id', $deal->id)->value('status'));

        // The retired label says so by name, rather than leaving the person at the
        // gate with "we don't know this code" and a live order they can see.
        $this->scan($deal->id, $code, $buyerUser, $buyer)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'HANDOVER_INVALIDATED');

        // The quantity went back to the shelf when the order was called off, and
        // a scan cannot put it forward again.
        $offer->refresh();
        $this->assertSame('100.00', (string) $offer->available_quantity);
        $this->assertSame('0.00', (string) $offer->reserved_quantity);
    }

    /**
     * A code an attested order spent is left spent when the order dies.
     *
     * The retirement rule in the state machine refuses to overwrite a handover
     * that happened: the goods did change hands, and a later cancellation is not
     * a time machine. This is the one case where a cancelled order keeps a live
     * proof, and it is on purpose.
     */
    public function test_an_attested_handover_survives_a_later_cancellation(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, , $deal] = $this->dealInFlight();

        foreach ([Deal::STATUS_ACCEPTED, Deal::STATUS_DISPATCHED, Deal::STATUS_DELIVERED] as $status) {
            $this->move($deal->id, $status, $sellerUser, $seller)->assertOk();
        }

        $code = $this->codeOf($deal);
        $this->scan($deal->id, $code, $buyerUser, $buyer)->assertCreated();

        // The goods were signed for, and then the argument started: a complaint
        // on delivered pallets, a ruling, and the order called off over it.
        $this->move($deal->id, Deal::STATUS_DISPUTED, $buyerUser, $buyer, [
            'notes' => 'وصل نصف الكمية مكسوراً',
        ])->assertOk();

        $this->move($deal->id, Deal::STATUS_RESOLVED, $this->platformUser(), $seller, [
            'notes' => 'حُكم بإلغاء الطلب وإرجاع ما وصل',
        ])->assertOk();

        $this->move($deal->id, Deal::STATUS_CANCELLED, $this->platformUser(), $seller, [
            'notes' => 'أُلغي الحكم بالإلغاء',
        ])->assertOk();

        $this->assertSame(DealHandover::STATUS_USED, DealHandover::where('deal_id', $deal->id)->value('status'));
    }

    public function test_what_a_camera_returns_and_what_a_person_types_are_the_same_act(): void
    {
        [$buyerUser, $buyer, , , $deal, $code] = $this->atTheDoorstep();

        // The printed label carries the deal number so a photo of it years later
        // still names the order it belonged to; the scanner posts the whole line.
        $this->scan($deal->id, 'HASSAL:HANDOVER:' . $deal->deal_number . ':' . $code, $buyerUser, $buyer)
            ->assertCreated()
            ->assertJsonPath('data.status', DealHandover::STATUS_USED);
    }

    public function test_a_code_typed_under_a_bad_light_still_reads(): void
    {
        [$buyerUser, $buyer, , , $deal, $code] = $this->atTheDoorstep();

        // Lower case, a space in the middle, and a hyphen the label's grouping
        // adds. None of these are different codes; the person at the gate is
        // allowed to be wrong about the formatting and right about the pallet.
        $mangled = strtolower(substr($code, 0, 8)) . '-' . strtoupper(substr($code, 8));

        $this->assertNotSame($code, $mangled);

        $this->scan($deal->id, $mangled, $buyerUser, $buyer)
            ->assertCreated()
            ->assertJsonPath('data.status', DealHandover::STATUS_USED);
    }

    public function test_reading_a_code_is_not_the_same_as_spending_it(): void
    {
        [, , $sellerUser, $seller, $deal] = $this->atTheDoorstep();

        // The seller's own copy of the label, before the goods leave: reading is
        // a party act behind `deals.view`, and it changes nothing.
        $this->show($deal->id, $sellerUser, $seller)
            ->assertOk()
            ->assertJsonPath('data.status', DealHandover::STATUS_ACTIVE);

        $this->assertSame(DealHandover::STATUS_ACTIVE, DealHandover::where('deal_id', $deal->id)->value('status'));
        $this->assertNull(DealHandover::where('deal_id', $deal->id)->value('verified_at'));
    }

    public function test_a_business_cannot_attest_a_delivery_it_has_no_part_in(): void
    {
        [$buyerUser, $buyer, , $seller, $deal, $code] = $this->atTheDoorstep();

        [$strangerUser, $stranger] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'محل غريب');

        $this->scan($deal->id, $code, $strangerUser, $stranger)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'DEAL_ACCESS_DENIED');

        $this->show($deal->id, $strangerUser, $stranger)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'DEAL_ACCESS_DENIED');

        // Right company, wrong grant: the finance colleague reads the books and
        // confirms terms, and is not the person standing at the door with a
        // phone. `deals.deliver` is not on their standing.
        $accountant = $this->memberOf($buyer, 'finance');

        $this->scan($deal->id, $code, $accountant, $buyer)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PERMISSION_DENIED');

        $this->assertSame(DealHandover::STATUS_ACTIVE, DealHandover::where('deal_id', $deal->id)->value('status'));

        // And a scan with nothing on it is a validation failure, not a delivery.
        Sanctum::actingAs($buyerUser);
        $this->withHeaders($this->withTenant($buyer))
            ->postJson('/api/v1/deals/' . $deal->id . '/handover/verify', ['code' => ''])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');

        $this->assertSame(DealHandover::STATUS_ACTIVE, DealHandover::where('deal_id', $deal->id)->value('status'));
    }

    /**
     * The gate has to hold at the state machine too, not only at the endpoint.
     *
     * A client that posts `COMPLETED` without ever talking to the scan route is
     * the whole reason this proof exists, so the refusal is asserted from the
     * direction that tries to walk around it.
     */
    public function test_closing_an_order_without_the_scan_is_refused_at_the_state_machine(): void
    {
        [$buyerUser, $buyer, , , $deal, $code] = $this->atTheDoorstep();

        $this->move($deal->id, Deal::STATUS_COMPLETED, $buyerUser, $buyer)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'HANDOVER_NOT_VERIFIED');

        $this->assertSame(Deal::STATUS_DELIVERED, $deal->fresh()->status);

        // The service that answers the scan is the one answer: an order cannot be
        // closed by writing the flag somewhere else, because there is nowhere
        // else the flag is written.
        $this->assertFalse(
            app(\App\Trading\Services\DealStateMachineService::class)->handoverVerified($deal),
            'Nothing has been scanned for this order.'
        );

        $this->scan($deal->id, $code, $buyerUser, $buyer)->assertCreated();

        $this->assertTrue(
            app(\App\Trading\Services\DealStateMachineService::class)->handoverVerified($deal->fresh())
        );
    }
}
