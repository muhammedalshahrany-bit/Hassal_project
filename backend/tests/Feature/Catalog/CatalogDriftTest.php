<?php

namespace Tests\Feature\Catalog;

use App\Platform\Models\FeatureFlag;
use App\Platform\Models\SystemSetting;
use App\Platform\Support\CatalogSync;
use App\Platform\Support\FlagCatalog;
use App\Platform\Support\SettingCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * The catalogs and the installed rows cannot be allowed to drift apart.
 *
 * This is not a cosmetics test. A capability whose feature-flag row is missing
 * fails closed — `FeatureFlagService::isEnabled()` treats an unknown key as off
 * — so the deployed marketplace stopped showing likes and questions on listings
 * after the community flag shipped in code: the routes, the tests and the
 * client all had the feature, and the live `feature_flags` table had no row for
 * it, which made it invisible and unswitchable at once. A missing *setting* is
 * the mirror image: the console lists nothing, and the code quietly answers from
 * a caller's fallback.
 *
 * So four things are pinned here:
 *
 *   1. A fresh test database, seeded the way `app:install-system` seeds it,
 *      contains every key both catalogs declare. Drift fails the suite.
 *   2. Syncing inserts the missing rows with their type, group, descriptions
 *      and public visibility — not as bare keys, which is what a console needs
 *      to explain a switch to an operator.
 *   3. Syncing leaves an operator's chosen value and an operator's off switch
 *      exactly as they are. A deploy must never undo a configuration.
 *   4. Both reconcile commands are idempotent.
 */
class CatalogDriftTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    public function test_a_seeded_install_holds_every_key_both_catalogs_declare(): void
    {
        $this->assertSame(
            ['settings' => [], 'flags' => []],
            CatalogSync::drift(),
            'the catalogs gained a key the installer does not seed',
        );

        $this->assertCount(count(SettingCatalog::defaults()), SystemSetting::whereNull('organization_id')->get());
        $this->assertCount(count(FlagCatalog::defaults()), FeatureFlag::all());
    }

    public function test_syncing_a_missing_capability_makes_it_switchable_again_and_visible_in_bootstrap(): void
    {
        // Reproduce the deployed failure: the community flag exists in code and
        // not in this database.
        FeatureFlag::where('key', 'community')->delete();
        SystemSetting::where('key', 'marketplace.require_verified_to_trade')->delete();

        $this->assertSame(['community'], CatalogSync::drift()['flags']);
        $this->assertSame(['marketplace.require_verified_to_trade'], CatalogSync::drift()['settings']);

        $added = CatalogSync::run();
        $this->assertSame(['settings' => 1, 'flags' => 1], $added);

        $flag = FeatureFlag::where('key', 'community')->firstOrFail();
        $this->assertTrue($flag->is_enabled, 'the catalog default is on, so the capability returns usable');
        $this->assertNotEmpty($flag->name_ar);

        $setting = SystemSetting::where('key', 'marketplace.require_verified_to_trade')->firstOrFail();
        $this->assertSame('trust', $setting->group);
        $this->assertSame('boolean', $setting->type);
        $this->assertNotNull($setting->description_ar, 'a switch with no description cannot be operated');
        $this->assertSame('1', (string) $setting->value);

        // The client hides the interest controls off this very payload, which
        // is the shape the bug was reported in.
        $bootstrap = $this->getJson('/api/v1/bootstrap')->assertOk()->json();
        $this->assertTrue(
            $bootstrap['data']['feature_flags']['community']['is_enabled'],
            'the client reads this exact field to decide whether to show the interest controls',
        );
    }

    public function test_syncing_never_touches_a_value_or_a_switch_someone_already_chose(): void
    {
        SystemSetting::where('key', 'tax.rate')->update(['value' => '0.0500']);
        FeatureFlag::where('key', 'api_keys')->update(['is_enabled' => true]);
        FeatureFlag::where('key', 'webhooks')->update(['is_enabled' => false]);

        $before = [
            'tax.rate' => SystemSetting::where('key', 'tax.rate')->value('value'),
            'api_keys' => FeatureFlag::where('key', 'api_keys')->value('is_enabled'),
            'webhooks' => FeatureFlag::where('key', 'webhooks')->value('is_enabled'),
        ];

        $this->assertSame(['settings' => 0, 'flags' => 0], CatalogSync::run());

        $this->assertSame($before['tax.rate'], SystemSetting::where('key', 'tax.rate')->value('value'));
        $this->assertEquals(1, FeatureFlag::where('key', 'api_keys')->value('is_enabled'), 'an enabled capability stays enabled');
        $this->assertEquals(0, FeatureFlag::where('key', 'webhooks')->value('is_enabled'), 'a switch an operator turned off stays off');
    }

    public function test_both_reconcile_commands_report_the_same_state_and_are_idempotent(): void
    {
        FeatureFlag::where('key', 'community')->delete();
        SystemSetting::where('key', 'community.moderate_before_publishing')->delete();

        $this->artisan('features:sync-catalog')
            ->assertSuccessful()
            ->expectsOutputToContain('community');

        $this->artisan('settings:sync-catalog')
            ->assertSuccessful()
            ->expectsOutputToContain('community.moderate_before_publishing');

        $this->assertSame(['settings' => [], 'flags' => []], CatalogSync::drift());

        // Second run: nothing left to insert, nothing overwritten.
        $this->artisan('features:sync-catalog')->assertSuccessful();
        $this->artisan('settings:sync-catalog')->assertSuccessful();

        $this->assertSame(['settings' => [], 'flags' => []], CatalogSync::drift());
        $this->assertTrue(FeatureFlag::where('key', 'community')->value('is_enabled'));
        $this->assertNotNull(SystemSetting::where('key', 'community.moderate_before_publishing')->value('description_ar'));
    }
}
