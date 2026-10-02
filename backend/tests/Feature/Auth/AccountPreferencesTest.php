<?php

namespace Tests\Feature\Auth;

use App\Auth\Support\PreferenceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * What one person decides the product looks like, and nothing else.
 *
 * The three things worth defending here are all rules this endpoint could have
 * quietly broken:
 *
 *   1. It is *scoped to the caller*. There is no user id in the path, so the
 *      only account a person can set preferences for is their own — the test
 *      below asserts a second user's write cannot be read back through the
 *      first one's row.
 *   2. A patch is a patch. A settings screen that shows two switches must not
 *      blank the other eight by omitting them.
 *   3. A key the platform does not define is not stored. The JSON column is
 *      otherwise a place for a client to park whatever it felt like caching,
 *      and it is named in `ignored` so the discard is visible to the person
 *      who asked for it rather than silent.
 */
class AccountPreferencesTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    public function test_a_fresh_account_is_answered_the_compiled_defaults(): void
    {
        $token = $this->tokenFor($user = $this->user());

        $response = $this->withToken($token)->getJson('/api/v1/auth/preferences');

        $response->assertOk()
            ->assertJsonPath('data.preferences', PreferenceCatalog::defaults())
            // The catalog travels with the values so a client renders what this
            // deployment defines instead of mirroring the list in its own code.
            ->assertJsonStructure([
                'data' => [
                    'catalog' => [
                        ['key', 'type', 'default', 'group', 'ar', 'en'],
                    ],
                ],
            ]);

        $this->assertNull($user->fresh()->preferences);
    }

    public function test_a_patch_leaves_the_keys_it_never_mentioned_alone(): void
    {
        $token = $this->tokenFor($user = $this->user());

        $this->withToken($token)
            ->putJson('/api/v1/auth/preferences', [
                'preferences' => [
                    PreferenceCatalog::HIDE_ORGANIZATION_NAME => true,
                    PreferenceCatalog::THEME => 'dark',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.preferences.hide_organization_name', true)
            ->assertJsonPath('data.preferences.theme', 'dark')
            // The two defaults the patch did not touch must still be there.
            ->assertJsonPath('data.preferences.compact_lists', false)
            ->assertJsonPath('data.preferences.notifications_enabled', true)
            ->assertJsonMissingPath('data.ignored');

        $stored = $user->fresh()->preferences;
        $this->assertSame(true, $stored[PreferenceCatalog::HIDE_ORGANIZATION_NAME]);
        $this->assertSame('dark', $stored[PreferenceCatalog::THEME]);
    }

    public function test_a_key_the_platform_does_not_define_is_refused_by_name_and_never_stored(): void
    {
        $token = $this->tokenFor($user = $this->user());

        $this->withToken($token)
            ->putJson('/api/v1/auth/preferences', [
                'preferences' => [
                    'notifications_enabled' => false,
                    'pinned_api_token' => 'a value no client may park in a settings row',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.ignored', ['pinned_api_token'])
            // The key the person did ask for still applied: an unknown name in
            // the same patch is not a reason to refuse the whole write.
            ->assertJsonPath('data.preferences.notifications_enabled', false);

        $this->assertArrayNotHasKey('pinned_api_token', $user->fresh()->preferences);
    }

    public function test_a_value_outside_an_enums_own_list_is_ignored_rather_than_stored(): void
    {
        $token = $this->tokenFor($user = $this->user());

        $this->withToken($token)
            ->putJson('/api/v1/auth/preferences', [
                'preferences' => ['theme' => 'pinned to a dark corner'],
            ])
            ->assertOk()
            ->assertJsonPath('data.ignored', ['theme'])
            ->assertJsonPath('data.preferences.theme', 'system');

        $this->assertSame('system', $user->fresh()->preferences[PreferenceCatalog::THEME]);
    }

    public function test_the_preferences_of_one_account_are_never_another_accounts(): void
    {
        $first = $this->user('أول');
        $second = $this->user('ثانٍ');

        // Both tokens are taken before either is attached, and each request that
        // follows is made against a fresh guard — see `asFresh()`. Without that,
        // the second account's read would be served the first account's session
        // and this would fail as a "leak" that is only the test process.
        $firstToken = $this->tokenFor($first);
        $secondToken = $this->tokenFor($second);

        $this->asFresh();
        $this->withToken($firstToken)
            ->putJson('/api/v1/auth/preferences', [
                'preferences' => [PreferenceCatalog::HIDE_SELLER_NAME => true],
            ])
            ->assertOk();

        // The second read is the assertion: the route has no id to guess, so
        // scoping is by the bearer token alone.
        $this->asFresh();
        $this->withToken($secondToken)
            ->getJson('/api/v1/auth/preferences')
            ->assertOk()
            ->assertJsonPath('data.preferences.hide_seller_name', false);

        $this->assertTrue($first->fresh()->preferences[PreferenceCatalog::HIDE_SELLER_NAME]);
    }

    public function test_the_session_itself_carries_the_values_so_the_chrome_needs_no_second_fetch(): void
    {
        $token = $this->tokenFor($user = $this->user());

        $this->withToken($token)
            ->putJson('/api/v1/auth/preferences', [
                'preferences' => [PreferenceCatalog::HIDE_ORGANIZATION_NAME => true],
            ])
            ->assertOk();

        // `/auth/me` is the call every client makes on opening. A preference the
        // header obeys has to be in that answer, or the header would show a name
        // for the whole run before the settings screen ever loaded.
        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.preferences.hide_organization_name', true);
    }

    public function test_preferences_require_a_session(): void
    {
        $this->getJson('/api/v1/auth/preferences')->assertUnauthorized();
        $this->putJson('/api/v1/auth/preferences', ['preferences' => ['theme' => 'dark']])
            ->assertUnauthorized();
    }

    public function test_a_written_row_is_answered_complete_so_no_client_has_to_supply_defaults(): void
    {
        $token = $this->tokenFor($user = $this->user());

        $this->withToken($token)
            ->putJson('/api/v1/auth/preferences', [
                'preferences' => [PreferenceCatalog::COMPACT_LISTS => true],
            ])
            ->assertOk();

        // The whole merged map is stored, not the patch. A client reading the row
        // would otherwise have to know which of the eleven keys it has never heard
        // of, and a missing one would be a switch rendered from a guess.
        //
        // Compared as sets, deliberately. A MySQL `json` column keeps its object
        // keys sorted by length and then by bytes, so the order a PHP array went
        // in is not the order that comes back — and the platform makes no claim
        // in that order. Clients read `preferences.theme`, never `preferences[3]`,
        // and it is the *catalog* (a JSON array) whose order both clients render
        // from. Asserting a sequence here would test the storage engine.
        $stored = array_keys($user->fresh()->preferences);
        sort($stored);

        $defined = array_keys(PreferenceCatalog::defaults());
        sort($defined);

        $this->assertSame($defined, $stored);
        // A literal, and deliberately not `count($defined)`: the assertion above
        // would pass against an empty catalog read from an empty catalog. This one
        // is the tripwire that says the list grew or shrank. Adding a key here
        // means updating `PreferenceCatalog`, this number, and §4.18 of
        // API_CONTRACT.md — the contract names the keys, so a client written
        // against it stops matching the server the moment one moves alone.
        //
        // Twelve since `notify_offers` joined the catalog: the market-wide offer
        // broadcast is the first notification a person may want switched off
        // before it exists, and a default of `true` is the one place this catalog
        // deliberately changes what an established account sees.
        $this->assertCount(12, $stored);
    }
}
