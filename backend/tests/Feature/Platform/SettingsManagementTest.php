<?php

namespace Tests\Feature\Platform;

use App\Organizations\Models\Organization;
use App\Platform\Models\SystemSetting;
use App\Platform\Support\FlagCatalog;
use App\Platform\Support\SettingCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * Writing a setting, and what a write is allowed to change.
 *
 * The settings API is the operator's only lever over pricing, moderation and
 * legal requirements, so two things have to hold: a write changes the value and
 * nothing else, and a key the catalog declares non-editable cannot be edited
 * through the back door. Before this, `POST /settings/update` sent no type or
 * group, and the service rewrote the whole row with its defaults — editing
 * `tax.rate` retyped a decimal to a string, moved it out of the `tax` group and
 * deleted both descriptions. A screen that lists settings by group would then
 * show a key that no longer matches what the code expects to read.
 */
class SettingsManagementTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    /** @return array{0: \App\Identity\Models\User, 1: Organization} */
    protected function platform(): array
    {
        return $this->accountWithOrganization('SuperAdmin', 'seller', 'منصة حصّال');
    }

    public function test_a_write_changes_the_value_and_leaves_the_declaration_alone(): void
    {
        [$platformUser] = $this->platform();
        Sanctum::actingAs($platformUser);

        $before = SystemSetting::where('key', 'tax.rate')->firstOrFail();
        $this->assertSame('decimal', $before->type);
        $this->assertSame('tax', $before->group);

        $this->postJson('/api/v1/settings/update', ['key' => 'tax.rate', 'value' => '0.0500'])
            ->assertOk()
            ->assertJsonPath('data.value', '0.0500')
            ->assertJsonPath('meta.in_catalog', true);

        $after = SystemSetting::where('key', 'tax.rate')->firstOrFail();
        $this->assertSame('0.0500', $after->value);
        $this->assertSame('decimal', $after->type, 'The write retyped the setting.');
        $this->assertSame('tax', $after->group, 'The write moved the setting to another group.');
        $this->assertNotNull($after->description_ar, 'The write erased the Arabic label.');

        // The service, not only the row, must agree — that is what the pricing
        // code reads.
        $this->assertSame(0.05, (float) app(\App\Platform\Services\SystemSettingsService::class)->get('tax.rate'));
    }

    public function test_a_value_has_to_fit_the_type_the_key_was_declared_with(): void
    {
        [$platformUser] = $this->platform();
        Sanctum::actingAs($platformUser);

        foreach ([
            ['tax.rate', 'not-a-number'],
            ['reservation.timeout', 'thirty'],
            ['tax.enabled', 'ربما'],
        ] as [$key, $value]) {
            $response = $this->postJson('/api/v1/settings/update', ['key' => $key, 'value' => $value]);
            $response->assertStatus(422)
                ->assertJsonPath('error_code', 'SETTING_TYPE_MISMATCH');

            $this->assertNotSame(
                (string) $value,
                SystemSetting::where('key', $key)->value('value'),
                "{$key} accepted a value its type cannot represent."
            );
        }

        // The boolean vocabulary the settings table actually stores.
        $this->postJson('/api/v1/settings/update', ['key' => 'tax.enabled', 'value' => 'true'])
            ->assertOk();
        $this->assertTrue((bool) app(\App\Platform\Services\SystemSettingsService::class)->get('tax.enabled'));
    }

    /**
     * `is_editable` is a real gate now rather than a decoration. Note that the
     * catalog ships every key editable — its fifth argument is `is_public`, not
     * editability — so this pins a row the way a future catalog entry would, and
     * checks the server honours it.
     */
    public function test_a_key_marked_non_editable_is_refused_and_keeps_its_value(): void
    {
        [$platformUser] = $this->platform();
        Sanctum::actingAs($platformUser);

        SystemSetting::where('key', 'commission.rate')->update(['is_editable' => false]);

        $this->postJson('/api/v1/settings/update', ['key' => 'commission.rate', 'value' => '0.9000'])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'SETTING_NOT_EDITABLE');

        $row = SystemSetting::where('key', 'commission.rate')->firstOrFail();
        $this->assertNotSame('0.9000', $row->value);
        $this->assertFalse($row->is_editable, 'A refused write must not unlock the key.');
    }

    public function test_an_editable_key_stays_editable_after_a_write(): void
    {
        [$platformUser] = $this->platform();
        Sanctum::actingAs($platformUser);

        $this->postJson('/api/v1/settings/update', ['key' => 'inspection.window', 'value' => '24'])
            ->assertOk();

        $row = SystemSetting::where('key', 'inspection.window')->firstOrFail();
        $this->assertTrue($row->is_editable);
        $this->assertSame('integer', $row->type);
        $this->assertSame('operations', $row->group);
    }

    /**
     * A business administrator has no access to the platform's configuration at
     * all: the whole `/settings` prefix is gated on the platform role, read
     * included, so nothing about the platform's switches leaks to a tenant.
     */
    public function test_the_settings_surface_is_closed_to_a_business_administrator(): void
    {
        [$user, $organization] = $this->accountWithOrganization('SellerAdmin', 'seller', 'شركة البيع');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/settings', $this->withTenant($organization))
            ->assertForbidden();

        $this->postJson('/api/v1/settings/update', ['key' => 'tax.rate', 'value' => '0.9900'],
            $this->withTenant($organization))->assertForbidden();

        $this->assertNotSame('0.9900', SystemSetting::where('key', 'tax.rate')->value('value'));
    }

    /**
     * A key nothing reads is not configuration. The endpoint says so instead of
     * accepting the write silently and leaving the operator to discover that no
     * behaviour changed.
     */
    public function test_an_unrecognized_key_is_created_but_flagged_as_unwired(): void
    {
        [$platformUser] = $this->platform();
        Sanctum::actingAs($platformUser);

        $this->assertNotContains('reports.branding_color', array_column(SettingCatalog::defaults(), 'key'));

        $this->postJson('/api/v1/settings/update', [
            'key' => 'reports.branding_color',
            'value' => '#123456',
        ])
            ->assertOk()
            ->assertJsonPath('data.key', 'reports.branding_color')
            ->assertJsonPath('meta.in_catalog', false);

        $this->assertDatabaseHas('system_settings', [
            'key' => 'reports.branding_color',
            'value' => '#123456',
        ]);
    }

    /**
     * A flag gates a real backend path or it is not offered. `isEnabled()`
     * fails closed on an unknown key, and the insert itself would abort on the
     * NOT NULL `name_ar`, so the refusal has to be a 422 with the real list.
     */
    public function test_a_capability_that_gates_nothing_cannot_be_created_by_toggling(): void
    {
        [$platformUser] = $this->platform();
        Sanctum::actingAs($platformUser);

        $response = $this->postJson('/api/v1/feature-flags/night_mode/toggle', ['is_enabled' => true])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'FLAG_NOT_IN_CATALOG');

        $known = $response->json('error.details.known_flags');
        $this->assertIsArray($known);
        sort($known);

        $catalog = array_column(FlagCatalog::defaults(), 'key');
        sort($catalog);
        $this->assertNotSame([], $catalog, 'The flag catalog is empty, so this assertion proves nothing.');
        $this->assertSame($catalog, $known, 'The refusal must list exactly the catalogued capabilities.');

        // And a name in the catalog has to gate something real. A flag with no
        // gate behind it is a switch that changes nothing, which is worse than
        // no switch: it teaches the operator that switches here are decorative.
        //
        // A gate is not always a route. `double_entry_ledger` decides inside the
        // state machine whether a closed deal posts its commission, because what
        // it governs is a side effect rather than a call to an endpoint — so this
        // looks at both places, and at nothing else. A key mentioned only in the
        // catalog is exactly the dead switch this test refuses to ship.
        $gated = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'feature:')) {
                    $gated[] = substr($middleware, strlen('feature:'));
                }
            }
        }

        $sources = array_merge(
            glob(base_path('app/Services/*.php')) ?: [],
            glob(base_path('app/Http/Middleware/*.php')) ?: [],
        );

        foreach ($sources as $file) {
            $code = (string) file_get_contents($file);
            if (preg_match_all("/'feature'\s*=>\s*'([a-z_]+)'|isEnabled\(\s*'([a-z_]+)'\s*\)/", $code, $matches)) {
                foreach (array_merge($matches[1], $matches[2]) as $key) {
                    if ($key !== '') {
                        $gated[] = $key;
                    }
                }
            }
        }

        $gated = array_values(array_unique($gated));
        sort($gated);

        $this->assertSame([], array_diff($catalog, $gated), 'Every catalogued flag must gate a real path.');
        $this->assertSame([], array_diff($gated, $catalog), 'A gate the catalog does not name is a switch the operator cannot control.');
        $this->assertDatabaseMissing('feature_flags', ['key' => 'night_mode']);
    }

    public function test_a_catalogued_capability_toggles_and_takes_effect_at_once(): void
    {
        [$platformUser] = $this->platform();
        Sanctum::actingAs($platformUser);

        $this->postJson('/api/v1/feature-flags/double_entry_ledger/toggle', ['is_enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.key', 'double_entry_ledger')
            ->assertJsonPath('data.is_enabled', true);

        // The memoised flag map is invalidated by the write, not by a timer.
        $this->assertTrue(
            app(\App\Platform\Services\FeatureFlagService::class)->isEnabled('double_entry_ledger'),
            'The toggle did not reach the service that enforces it.'
        );

        $this->postJson('/api/v1/feature-flags/double_entry_ledger/toggle', ['is_enabled' => false])
            ->assertOk();
        $this->assertFalse(app(\App\Platform\Services\FeatureFlagService::class)->isEnabled('double_entry_ledger'));
    }
}
