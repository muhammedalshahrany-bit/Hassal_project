<?php

namespace Tests\Support;

use App\Catalog\Models\Category;
use App\Platform\Models\Currency;
use App\Trading\Models\Deal;
use App\Marketplace\Models\Demand;
use App\Platform\Models\FeatureFlag;
use App\Marketplace\Models\Offer;
use App\Marketplace\Models\OfferItem;
use App\Organizations\Models\Organization;
use App\Organizations\Models\OrganizationMembership;
use App\Catalog\Models\Product;
use App\Catalog\Models\ProductVariant;
use App\Trading\Models\PurchaseRequest;
use App\Trading\Models\Reservation;
use App\Platform\Models\SystemSetting;
use App\Identity\Models\User;
use App\Inventory\Models\Warehouse;
use App\Platform\Services\FeatureFlagService;
use App\Trading\Services\InventoryReservationService;
use App\Platform\Services\SystemSettingsService;
use App\Platform\Support\FlagCatalog;
use App\Platform\Support\SettingCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Builds the smallest real world a test can act in.
 *
 * Every fixture is created inside the test itself. The suite used to look up
 * demo users and offers by email (`seller@alwataniya.sa`) from a seeded
 * database, which meant the tests described a Saudi pilot that is not this
 * product, broke whenever the demo data changed, and could not express a
 * scenario the seed did not already contain.
 *
 * Nothing here is secret: passwords are generated per call, so no credential
 * is written into the repository — including this file.
 */
trait HassalFixtures
{
    /** Aden: the city HASSAL's discovery features are built around. */
    protected const CITY = 'عدن';
    protected const LAT = 12.7855000;
    protected const LNG = 45.0187000;

    protected array $fixturePasswords = [];

