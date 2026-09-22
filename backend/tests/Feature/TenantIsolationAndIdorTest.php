<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\PurchaseRequest;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * Can organization B reach organization A's records by guessing an id?
 *
 * This is the question `SECURITY.md` §1 answers with prose and a middleware
 * description. Prose is not evidence: permissions say "may a SellerAdmin edit an
 * offer", and every one of these routes grants that to both businesses. The only
 * thing that keeps a seller from editing the *rival's* offer is a per-record
 * ownership test inside the controller, and until this file nothing proved those
 * checks ran, on every door, in the order that refuses before it answers.
 *
 * Each test therefore builds two real businesses, puts a real record in one, and
 * attacks it from the other with a session that holds the correct permission.
 * A 422 would be a false pass — the request never reached the ownership question
 * — so payloads are valid and the assertion is about the refusal.
 */
class TenantIsolationAndIdorTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    /**
     * Two seller businesses, each with an owner holding every seller capability.
     *
     * @return array{0: array{0: User, 1: Organization}, 1: array{0: User, 1: Organization}}
     */
    protected function twoSellers(): array
    {
        return [
            $this->accountWithOrganization('SellerAdmin', 'seller', 'منشأة الأرقام'),
            $this->accountWithOrganization('SellerAdmin', 'seller', 'منشأة المنافس'),
        ];
    }

    public function test_a_rival_seller_cannot_edit_another_business_offer(): void
    {
        [$victim, $victimOrg] = $this->twoSellers()[0];
        [$attacker, $attackerOrg] = $this->twoSellers()[1];

        $offer = $this->offer($victimOrg, ['status' => Offer::STATUS_DRAFT]);
        $untouchedTitle = $offer->title_ar;

        Sanctum::actingAs($attacker);
        // The attacker's own session and own tenant, aimed at the victim's row id.
        $this->withHeaders($this->withTenant($attackerOrg))
            ->putJson("/api/v1/marketplace/offers/{$offer->id}", [
                'title_ar' => 'تم الاستيلاء',
                'warehouse_id' => $this->warehouse($attackerOrg)->id,
                'product_variant_id' => $this->productVariant($attackerOrg)->id,
                'opportunity_reason' => Offer::REASON_SURPLUS_STOCK,
                'total_quantity' => 50,
                'unit' => 'carton',
                'unit_price' => 1.0,
                'currency_code' => 'YER',
                'min_order_quantity' => 1,
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'UNAUTHORIZED_OFFER_ACTION');

        $this->assertSame($untouchedTitle, $offer->fresh()->title_ar);
        $this->assertStringNotContainsString('تم الاستيلاء', (string) $offer->fresh()->title_ar);
    }

    public function test_a_rival_seller_cannot_delete_another_business_draft(): void
    {
        [$victim, $victimOrg] = $this->twoSellers()[0];
        [$attacker, $attackerOrg] = $this->twoSellers()[1];

        $offer = $this->offer($victimOrg, ['status' => Offer::STATUS_DRAFT]);

        Sanctum::actingAs($attacker);
        $this->withHeaders($this->withTenant($attackerOrg))
            ->deleteJson("/api/v1/marketplace/offers/{$offer->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('offers', ['id' => $offer->id]);
    }

    public function test_a_rival_cannot_read_a_deal_it_is_not_party_to(): void
    {
        [$seller, $sellerOrg] = $this->twoSellers()[0];
        [$attacker, $attackerOrg] = $this->twoSellers()[1];

        $buyer = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'منشأة الشراء');
        $offer = $this->offer($sellerOrg);
        $deal = $this->deal($offer, $buyer[1], $buyer[0]);

        Sanctum::actingAs($attacker);
        $response = $this->withHeaders($this->withTenant($attackerOrg))
            ->getJson("/api/v1/deals/{$deal->id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'DEAL_ACCESS_DENIED');

        // The deal number is what a successful read returns; making sure it is
        // not in the refusal body is what "refuse without leaking" means.
        $this->assertStringNotContainsString($deal->deal_number, $response->getContent());
    }

    public function test_a_rival_cannot_move_another_partys_deal_through_the_state_machine(): void
    {
        [$seller, $sellerOrg] = $this->twoSellers()[0];
        [$attacker, $attackerOrg] = $this->twoSellers()[1];

        $buyer = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'منشأة الشراء');
        $offer = $this->offer($sellerOrg);
        $deal = $this->deal($offer, $buyer[1], $buyer[0], ['status' => Deal::STATUS_PENDING_SELLER_APPROVAL]);

        Sanctum::actingAs($attacker);
        $this->withHeaders($this->withTenant($attackerOrg))
            ->postJson("/api/v1/deals/{$deal->id}/transition", ['to_status' => Deal::STATUS_ACCEPTED])
            ->assertStatus(403);

        $this->assertSame(Deal::STATUS_PENDING_SELLER_APPROVAL, $deal->fresh()->status);
        $this->assertDatabaseMissing('deal_status_histories', [
            'deal_id' => $deal->id,
            'to_status' => Deal::STATUS_ACCEPTED,
        ]);
    }

    public function test_a_rival_cannot_cancel_a_reservation_it_does_not_hold(): void
    {
        [, $sellerOrg] = $this->twoSellers()[0];

        $victim = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'منشأة الشراء');
        // A second buyer, holding the same `requests.cancel` the route asks for,
        // so a refusal can only come from ownership and not from the guard.
        [$attacker, $attackerOrg] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'منشأة منافسة');

        $offer = $this->offer($sellerOrg);
        $demand = $this->demand($victim[1], $victim[0]);
        $this->purchaseRequest($demand, $offer, $victim[0]);
        $reservation = $this->reserveDirectly($offer, $victim[0], $victim[1]);

        Sanctum::actingAs($attacker);
        $this->withHeaders($this->withTenant($attackerOrg))
            ->postJson("/api/v1/marketplace/reservations/{$reservation->id}/cancel", ['reason' => 'not mine'])
            ->assertStatus(404);

        $this->assertSame(
            (float) $offer->fresh()->reserved_quantity,
            (float) $reservation->quantity,
            'The attack released stock it did not hold.'
        );
        $this->assertSame(Reservation::STATUS_ACTIVE, $reservation->fresh()->status);
    }

    public function test_a_rival_cannot_approve_a_purchase_request_addressed_to_another_seller(): void
    {
        [$seller, $sellerOrg] = $this->twoSellers()[0];
        [$attacker, $attackerOrg] = $this->twoSellers()[1];

        $buyer = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'منشأة الشراء');
        $offer = $this->offer($sellerOrg);
        $demand = $this->demand($buyer[1], $buyer[0]);
        $request = $this->purchaseRequest($demand, $offer, $buyer[0]);

        Sanctum::actingAs($attacker);
        $this->withHeaders($this->withTenant($attackerOrg))
            ->postJson("/api/v1/purchase-requests/{$request->id}/accept")
            ->assertStatus(403);

        $this->assertSame(PurchaseRequest::STATUS_REQUESTED, $request->fresh()->status);
        $this->assertSame(
            0.0,
            (float) $offer->fresh()->reserved_quantity,
            'The forged acceptance moved stock.'
        );
    }

    public function test_a_rival_cannot_rename_another_business_warehouse(): void
    {
        [$victim, $victimOrg] = $this->twoSellers()[0];
        [$attacker, $attackerOrg] = $this->twoSellers()[1];

        $warehouse = $this->warehouse($victimOrg);

        Sanctum::actingAs($attacker);
        $this->withHeaders($this->withTenant($attackerOrg))
            ->putJson("/api/v1/warehouses/{$warehouse->id}", [
                'name' => 'مستودع مسروق',
                'code' => 'STOLEN-1',
                'city' => self::CITY,
                'address_line' => 'شارع ما',
            ])
            ->assertStatus(404);

        // 404 rather than 403 because the lookup is scoped by organization: the
        // rival's warehouse does not exist for this session at all.
        $this->assertNotSame('مستودع مسروق', $warehouse->fresh()->name);
    }

    public function test_a_business_cannot_read_the_audit_trail_of_another_business(): void
    {
        [$victim, $victimOrg] = $this->twoSellers()[0];
        [$attacker, $attackerOrg] = $this->twoSellers()[1];

        // `audit.view` is held by business roles too, so the isolation here is
        // not a permission question — it is the tenant scoping in the route.
        // One real HTTP write gives the victim an audit row to be stolen.
        Sanctum::actingAs($victim);
        $draft = $this->offer($victimOrg, ['status' => Offer::STATUS_DRAFT]);
        $this->withHeaders($this->withTenant($victimOrg))
            ->deleteJson("/api/v1/marketplace/offers/{$draft->id}")
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $victimOrg->id,
            'action' => "DELETE api/v1/marketplace/offers/{$draft->id}",
        ]);

        Sanctum::actingAs($attacker);
        $rows = $this->withHeaders($this->withTenant($attackerOrg))
            ->getJson('/api/v1/audit-logs?per_page=100')
            ->assertOk()
            ->json('data');

        foreach ($rows as $row) {
            $this->assertNotSame(
                $victimOrg->id,
                $row['organization_id'] ?? null,
                'The rival read an audit row belonging to another business.'
            );
        }

        $this->assertSame([], array_filter($rows, fn ($r) => ($r['organization_id'] ?? null) === $victimOrg->id));
    }

    public function test_the_same_user_in_two_businesses_sees_only_the_selected_tenant_offers(): void
    {
        [$user, $orgA] = $this->accountWithOrganization('SellerAdmin', 'seller', 'منشأة أ');
        $orgB = $this->organization('seller', 'منشأة ب');
        $this->membership($orgB, $user, 'admin');

        $offerA = $this->offer($orgA);
        $offerB = $this->offer($orgB);

        Sanctum::actingAs($user);

        $mineA = $this->withHeaders($this->withTenant($orgA))->getJson('/api/v1/marketplace/my-offers')
            ->assertOk()->json('data');
        $idsA = array_column($mineA, 'id');
        $this->assertContains($offerA->id, $idsA);
        $this->assertNotContains($offerB->id, $idsA, 'The seller shelf crossed into the other business.');

        $mineB = $this->withHeaders($this->withTenant($orgB))->getJson('/api/v1/marketplace/my-offers')
            ->assertOk()->json('data');
        $this->assertContains($offerB->id, array_column($mineB, 'id'));
    }

    public function test_a_non_member_cannot_place_a_foreign_organization_in_context(): void
    {
        [, $victimOrg] = $this->twoSellers()[0];
        [$attacker, $attackerOrg] = $this->twoSellers()[1];

        Sanctum::actingAs($attacker);

        // The header is the whole tenancy mechanism. If it accepted a foreign id
        // the per-record checks would be the only thing left standing.
        $this->withHeaders($this->withTenant($victimOrg))->getJson('/api/v1/marketplace/my-offers')->assertStatus(403);
    }
}
