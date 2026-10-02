<?php

namespace Tests\Feature\Auth;

use App\Marketplace\Models\Offer;
use App\Organizations\Models\Organization;
use App\Organizations\Models\OrganizationMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * The cold-start payload, and the one source each field in it has.
 *
 * `GET /bootstrap` is the first call an app makes, so it is where a client's
 * picture of the platform gets built. Three things were true about it before
 * this suite existed, and all three are the kind that cost a user-visible
 * minute to find in a screen rather than a second to read here:
 *
 *   1. Its `user` block was a third hand-written copy of the account — after a
 *      sign-in and a profile read — and it had grown fields the other two sent
 *      and one it did not. The tests below compare the blocks instead of
 *      trusting that three descriptions of one person agree.
 *   2. Its `tenant` block was chosen by asking which company this person belongs
 *      to, while the capabilities sitting above it were assembled from the
 *      business the request was actually acting as. One payload, two businesses.
 *   3. The opportunity-reason dropdown was typed out again here while the
 *      validator accepted `Offer::OPPORTUNITY_REASONS`. The list a form offers
 *      and the list a server takes are the same list, or a reason is pickable
 *      but refused, or acceptable but invisible.
 *
 * Nothing here asserts wording a client never compares, and nothing asserts a
 * setting an operator has not been asked to choose — see the note above each
 * expectation.
 */
class BootstrapPayloadTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    public function test_a_signed_out_cold_start_gets_the_reference_data_and_names_no_one(): void
    {
        $data = $this->getJson('/api/v1/bootstrap')->assertOk()->json('data');

        $this->assertNull($data['user'], 'A request with no token cannot be answered an account.');
        $this->assertNull($data['tenant'], 'Nor a business it is supposedly acting as.');

        // Configured by the catalog, not guessed by the client: these are the
        // values a Yemeni installation answers with before a wizard runs.
        $this->assertSame('YER', $data['base_currency']);
        $this->assertSame('ar', $data['default_locale']);
        $this->assertSame('عدن', $data['platform_settings']['default_city']);
        $this->assertSame('OPERATIONAL', $data['system_status'], 'The database answered, so the client is not told the platform is down.');

        $this->assertNotEmpty($data['currencies']);
        $this->assertNotEmpty($data['feature_flags'], 'A cold start with no flags would show every gated control to everyone.');

        // The tree is operator data, not something the platform ships: a fresh
        // deployment answers an empty list rather than a compiled-in one a client
        // would then have to keep in step by hand.
        $this->assertSame([], $data['categories'], 'Bootstrap carries no category of its own.');

        $category = $this->category();

        $this->assertSame(
            $category->id,
            $this->getJson('/api/v1/bootstrap')->assertOk()->json('data.categories.0.id'),
            'A category an operator adds is drawn from this call, which is why the client does not keep a list.'
        );
    }

    public function test_the_reasons_a_form_offers_are_exactly_the_ones_the_vocabulary_names(): void
    {
        $reasons = $this->getJson('/api/v1/bootstrap')->assertOk()->json('data.opportunity_reasons');

        // Order included: the dropdown shows these rows in this order, and the
        // constant is the order its own definition means.
        $this->assertSame(
            array_values(Offer::OPPORTUNITY_REASONS),
            array_column($reasons, 'code'),
            'The list the client is offered and the list the API accepts are one list — a reason missing here cannot be chosen, and a reason missing there cannot be filed.'
        );

        foreach ($reasons as $reason) {
            $this->assertNotSame($reason['code'], $reason['label_ar'], 'A dropdown that reads `SURPLUS_STOCK` to a seller is the bug this row exists to prevent.');
            $this->assertNotSame($reason['code'], $reason['label_en']);
            $this->assertNotSame('', trim((string) $reason['label_ar']));
        }
    }

    public function test_the_account_a_client_reads_is_the_one_the_profile_answers(): void
    {
        [$user, $organization] = $this->accountWithOrganization(null, Organization::TYPE_SELLER, 'مورد تجريبي');
        $token = $this->tokenFor($user);

        $bootstrap = $this->withToken($token)
            ->getJson('/api/v1/bootstrap', $this->withTenant($organization))
            ->assertOk()
            ->json('data');

        $this->asFresh();

        $profile = $this->withToken($token)
            ->getJson('/api/v1/auth/me', $this->withTenant($organization))
            ->assertOk()
            ->json('data');

        $this->assertSame(
            $profile['user'],
            $bootstrap['user'],
            'One person, one shape: a screen that reads bootstrap first and the profile second must not find a field the first call never carried.'
        );

        // Named individually because these are the three that were missing from
        // this payload while both other endpoints sent them.
        foreach (['national_id', 'status', 'preferences'] as $field) {
            $this->assertArrayHasKey($field, $bootstrap['user'], 'The cold start owes the client `' . $field . '` without a second call.');
        }

        $this->assertSame($user->id, $bootstrap['user']['id']);
        $this->assertSame($user->email, $bootstrap['user']['email']);
    }

    public function test_the_business_named_is_the_one_the_request_is_acting_as(): void
    {
        [$user, $seller] = $this->accountWithOrganization(null, Organization::TYPE_SELLER, 'مورد تجريبي');
        $buyer = $this->organization(Organization::TYPE_BUYER, 'مشترٍ تجريبي');
        $this->membership($buyer, $user, 'staff');

        $token = $this->tokenFor($user);

        // With nothing said, the context is the first active membership — and
        // both endpoints have to agree about which one that was.
        $withoutHeader = $this->withToken($token)->getJson('/api/v1/bootstrap')->assertOk()->json('data');

        $this->assertSame($seller->id, $withoutHeader['tenant']['id']);
        $this->assertSame(OrganizationMembership::ROLE_OWNER, $withoutHeader['tenant']['role_in_organization']);

        $this->asFresh();

        $named = $this->withToken($token)
            ->getJson('/api/v1/bootstrap', $this->withTenant($buyer))
            ->assertOk()
            ->json('data');

        $this->assertSame(
            $buyer->id,
            $named['tenant']['id'],
            'A payload that speaks for the first company the memberships row returns is a console showing one business while acting as another.'
        );
        $this->assertSame('staff', $named['tenant']['role_in_organization'], 'Standing belongs to a business: owning one does not make this person the owner of the next.');
        $this->assertSame($buyer->name_ar, $named['tenant']['name_ar']);
        $this->assertSame($buyer->commercial_registration_number, $named['tenant']['commercial_registration_number']);

        $this->asFresh();

        $profile = $this->withToken($token)
            ->getJson('/api/v1/auth/me', $this->withTenant($buyer))
            ->assertOk()
            ->json('data');

        $this->assertSame(
            $profile['current_organization']['id'],
            $named['tenant']['id'],
            'Bootstrap and the profile are the same request context described twice; they cannot be allowed to name two businesses.'
        );
    }

    public function test_a_business_the_caller_does_not_belong_to_is_refused_rather_than_described(): void
    {
        [$intruder, ] = $this->accountWithOrganization(null, Organization::TYPE_BUYER, 'متطفل');
        $foreign = $this->organization(Organization::TYPE_SELLER, 'منشأة غير مسموحة');

        $token = $this->tokenFor($intruder);

        // This route answers signed-out, so the only thing standing between a
        // borrowed header and a payload is the context resolver. The refusal has
        // to be the answer — not a 200 that leaves `tenant` null and lets a
        // client conclude its borrowed header was simply ignored.
        $this->withToken($token)
            ->getJson('/api/v1/bootstrap', $this->withTenant($foreign))
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ORGANIZATION_ACCESS_DENIED');
    }

    public function test_a_seat_that_was_closed_is_not_the_business_a_payload_speaks_for(): void
    {
        $closed = $this->organization(Organization::TYPE_SELLER, 'منشأة مغلقة');
        $active = $this->organization(Organization::TYPE_BUYER, 'منشأة عاملة');

        $user = $this->user('زميل في منشأتين');
        $this->membership($closed, $user, OrganizationMembership::ROLE_OWNER, false);
        $this->membership($active, $user, 'staff');

        $token = $this->tokenFor($user);

        $bootstrap = $this->withToken($token)->getJson('/api/v1/bootstrap')->assertOk()->json('data');

        $this->assertSame(
            $active->id,
            $bootstrap['tenant']['id'],
            'The account holds one open seat; a payload that names the closed one describes a business nobody is acting as — and its permissions were already assembled from the open one.'
        );

        $this->asFresh();

        $profile = $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->json('data');

        $this->assertSame(
            $profile['current_organization']['id'],
            $bootstrap['tenant']['id'],
            'Bootstrap and the profile describe one request context; they cannot be allowed to name two businesses.'
        );
        $this->assertSame($bootstrap['user'], $profile['user'], 'The same agreement, on the account half of the payload.');
    }
}
