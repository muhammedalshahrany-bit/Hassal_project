<?php

namespace Tests\Feature\Platform;

use App\Platform\Models\FeatureFlag;
use App\Organizations\Models\OrganizationMembership;
use App\Trading\Services\DealStateMachineService;
use App\Platform\Support\FlagCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use ReflectionClass;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * The capability switches, checked against the paths they are supposed to switch.
 *
 * `App\Platform\Support\FlagCatalog` opens with a promise: a flag is listed here only if
 * some backend path refuses or allows work on it. That promise is exactly the
 * kind of sentence a codebase drifts out of — a route gets rewritten and stops
 * carrying `feature:`, a capability gets built and its switch is never wired,
 * an operator flips a dead one and believes something changed. Both directions
 * are checked here, because each fails differently:
 *
 *   * a route gated by a key the catalog does not ship is a capability nobody
 *     can turn on. It answers `FEATURE_DISABLED` forever, and the console has no
 *     row to fix it with;
 *   * a catalog key that gates nothing is a switch on the wall wired to no
 *     lamp, which teaches everyone to distrust the switches that are wired.
 *
 * The last test is the one that keeps a misinstalled deployment from being an
 * open door: a capability whose row is missing must read as *off*, not as
 * "nothing said otherwise".
 */
class FeatureFlagSweepTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    /**
     * @return list<string>
     */
    private function catalogKeys(): array
    {
        return array_map(
            static fn (array $row): string => $row['key'],
            FlagCatalog::defaults()
        );
    }

    /**
     * Keys named by a route's `feature:` middleware.
     *
     * The route file is the declaration, so it is read as written rather than
     * through the router: `Route::getRoutes()` exposes middleware only after the
     * kernel has compiled each route, and a middleware string that never reaches
     * a route is precisely the drift being looked for.
     *
     * @return list<string>
     */
    private function routeGatedKeys(): array
    {
        $routes = file_get_contents(base_path('routes/api.php'));

        preg_match_all("/feature:([a-z_]+)/", $routes, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Keys a deal-state transition refuses or allows on.
     *
     * `TRANSITION_RULES` is the one place a capability is consulted outside the
     * route layer, and it is read by reflection rather than copied into this
     * test — a copy here would keep passing after the rule moved.
     *
     * @return list<string>
     */
    private function transitionGatedKeys(): array
    {
        $rules = (new ReflectionClass(DealStateMachineService::class))
            ->getConstant('TRANSITION_RULES');

        $keys = [];

        foreach ((array) $rules as $rule) {
            if (! empty($rule['feature'])) {
                $keys[] = $rule['feature'];
            }
        }

        return array_values(array_unique($keys));
    }

    public function test_no_route_is_gated_by_a_flag_the_catalog_does_not_ship(): void
    {
        $orphans = array_diff($this->routeGatedKeys(), $this->catalogKeys());

        $this->assertSame(
            [],
            array_values($orphans),
            'Routes gated by a key with no catalog row — nobody can switch those capabilities on.'
        );
    }

    public function test_every_shipped_flag_gates_a_real_path(): void
    {
        $gated = array_merge($this->routeGatedKeys(), $this->transitionGatedKeys());

        // Any other consumer that asks the service directly, found by looking
        // for the call rather than by trusting a list kept in this file.
        foreach ($this->appSourceFiles() as $file) {
            preg_match_all("/isEnabled\(\s*'([a-z_]+)'/", file_get_contents($file), $matches);
            $gated = array_merge($gated, $matches[1]);
        }

        $gated = array_values(array_unique($gated));

        foreach ($this->catalogKeys() as $key) {
            $this->assertContains(
                $key,
                $gated,
                "The console offers a `{$key}` switch that no backend path consults."
            );
        }
    }

    /**
     * What ships on, and what does not, is a statement about this product.
     *
     * Every capability that reaches outside the platform's own record-keeping is
     * off at install: the ledger needs real accounts behind it, an API key
     * currently opens nothing, and a webhook needs a public receiver. Switching
     * any of them on is an operator's decision made with those facts in front of
     * them, not a default a deployment discovers after the fact.
     *
     * There is no money capability to list. HASSAL documents the trade and the
     * two businesses settle it between themselves, so a switch that implied the
     * platform was holding somebody's funds would be a false control even if it
     * gated something.
     */
    public function test_capabilities_that_reach_outside_the_platform_ship_disabled(): void
    {
        $defaults = [];

        foreach (FlagCatalog::defaults() as $row) {
            $defaults[$row['key']] = $row['is_enabled'];
        }

        foreach (['double_entry_ledger', 'api_keys', 'webhooks'] as $key) {
            $this->assertArrayHasKey($key, $defaults);
            $this->assertFalse($defaults[$key], "`{$key}` must not be on out of the box.");

            // And the installed rows agree with the catalog, because a seeded
            // `true` would silently contradict the source that says otherwise.
            $this->assertFalse(
                app(\App\Platform\Services\FeatureFlagService::class)->isEnabled($key),
                "`{$key}` reads as enabled in a fresh install."
            );
        }
    }

    /**
     * A flag row that is not there is a capability that is not installed.
     *
     * `app:install-system` seeds these; an installation whose rows were deleted
     * (or that never ran the installer) must close, not fall through to
     * "no row said otherwise".
     */
    public function test_a_missing_flag_row_closes_the_route_rather_than_opening_it(): void
    {
        [$user, $seller] = $this->accountWithOrganization(null, 'seller');

        $this->assertSame(OrganizationMembership::ROLE_OWNER, $user->roleInOrganization($seller->id));

        // On, then the row removed: the only difference left is "unregistered".
        $this->setFlag('marketplace', true);
        FeatureFlag::where('key', 'marketplace')->delete();
        Cache::flush();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/marketplace/my-offers', $this->withTenant($seller))
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'FEATURE_DISABLED');
    }

    /**
     * @return list<string>
     */
    private function appSourceFiles(): array
    {
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        return $paths;
    }
}
