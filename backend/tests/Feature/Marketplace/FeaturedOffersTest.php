<?php

namespace Tests\Feature\Marketplace;

use App\Marketplace\Models\Offer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * The strip at the top of the market, and the two rules it exists to keep.
 *
 * The first is the one every second listing path has to re-learn: a pin is not a
 * pass. `featured_at` says the platform wanted a listing promoted; whether the
 * market may still show it is answered by the same visibility chain the public
 * index applies — status, stock, the expiry date, and the seller's standing. The
 * case that matters is the listing whose date passed while its row still reads
 * ACTIVE, because nothing in this deployment rewrites that status, and a
 * carousel that ranked such a listing first would be the platform's own
 * endorsement of a price nobody can take.
 *
 * The second is who may move it. `offers.moderate` decides whether a listing may
 * be seen; `offers.feature` decides where it sits in front of everyone, and a
 * seller holding either privilege over their own shelf must not hold the other
 * over the market's front door.
 */
class FeaturedOffersTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    /** @return array{0: \App\Identity\Models\User, 1: \App\Organizations\Models\Organization} */
    private function seller(): array
    {
        return $this->accountWithOrganization('SellerAdmin', 'seller', 'مخزن التجزئة');
    }

    private function admin(): \App\Identity\Models\User
    {
        [$admin] = $this->accountWithOrganization('SuperAdmin', 'buyer', 'إدارة المنصة');
        return $admin;
    }

    private function pin(int $offerId, bool $featured = true): \Illuminate\Testing\TestResponse
    {
        // A fresh key per call: these tests pin the same listing twice on
        // purpose, and an idempotency cache that replayed the first answer would
        // be asserting the middleware rather than the pin.
        return $this->putJson("/api/v1/marketplace/offers/{$offerId}/feature", [
            'featured' => $featured,
        ], ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()]);
    }

    private function strip(): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/marketplace/offers/featured');
    }

    public function test_a_pin_is_a_platform_act_and_not_a_sellers(): void
    {
        [$sellerUser, $sellerOrg] = $this->seller();
        $offer = $this->offer($sellerOrg);

        Sanctum::actingAs($sellerUser);
        $this->pin($offer->id)->assertForbidden();
        $this->assertNull($offer->fresh()->featured_at, 'The refused click must not have pinned anything.');

        // A business *standing* — the highest one a company can hold — is not a
        // platform capability, even when the person holding it owns the company.
        $adminOfShelf = $this->memberOf($sellerOrg, 'owner');
        Sanctum::actingAs($adminOfShelf);
        $this->pin($offer->id)->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->pin($offer->id)->assertOk()->assertJsonPath('data.id', $offer->id);
        $this->assertNotNull($offer->fresh()->featured_at);
    }

    public function test_the_strip_carries_only_live_listings_whatever_it_was_pinned_for(): void
    {
        [, $seller] = $this->seller();
        Sanctum::actingAs($this->admin());

        $live     = $this->offer($seller, ['title_ar' => 'أرز مصري', 'expires_at' => now()->addDays(5)]);
        $overdue  = $this->offer($seller, ['title_ar' => 'سكر منتهي', 'expires_at' => now()->subMinute()]);
        $soldOut  = $this->offer($seller, ['title_ar' => 'ذرة نفدت', 'available_quantity' => 0.0]);
        $paused   = $this->offer($seller, ['title_ar' => 'زيت موقوف', 'status' => Offer::STATUS_PAUSED]);
        $unpinned = $this->offer($seller, ['title_ar' => 'دقيق عادي']);

        foreach ([$live, $overdue, $soldOut, $paused] as $offer) {
            // The two refusals below are the second rule; these four rows are
            // pinned straight onto the table because the point here is what the
            // *read* does with a pin that has outlived the listing.
            $offer->forceFill(['featured_at' => now()])->save();
        }
        $this->pin($paused->id)->assertStatus(422)->assertJsonPath('error.code', 'OFFER_NOT_FEATUREABLE');

        $ids = array_column($this->strip()->assertOk()->json('data.offers'), 'id');

        $this->assertContains($live->id, $ids);
        $this->assertNotContains($overdue->id, $ids, 'A pin must not resurrect a listing past its expiry date.');
        $this->assertNotContains($soldOut->id, $ids, 'Nothing to buy is nothing to promote.');
        $this->assertNotContains($paused->id, $ids, 'A seller who hid a listing did not ask to lead the market with it.');
        $this->assertNotContains($unpinned->id, $ids);
    }

    /**
     * The route's own gate is a status check, and a status column is not the
     * whole truth: the listing whose `expires_at` passed still says ACTIVE, so
     * the platform can pin one that is already over. That is allowed on purpose —
     * the date is the fact, and an operator may pin a listing hours before it
     * ends — and the strip above is what keeps it out of sight.
     */
    public function test_a_pinned_listing_disappears_from_the_strip_when_its_date_passes(): void
    {
        [, $seller] = $this->seller();
        Sanctum::actingAs($this->admin());

        $offer = $this->offer($seller, ['expires_at' => now()->addDay()]);
        $this->pin($offer->id)->assertOk();
        $this->assertContains($offer->id, array_column($this->strip()->json('data.offers'), 'id'));

        $offer->forceFill(['expires_at' => now()->subSecond()])->save();

        $this->assertNotContains($offer->id, array_column($this->strip()->json('data.offers'), 'id'));
        // The pin itself stays: it is a record of a decision, and the listing is
        // one date-edit away from being promotable again.
        $this->assertNotNull($offer->fresh()->featured_at);
    }

    public function test_the_newest_pin_leads_and_the_ceiling_is_the_platforms(): void
    {
        $this->setSetting('marketplace.featured_limit', '2', 'integer');
        [, $seller] = $this->seller();
        Sanctum::actingAs($this->admin());

        $first  = $this->offer($seller, ['title_ar' => 'الأول']);
        $second = $this->offer($seller, ['title_ar' => 'الثاني']);
        $third  = $this->offer($seller, ['title_ar' => 'الثالث']);

        $this->pin($first->id)->assertOk();
        $this->travel(1)->minutes();
        $this->pin($second->id)->assertOk();
        $this->travel(1)->minutes();
        $this->pin($third->id)->assertOk();

        $response = $this->strip()->assertOk();
        $ids = array_column($response->json('data.offers'), 'id');

        $this->assertSame([$third->id, $second->id], $ids, 'Newest pin first, and only as many as the platform allows.');
        // Three are pinned and two are shown; a console that could not tell the
        // difference would report a promotion nobody asked for.
        $response->assertJsonPath('data.total_featured', 3)->assertJsonPath('data.limit', 2);

        // Re-pinning moves a listing to the front rather than being a no-op.
        $this->travel(1)->minutes();
        $this->pin($first->id)->assertOk();
        $this->assertSame($first->id, array_column($this->strip()->json('data.offers'), 'id')[0]);
    }

    public function test_removing_a_pin_takes_the_listing_back_out_of_the_strip(): void
    {
        [, $seller] = $this->seller();
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $offer = $this->offer($seller, ['title_ar' => 'مسلى']);
        $this->pin($offer->id)->assertOk();
        $this->assertContains($offer->id, array_column($this->strip()->json('data.offers'), 'id'));

        $this->pin($offer->id, false)->assertOk()->assertJsonPath('data.featured_at', null);
        $this->assertNotContains($offer->id, array_column($this->strip()->json('data.offers'), 'id'));
        $this->assertNull($offer->fresh()->featured_by, 'Who pinned it stops being an answer once nobody has.');
    }

    public function test_the_account_behind_a_pin_is_visible_to_those_who_can_place_one(): void
    {
        [, $seller] = $this->seller();
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $offer = $this->offer($seller, ['title_ar' => 'شاي معلّق']);
        $this->pin($offer->id)->assertOk();

        // The account that placed the pin reads it back: a console that cannot
        // say who promoted a listing has no audit to show anyone.
        $this->getJson('/api/v1/marketplace/offers/' . $offer->id)
            ->assertOk()
            ->assertJsonPath('data.featured_by.id', $admin->id)
            ->assertJsonStructure(['data' => ['featured_by' => ['id', 'name']]]);
    }

    /**
     * The same read by someone holding no token at all.
     *
     * The pin is placed straight on the row rather than through the route
     * because this test must never be authenticated: `Sanctum::actingAs()` is a
     * switch for the whole test, not a header per request, so a test that asked
     * the platform to pin something could not then ask as a stranger.
     *
     * The fact of promotion is public — a market that lifts listings without
     * saying so is advertising dressed as a shelf — and the employee behind it
     * is not.
     */
    public function test_a_visitor_sees_the_pin_and_not_the_person_who_placed_it(): void
    {
        [, $seller] = $this->seller();
        $offer = $this->offer($seller, ['title_ar' => 'شاي معلّق']);
        $offer->forceFill(['featured_at' => now(), 'featured_by' => $this->admin()->id])->save();

        $this->getJson('/api/v1/marketplace/offers/' . $offer->id)
            ->assertOk()
            ->assertJsonPath('data.id', $offer->id)
            ->assertJsonPath('data.featured_at', fn ($value) => is_string($value))
            ->assertJsonMissingPath('data.featured_by');

        $this->getJson('/api/v1/marketplace/offers/featured')
            ->assertOk()
            ->assertJsonPath('data.offers.0.id', $offer->id)
            ->assertJsonMissingPath('data.offers.0.featured_by');
    }
}