    /**
     * Reference data the application assumes exists: the RBAC matrix, the
     * settings vocabulary, the capability flags, and the base currency.
     *
     * This mirrors what `app:install-system` produces, so a test exercises the
     * configuration a real deployment actually runs with — including the flags
     * that gate the routes.
     */
    protected function seedPlatformDefaults(): void
    {
        Cache::flush();
        $this->seed(RolesAndPermissionsSeeder::class);

        foreach (SettingCatalog::defaults() as $setting) {
            SystemSetting::updateOrCreate(
                ['key' => $setting['key'], 'organization_id' => null],
                $setting
            );
        }

        foreach (FlagCatalog::defaults() as $flag) {
            FeatureFlag::updateOrCreate(['key' => $flag['key']], $flag);
        }

        Currency::query()->delete();
        Currency::create([
            'iso_code' => 'YER',
            'name_ar' => 'ريال يمني',
            'name_en' => 'Yemeni Rial',
            'symbol' => 'ر.ي',
            'decimal_places' => 2,
            'exchange_rate_to_base' => 1.000000,
            'rate_source' => 'BASE_CURRENCY',
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    /**
     * Change one setting the way the admin console does, cache included.
     */
    protected function setSetting(string $key, mixed $value, string $type = 'string'): void
    {
        app(SystemSettingsService::class)->set($key, $value, $type);
    }

    protected function setFlag(string $key, bool $enabled): void
    {
        app(FeatureFlagService::class)->setFlag($key, $enabled);
    }

    protected function passwordFor(User $user): string
    {
        return $this->fixturePasswords[$user->id] ?? '';
    }

    /**
     * Make the next request resolve its own caller.
     *
     * One test method is one PHP process, and Laravel does not rebuild the
     * container between the HTTP calls inside it — so the request guard keeps the
     * user it resolved for the first bearer token it saw. A second account's
     * token would then read back as the first account's session, and an isolation
     * test written that way would be comparing one account with itself while
     * believing it had caught a leak.
     *
     * This is a property of the harness, not of the endpoint. A real request gets
     * a fresh guard, and so does a long-running one: Octane flushes the auth
     * guards between ticks for exactly this reason.
     */
    protected function asFresh(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /**
     * Sign in over the real route and hand back the token that works.
     *
     * Through the endpoint rather than `Sanctum::actingAs`, because the payload
     * a client reads is built by the login path — `data.token` in particular. A
     * test that wants to know what a signed-in caller is answered has to have
     * been signed in; acting as a user fakes the caller while leaving the
     * credential out, which is the difference between testing the request and
     * testing the intent to make one.
     *
     * `$device` is the optional third thing a sign-in carries (see
     * `SignInDevicesTest`): the handset or browser named in the request body.
     *
     * @param  array{key?: string, name?: string, platform?: string, biometric_unlock?: bool}  $device
     */
    protected function tokenFor(User $user, array $device = []): string
    {
        $this->asFresh();

        $response = $this->postJson('/api/v1/auth/login', array_merge([
            'login' => $user->email,
            'password' => $this->passwordFor($user),
        ], $device));

        $response->assertOk();

        return (string) $response->json('data.token');
    }

    /**
     * An account that owns an organization, and the platform role it is given.
     *
     * The first argument used to be where a test's business authority came
     * from — `SellerAdmin`, `BuyerStaff`. It is not any more: that authority is
     * the standing in the owner membership created here, and the retired names
     * are empty rows that grant nothing. They are still accepted so a scenario
     * that says "a seller" reads the same as it always did, but passing one now
     * means "no platform role", which is what it actually was.
     *
     * @return array{0: User, 1: Organization}
     */
    protected function accountWithOrganization(?string $platformRole = null, string $organizationType = 'buyer', ?string $name = null): array
    {
        $organization = $this->organization($organizationType, $name);
        $user = $this->user($name ?? 'موظف تجريبي');

        $this->membership($organization, $user, OrganizationMembership::ROLE_OWNER);

        if ($platformRole !== null && in_array($platformRole, (array) config('rbac.platform_roles'), true)) {
            $user->assignRole($platformRole);
        }

        return [$user, $organization];
    }

    /**
     * An account whose only standing is inside this one company: no platform
     * role, nothing outside the membership row.
     */
    protected function memberOf(Organization $organization, string $standing = 'staff'): User
    {
        $user = $this->user('زميل');
        $this->membership($organization, $user, $standing);

        return $user;
    }

    /**
     * An account, complete as far as this deployment's switches ask.
     *
     * The phone number is here because `account.require_phone_number` ships on:
     * a business cannot be opened by an account that has none, and a fixture
     * that meant to exercise "someone who owns a company" would otherwise be
     * refused for a reason the test is not about. A test that wants the bare
     * account asks for it directly — see `ProfileCompletionTest`, which is
     * precisely about the missing field.
     */
    protected function user(string $name = 'مستخدم تجريبي'): User
    {
        $password = Str::password(16, symbols: false);

        $user = User::create([
            'name' => $name . ' ' . Str::random(5),
            'email' => 'test+' . Str::random(10) . '@example.test',
            'phone' => '+9677' . Str::password(8, false, false),
            'password' => $password,
            'status' => 'active',
        ]);

        $this->fixturePasswords[$user->id] = $password;

        return $user;
    }

    protected function organization(string $type = 'buyer', ?string $name = null, array $attributes = []): Organization
    {
        $suffix = Str::upper(Str::random(8));

        return Organization::create(array_merge([
            'name_ar' => $name ?? 'منشأة تجريبية ' . $suffix,
            'name_en' => 'Fixture Organization ' . $suffix,
            'type' => $type,
            'country_code' => 'YE',
            'city' => self::CITY,
            'status' => Organization::STATUS_VERIFIED,
            'commercial_registration_number' => 'CR-' . $suffix,
            'vat_number' => 'VAT-' . $suffix,
        ], $attributes));
    }

    /**
     * A seat in a business.
     *
     * `$active` is the whole point of the last argument: a closed seat is a row
     * that still exists and still says which company this person was once at, so
     * the questions a suite asks about it — what does a payload speak for, what
     * may this account act on — are decided by the flag rather than by deleting
     * evidence.
     */
    protected function membership(Organization $organization, User $user, string $role = 'admin', bool $active = true): OrganizationMembership
    {
        return OrganizationMembership::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'is_active' => $active,
            'joined_at' => now(),
        ]);
    }

    protected function warehouse(Organization $organization, array $attributes = []): Warehouse
    {
        return Warehouse::create(array_merge([
            'organization_id' => $organization->id,
            'name' => 'مستودع ' . self::CITY . ' ' . Str::random(4),
            'code' => 'WH-' . Str::upper(Str::random(6)),
            'city' => self::CITY,
            'address_line' => 'شارع التسعين، ' . self::CITY,
            'latitude' => self::LAT,
            'longitude' => self::LNG,
            'is_active' => true,
        ], $attributes));
    }

    protected function category(): Category
    {
        static $sequence = 0;
        $sequence++;

        return Category::create([
            'code' => 'FIXTURE-' . $sequence,
            'slug' => 'fixture-' . $sequence,
            'name_ar' => 'تصنيف تجريبي ' . $sequence,
            'name_en' => 'Fixture Category ' . $sequence,
        ]);
    }

    protected function productVariant(Organization $organization, ?Category $category = null): ProductVariant
    {
        $category ??= $this->category();
        $suffix = Str::upper(Str::random(6));

        $product = Product::create([
            'organization_id' => $organization->id,
            'category_id' => $category->id,
            'sku' => 'PRD-' . $suffix,
            'name_ar' => 'منتج تجريبي ' . $suffix,
            'name_en' => 'Fixture Product ' . $suffix,
            'unit' => 'carton',
        ]);

        return ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'VAR-' . $suffix,
            'name_ar' => 'عبوة تجريبية ' . $suffix,
            'name_en' => 'Fixture Variant ' . $suffix,
        ]);
    }

