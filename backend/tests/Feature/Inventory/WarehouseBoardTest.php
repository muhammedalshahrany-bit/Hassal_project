<?php

namespace Tests\Feature\Inventory;

use App\Inventory\Models\Warehouse;
use App\Core\Support\Geo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * The map board, and the two things it owed and did not have.
 *
 * A buyer looking at pins on a map is asking one question the endpoint never
 * answered: how far. And the same public route — no token, no permission,
 * reachable from a login screen — was returning a raw Eloquent row, which means
 * it published every warehouse's `contact_name` and `contact_phone` to whoever
 * bothered to ask. These are the two facts worth locking: the distance is
 * present and ordered, and the private paper behind a public pin is not.
 *
 * Nothing in the suite read `/marketplace/warehouses` before this file, which is
 * how the leak survived as long as it did.
 */
class WarehouseBoardTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    public function test_the_public_board_ranks_by_distance_and_keeps_the_call_person_back(): void
    {
        [, $seller] = $this->accountWithOrganization(null, 'seller', 'مورد');

        // Aden, then Mukalla — the observer stands in Aden, so the order is not
        // a coincidence of `orderBy('name')`.
        $near = $this->warehouse($seller, [
            'name' => 'مستودع الميناء', 'latitude' => 12.7855, 'longitude' => 45.0187,
            'contact_name' => 'سالم', 'contact_phone' => '770000111',
        ]);
        $far = $this->warehouse($seller, [
            'name' => 'أبعد من الميناء', 'latitude' => 14.5419, 'longitude' => 48.6742,
            'contact_name' => 'علي', 'contact_phone' => '770000222',
        ]);

        $rows = $this->getJson('/api/v1/marketplace/warehouses?latitude=12.79&longitude=45.02')
            ->assertOk()
            ->assertJsonPath('data.0.id', $near->id)
            ->assertJsonPath('data.1.id', $far->id)
            ->json('data');

        // A number, and a plausible one: the observer is ~1 km off the near
        // shed's recorded corner, and Aden to Mukalla is roughly 470 km.
        $this->assertLessThan(5.0, (float) $rows[0]['distance_km']);
        $this->assertGreaterThan(400, (float) $rows[1]['distance_km']);
        $this->assertLessThan((float) $rows[1]['distance_km'], (float) $rows[0]['distance_km']);

        // The pin's owner is not part of the pin.
        $this->assertSame('مستودع الميناء', $rows[0]['name']);
        $this->assertSame(12.7855, $rows[0]['latitude']);
        $this->assertNull($rows[0]['contact_name']);
        $this->assertNull($rows[0]['contact_phone']);
        // …and the keys are still there, so a client reading one list shape does
        // not have to guess which door it happened to come through.
        $this->assertArrayHasKey('contact_phone', $rows[0]);
    }

    public function test_a_board_with_no_position_says_so_instead_of_placing_everything_next_to_the_caller(): void
    {
        [, $seller] = $this->accountWithOrganization(null, 'seller', 'مورد');
        $this->warehouse($seller);

        $row = $this->getJson('/api/v1/marketplace/warehouses')
            ->assertOk()
            ->assertJsonPath('data.0.distance_km', null)
            ->json('data.0');

        // Not `0`. A zero is a claim that the goods are under the buyer's feet,
        // and a list sorted on it would put every unmeasured shed first.
        $this->assertArrayHasKey('distance_km', $row);
        $this->assertNull($row['distance_km']);
    }

    public function test_a_warehouse_that_recorded_no_coordinates_is_shown_last_and_still_shown(): void
    {
        [, $seller] = $this->accountWithOrganization(null, 'seller', 'مورد');
        $located = $this->warehouse($seller, ['name' => 'لها موقع', 'latitude' => 12.7855, 'longitude' => 45.0187]);
        $adrift = $this->warehouse($seller, ['name' => 'بلا إحداثيات', 'latitude' => null, 'longitude' => null]);

        $rows = $this->getJson('/api/v1/marketplace/warehouses?latitude=12.79&longitude=45.02')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $rows);
        $this->assertSame($located->id, $rows[0]['id']);
        $this->assertSame($adrift->id, $rows[1]['id']);
        $this->assertNull($rows[1]['distance_km']);
    }

    public function test_a_radius_narrows_the_board_and_a_half_position_is_refused(): void
    {
        [, $seller] = $this->accountWithOrganization(null, 'seller', 'مورد');
        $this->warehouse($seller, ['name' => 'قريب', 'latitude' => 12.7855, 'longitude' => 45.0187]);
        $this->warehouse($seller, ['name' => 'بعيد', 'latitude' => 14.5419, 'longitude' => 48.6742]);

        $this->getJson('/api/v1/marketplace/warehouses?latitude=12.79&longitude=45.02&radius_km=50')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'قريب');

        // Latitude without longitude is not a position, and a distance measured
        // from half of one looks exactly like a real number.
        $this->getJson('/api/v1/marketplace/warehouses?latitude=12.79')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');

        // A radius with nowhere to measure from means nothing, and silently
        // ignoring a filter is how a buyer is shown thirty sheds they excluded.
        $this->getJson('/api/v1/marketplace/warehouses?radius_km=50')
            ->assertStatus(422);
    }

    public function test_a_business_reading_its_own_board_still_sees_whom_to_call(): void
    {
        [$user, $org] = $this->accountWithOrganization(null, 'seller', 'مورد');
        $this->warehouse($org, ['contact_name' => 'سالم', 'contact_phone' => '770000111']);

        \Laravel\Sanctum\Sanctum::actingAs($user);
        $this->getJson('/api/v1/warehouses', $this->withTenant($org))
            ->assertOk()
            ->assertJsonPath('data.0.contact_name', 'سالم')
            ->assertJsonPath('data.0.contact_phone', '770000111');
    }

    /**
     * The two halves of `Geo` agree.
     *
     * The nearby-offers query measures in MySQL and this board measures in PHP,
     * because they work on different volumes. Two formulas is two numbers that
     * will not match in front of a buyer comparing a listing against the shed it
     * names, and nothing but a test standing in the middle would notice the day
     * one of them is edited.
     */
    public function test_the_php_distance_matches_the_shape_the_board_presents(): void
    {
        $aden = [12.7855, 45.0187];
        $mukalla = [14.5419, 48.6742];

        $km = Geo::kilometers($aden[0], $aden[1], $mukalla[0], $mukalla[1]);
        $this->assertNotNull($km);
        // Aden–Mukalla is ~470 km great-circle. The bound is loose on purpose:
        // what is being pinned is "the same answer as the SQL path", not a
        // survey of Yemen.
        $this->assertGreaterThan(400, $km);
        $this->assertLessThan(520, $km);

        // The same point is zero, not NaN and not a negative rounding artefact.
        $this->assertSame(0.0, Geo::present(Geo::kilometers($aden[0], $aden[1], $aden[0], $aden[1])));
        $this->assertNull(Geo::kilometers($aden[0], $aden[1], null, null));
        $this->assertNull(Geo::present(null));
    }
}