    /**
     * A published, reservable offer carrying 100 units by default.
     * `total = available + reserved + sold` always holds because the schema
     * enforces it.
     *
     * @param array<string, mixed> $attributes
     */
    protected function offer(
        Organization $seller,
        array $attributes = [],
        ?Warehouse $warehouse = null,
        ?ProductVariant $variant = null
    ): Offer {
        $variant ??= $this->productVariant($seller);

        $quantity = (float) ($attributes['total_quantity'] ?? 100.0);

        // Unguarded, for the same reason `Illuminate\Database\Eloquent\Factory`
        // is: this helper states what the row *is*, including the four columns
        // the model refuses to take from a request — `status` and the three
        // quantities. Guarded mass assignment there is a protection against a
        // caller; a test fixture is not a caller, and a guard applied to it
        // turns every one of those keys into a silently dropped column, which
        // MySQL then reports as "field has no default value".
        $offer = Offer::unguarded(fn () => Offer::create(array_merge([
            'seller_organization_id' => $seller->id,
            'warehouse_id' => ($warehouse ?? $this->warehouse($seller))->id,
            'product_variant_id' => $variant->id,
            'title_ar' => 'عرض تجريبي ' . Str::random(5),
            'opportunity_reason' => Offer::REASON_SURPLUS_STOCK,
            'status' => Offer::STATUS_ACTIVE,
            'min_order_quantity' => 1.00,
            'total_quantity' => $quantity,
            'available_quantity' => $quantity,
            'reserved_quantity' => 0.00,
            'sold_quantity' => 0.00,
            'unit' => 'carton',
            'unit_price' => 25000.0000,
            'currency_code' => 'YER',
            'published_at' => now()->subMinute(),
            'expires_at' => now()->addDays(7),
        ], $attributes)));

        // A published offer always carries its line item; store() and the
        // seeder both write one, so a fixture that skipped it would describe a
        // row the application can never produce.
        OfferItem::updateOrCreate(
            ['offer_id' => $offer->id, 'product_variant_id' => $variant->id],
            [
                'product_id' => $variant->product_id,
                'quantity' => $offer->total_quantity,
                'unit_price' => $offer->unit_price,
                'currency_code' => $offer->currency_code,
            ]
        );

        return $offer;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function demand(Organization $buyer, User $user, array $attributes = []): Demand
    {
        return Demand::create(array_merge([
            'demand_number' => 'DEM-' . Str::upper(Str::random(8)),
            'buyer_organization_id' => $buyer->id,
            'buyer_user_id' => $user->id,
            'title_ar' => 'طلب شراء تجريبي',
            'target_quantity' => 40.00,
            'unit' => 'carton',
            'currency_code' => 'YER',
            'delivery_city' => self::CITY,
            'delivery_latitude' => self::LAT,
            'delivery_longitude' => self::LNG,
            'status' => Demand::STATUS_OPEN,
            'expires_at' => now()->addDays(5),
        ], $attributes));
    }

    /**
     * A buyer ask awaiting the seller's answer.
     *
     * @param array<string, mixed> $attributes
     */
    protected function purchaseRequest(
        Demand $demand,
        Offer $offer,
        User $buyerUser,
        array $attributes = []
    ): PurchaseRequest {
        return PurchaseRequest::create(array_merge([
            'purchase_request_number' => 'PR-' . now()->format('ymd') . '-' . Str::upper(Str::random(6)),
            'demand_id' => $demand->id,
            'offer_id' => $offer->id,
            'buyer_organization_id' => $demand->buyer_organization_id,
            'seller_organization_id' => $offer->seller_organization_id,
            'buyer_user_id' => $buyerUser->id,
            'requested_quantity' => (float) $demand->target_quantity,
            'unit' => $offer->unit,
            'requested_price' => (float) $offer->unit_price,
            'currency_code' => $offer->currency_code,
            'delivery_preference' => 'PICKUP',
            'status' => PurchaseRequest::STATUS_REQUESTED,
        ], $attributes));
    }

    /**
     * A deal closed against a real offer.
     *
     * Reports and pricing tests need rows in a finished state; writing them by
     * hand produced totals that no service could compute, so the amounts here
     * are derived from the offer and stay consistent unless a test overrides
     * them. The row is placed directly in `COMPLETED` rather than walked there:
     * a test of the walk uses the API, and a test of a total needs one number.
     *
     * Note what this means: a fixture deal has no handover row, because the code
     * is issued by acceptance and spent by a scan — two events this shortcut
     * skips. A test that needs them goes through the endpoints.
     *
     * @param array<string, mixed> $attributes
     */
    protected function deal(
        Offer $offer,
        Organization $buyer,
        User $buyerUser,
        array $attributes = []
    ): Deal {
        $merged = array_merge([
            'quantity' => 10.00,
            'unit_price' => (float) $offer->unit_price,
        ], $attributes);

        $baseAmount = (float) $merged['quantity'] * (float) $merged['unit_price'];
        $feeRate = (float) ($merged['platform_fee_rate_snapshot'] ?? 0);
        $fee = round($baseAmount * $feeRate, 4);

        return Deal::create(array_merge([
            'deal_number' => 'DL-' . Str::upper(Str::random(8)),
            'offer_id' => $offer->id,
            'buyer_organization_id' => $buyer->id,
            'seller_organization_id' => $offer->seller_organization_id,
            'buyer_user_id' => $buyerUser->id,
            'quantity' => $merged['quantity'],
            'unit_price' => $merged['unit_price'],
            'currency_code' => $offer->currency_code,
            'base_amount' => $baseAmount,
            'platform_fee_rate_snapshot' => $feeRate,
            'platform_fee_amount' => $fee,
            'buyer_total_amount' => $baseAmount,
            'seller_net_amount' => $baseAmount - $fee,
            'status' => Deal::STATUS_COMPLETED,
            'completed_at' => now(),
        ], $merged));
    }

    /**
     * Headers that place an organization in context for one request.
     *
     * @return array<string, string>
     */
    protected function withTenant(Organization $organization): array
    {
        return ['X-Organization-Id' => (string) $organization->id];
    }

    /**
     * The one step a deal screen performs: move an order to a state.
     *
     * The tenant header is part of the call, not decoration — a transition is
     * decided *as* a business, and the same account sitting in another one is
     * refused. It lived twice, identically, in the two suites that walk a deal;
     * a third suite asking the same question would have written it a third time
     * and then tested a helper rather than the endpoint.
     *
     * @param array<string, mixed> $payload
     */
    protected function move(
        int $dealId,
        string $status,
        User $user,
        Organization $organization,
        array $payload = []
    ): TestResponse {
        Sanctum::actingAs($user);

        return $this->postJson('/api/v1/deals/'.$dealId.'/transition', array_merge([
            'status' => $status,
        ], $payload), $this->withTenant($organization));
    }

    /**
     * The same step a phone at the loading bay takes.
     *
     * @param array<string, mixed> $payload
     */
    protected function scan(
        int $dealId,
        string $code,
        User $user,
        Organization $organization,
        array $payload = []
    ): TestResponse {
        Sanctum::actingAs($user);

        return $this->postJson('/api/v1/deals/'.$dealId.'/handover/verify', array_merge([
            'code' => $code,
        ], $payload), $this->withTenant($organization));
    }

    /**
     * A deal already formed against held stock, which is the only way a deal
     * comes into existence.
     *
     * Written here rather than in the suite that first needed it because three
     * suites now walk an order, and a fixture copied twice drifts: one copy
     * keeps asserting the created status while the other quietly stops, and the
     * two then disagree about what "a deal in flight" is.
     *
     * @return array{0: User, 1: Organization, 2: User, 3: Organization, 4: Offer, 5: Deal}
     */
    protected function dealInFlight(float $quantity = 40.0): array
    {
        [$buyerUser, $buyer] = $this->accountWithOrganization('BuyerAdmin', 'buyer', 'محل');
        [$sellerUser, $seller] = $this->accountWithOrganization('SellerAdmin', 'seller', 'مورد');

        $offer = $this->offer($seller);
        $reservation = $this->reserveDirectly($offer, $buyerUser, $buyer, $quantity);

        Sanctum::actingAs($buyerUser);
        $deal = $this->postJson('/api/v1/deals', [
            'reservation_id' => $reservation->id,
            'delivery_method' => 'PICKUP',
        ], $this->withTenant($buyer))
            ->assertCreated()
            ->assertJsonPath('data.status', Deal::STATUS_PENDING_SELLER_APPROVAL);

        return [
            $buyerUser,
            $buyer,
            $sellerUser,
            $seller,
            $offer->fresh(),
            Deal::find($deal->json('data.id')),
        ];
    }

    /**
     * The account that owns an organization built by the fixture helpers.
     */
    protected function ownerOf(Organization $organization): User
    {
        return User::findOrFail(
            OrganizationMembership::where('organization_id', $organization->id)->value('user_id')
        );
    }

    /**
     * An account whose only authority is the platform's own: no organization of
     * its own, the SuperAdmin role that lets it rule on somebody else's order.
     */
    protected function platformUser(): User
    {
        $user = $this->user('مشرف المنصة');
        $user->assignRole('SuperAdmin');

        return $user;
    }

    /**
     * Hold stock through the real reservation service.
     *
     * Scenarios that begin "the buyer already has a reservation" use this
     * rather than a hand-written row: the reservation is the thing under test
     * everywhere else, and inventing one here would let a broken service pass.
     */
    protected function reserveDirectly(Offer $offer, User $buyerUser, Organization $buyer, float $quantity = 10.0): Reservation
    {
        return app(InventoryReservationService::class)->reserve(
            $offer,
            $buyerUser,
            (int) $buyer->id,
            $quantity
        );
    }
}
