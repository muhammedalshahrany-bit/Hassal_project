<?php

namespace Database\Seeders;

use App\Trading\Exceptions\OfferExpiredException;
use App\Catalog\Models\Category;
use App\Trading\Models\Deal;
use App\Trading\Models\DealHandover;
use App\Trading\Models\DealItem;
use App\Marketplace\Models\Demand;
use App\Trading\Models\Dispute;
use App\Marketplace\Models\Offer;
use App\Community\Models\OfferComment;
use App\Marketplace\Models\OfferItem;
use App\Community\Models\OfferLike;
use App\Organizations\Models\Organization;
use App\Organizations\Models\OrganizationMembership;
use App\Organizations\Models\OrganizationRole;
use App\Catalog\Models\Product;
use App\Catalog\Models\ProductVariant;
use App\Trading\Models\PurchaseRequest;
use App\Marketplace\Models\Quotation;
use App\Trading\Models\Reservation;
use App\SupportDesk\Models\SupportTicket;
use App\Identity\Models\User;
use App\Inventory\Models\Warehouse;
use App\Trading\Services\DealHandoverService;
use App\Trading\Services\DealStateMachineService;
use App\Marketplace\Services\DemandMatchingService;
use App\Trading\Services\DoubleEntryLedgerService;
use App\Platform\Services\FeatureFlagService;
use App\Trading\Services\InventoryReservationService;
use App\Trading\Services\PricingAndCurrencyService;
use App\Platform\Services\SystemSettingsService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A complete, interconnected sandbox: the whole sales cycle in every state it can
 * actually reach, produced by the application's own services.
 *
 * This file is the opposite of `DatabaseSeeder` and the two must not be confused.
 * `DatabaseSeeder` writes reference data — roles, settings, currencies, categories
 * — and deliberately invents nobody. This one invents everyone, so it refuses to
 * run on a production environment unless the caller also types `--force`, and it
 * is only ever reached by name, on top of an installation that has already run its
 * installer:
 *
 *   php artisan migrate:fresh
 *   php artisan app:install-system          # reference data + the platform's one operator
 *   php artisan db:seed --class=SandboxSeeder
 *
 * Those three lines are the whole procedure, and this file will not run on a
 * database that has been seeded with it before. The trades are append-only — a
 * second order on the same listing is a second order, not an update — so a second
 * run would leave two demo worlds whose held quantities no longer add up to the
 * shelves they came from. The seeder says so rather than producing that.
 *
 * **Everything here is written through the services that write it in production**
 * (`InventoryReservationService`, `DealStateMachineService`, `DealHandoverService`,
 * `PricingAndCurrencyService`, `DemandMatchingService`). That is the point of the
 * file. Rows placed by hand produced a demo world no code path could have reached:
 * offers whose four quantity columns disagreed, deals in `COMPLETED` with no status
 * history, and handover codes that had never been issued because the acceptance
 * that issues them had never happened. Screens built on that data looked correct
 * and proved nothing.
 *
 * What the sandbox therefore holds:
 *
 *   businesses   eight organizations across every type and every KYC state, staffed
 *                with real accounts holding each of the five standings
 *   listings     offers in DRAFT, PENDING_REVIEW, ACTIVE, PAUSED, SOLD_OUT, EXPIRED
 *                and CANCELLED — SOLD_OUT and EXPIRED are written only by the
 *                reservation service, so the service wrote them
 *   asks         demands in every state, with quotations and the matches the
 *                matching service produced for them
 *   requests     purchase requests in all five states, including one whose seller
 *                acceptance is what holds stock right now
 *   orders       deals walked into eleven of the twelve states. `DRAFT` is absent
 *                because nothing creates one: `DealController::store()` opens an
 *                order at `PENDING_SELLER_APPROVAL`, so a seeder that wrote the
 *                twelfth state would be documenting a state the product does not
 *                have
 *   handovers    codes ACTIVE, USED and INVALIDATED, spent through
 *                `DealHandoverService::verify()` — one scan from a camera payload,
 *                one typed by hand
 *   disputes     one open, one under review and ruled to let the order stand, one
 *                awaiting evidence and ruled to call the order off
 *
 * Credentials are not in this file. Every business account shares the password at
 * `HASSAL_SANDBOX_PASSWORD`; only the emails are written here, because the email is
 * the half a person types. The platform operator is never created by a seeder —
 * `app:install-system` is the only door, and this file reads the account it left.
 *
 * Two configuration values are raised above their production-shipped zeros: a
 * commission rate, so the reports have something to report, and the ledger flag
 * with its chart of accounts, so the books screen has a book. Both are operator
 * decisions in a real installation, and `DatabaseSeeder` still ships them off.
 */
class SandboxSeeder extends Seeder
{
    protected const CITY = 'عدن';
    protected const LAT = 12.7855000;
    protected const LNG = 45.0187000;

    /** @var array<string, array{org: Organization, users: array<string, User>, warehouse: Warehouse}> */
    protected array $businesses = [];

    /** @var array<string, ProductVariant> */
    protected array $variants = [];

    /** @var array<string, Offer> */
    protected array $offers = [];

    /** @var array<int, User> accounts with no membership — the consumer context */
    protected array $consumers = [];

    protected SystemSettingsService $settings;
    protected InventoryReservationService $reservations;
    protected DealStateMachineService $stateMachine;
    protected DealHandoverService $handover;
    protected PricingAndCurrencyService $pricing;
    protected string $password;
    protected ?User $admin = null;

    public function run(): void
    {
        // A production host does not get invented businesses, invented trades or
        // a shared demo password. The one way past that is to say it twice: once
        // in the environment file, and once on the command line, where the person
        // running it has to type `--force`. A bare `db:seed` in production still
        // runs `DatabaseSeeder`, which invents nobody.
        $forced = $this->seededWithForce();

        if (app()->environment('production') && !$forced) {
            throw new RuntimeException(
                'SandboxSeeder invents businesses, staff and trades. It will not run against a production '
                . 'database. A development machine that has set APP_ENV=production for hardening reasons may '
                . 'override this with --force, and should remember that every number this file writes is made up.'
            );
        }

        $this->password = (string) (config('hassal.sandbox.password') ?? '');

        if ($this->password === '') {
            throw new RuntimeException(
                'SandboxSeeder will not invent a password for the accounts it creates. '
                . 'Set HASSAL_SANDBOX_PASSWORD (12 characters or more) in this environment first.'
            );
        }

        if (mb_strlen($this->password) < 12) {
            throw new RuntimeException('HASSAL_SANDBOX_PASSWORD must be at least 12 characters, like every real password here.');
        }

        // This world is built once, on an empty database.
        //
        // The trades are not `updateOrCreate`d by a natural key: a second order
        // for the same offer is a second order, not an update, and a run that
        // continued past businesses would invent a parallel set of deals whose
        // quantities no longer add up to the shelf they were reserved from. A
        // half-finished world is the worst thing a demo database can be — it is
        // the one a screen is then built against. So the seeder says what state
        // the database is in and names the way out instead of adding to it.
        //
        // The marker is the `sandbox` flag every organization here carries in its
        // metadata, not a row count: a real installation with one genuine business
        // must still be allowed to seed a demo world next to it.
        if (Organization::where('metadata->sandbox', true)->exists()) {
            throw new RuntimeException(
                'This database already holds a sandbox world, and building a second one on top of it would leave '
                . 'the quantities, holds and order histories of the two mixed. Start from an empty database: '
                . '`php artisan migrate:fresh --force`, then `php artisan app:install-system`, then this command.'
            );
        }

        $this->call(DatabaseSeeder::class);

        $this->settings = app(SystemSettingsService::class);
        $this->reservations = app(InventoryReservationService::class);
        $this->stateMachine = app(DealStateMachineService::class);
        $this->handover = app(DealHandoverService::class);
        $this->pricing = app(PricingAndCurrencyService::class);

        // Read, never created: an installation that has not run its installer has
        // no operator, and the disputes that need a ruling stay unruled until it
        // does. See `ruling()`.
        $this->admin = User::role(User::PLATFORM_OPERATOR_ROLE)->orderBy('id')->first();

        // A platform taking no commission has an empty report screen, and a book
        // that was never opened has an empty ledger. Both are set here and nowhere
        // else, so a real install still starts from the honest zeros.
        $this->settings->set('commission.rate', '0.0100', 'decimal', 'commission');
        app(FeatureFlagService::class)->setFlag('double_entry_ledger', true);
        app(DoubleEntryLedgerService::class)->ensureChartOfAccounts();

        $this->buildBusinesses();
        $this->buildCatalog();
        $this->buildOffers();
        $this->buildDemands();
        $this->buildPurchaseRequests();
        $this->buildOrders();
        $this->buildReservationEnds();
        $this->buildDisputes();
        $this->buildConsumers();

        $this->report();
    }

    /**
     * Whether the caller typed `--force` on `db:seed`.
     *
     * Read defensively, because a seeder can also be run from a test where no
     * console command stands behind it at all. The answer in that case is "no",
     * which is the safe one: the guard above then depends only on the environment.
     */
    protected function seededWithForce(): bool
    {
        try {
            return (bool) $this->command?->option('force');
        } catch (Throwable) {
            return false;
        }
    }

    // ─── Businesses and the people who work in them ──────────────────────────

    /**
     * Every organization, its staff, and its warehouse, in one table.
     *
     * All five standings appear (`config/rbac.membership_roles`), because the
     * member list, the roles screen and the permission gate inside every
     * controller are the three places a missing standing shows up as a bug rather
     * than as an empty row.
     *
     * @return array<int, array<int, mixed>>
     */
    protected function businessBlueprint(): array
    {
        return [
            ['amal', 'seller', 'مؤسسة الأمل للأغذية', 'Al-Amal Foods', Organization::STATUS_VERIFIED,
                ['owner', 'admin', 'staff', 'finance', 'auditor'],
                ['هدى الأهدل', 'ماجد الأهدل', 'سامي باوزير', 'ريم السقاف', 'نادر بامطرف']],
            ['majid', 'seller', 'شركة ماجد للمجمدات', 'Majid Frozen Foods', Organization::STATUS_VERIFIED,
                ['owner', 'staff', 'finance'],
                ['خالد ماجد', 'فتحي سالم', 'أمل قاسم']],
            ['sahil', 'seller', 'مطاحن الساحل', 'Sahil Mills', Organization::STATUS_VERIFIED,
                ['owner', 'admin', 'staff'],
                ['ياسر الساحلي', 'منير الساحلي', 'طارق الحداد']],
            ['sultan', 'buyer', 'مطعم السلطان', 'Sultan Restaurant', Organization::STATUS_VERIFIED,
                ['owner', 'staff', 'finance'],
                ['عبده السلطان', 'سالم النوباني', 'هدى ناصر']],
            ['nakhil', 'buyer', 'بقالة النخيل', 'Al-Nakhil Grocery', Organization::STATUS_VERIFIED,
                ['owner', 'staff'],
                ['فاضل النخيلي', 'إخلاص عوض']],
            ['fajr', 'buyer', 'مخبز الفجر', 'Fajr Bakery', Organization::STATUS_VERIFIED,
                ['owner', 'admin', 'finance'],
                ['أمين الفجري', 'سعيد بن شحنة', 'مرفت هيثم']],
            ['wasta', 'intermediary', 'وسيط الميناء التجاري', 'Port Trade Broker', Organization::STATUS_PENDING,
                ['owner'], ['وليد الوسيطي']],
            ['qaria', 'seller', 'بقالة القرية', 'Qaria Grocery', Organization::STATUS_SUSPENDED,
                ['owner'], ['سالم القرية']],
        ];
    }

    protected function buildBusinesses(): void
    {
        foreach ($this->businessBlueprint() as [$slug, $type, $nameAr, $nameEn, $status, $standings, $people]) {
            $organization = Organization::create([
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'trade_name_ar' => $nameAr,
                'type' => $type,
                'country_code' => 'YE',
                'city' => self::CITY,
                'phone' => '+967 2 ' . str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT),
                'email' => $slug . '@hassal.test',
                'commercial_registration_number' => 'CR-' . Str::upper($slug) . '-001',
                'vat_number' => 'VAT-' . Str::upper($slug) . '-001',
                'status' => $status,
                // `rating` is left alone on purpose. Nothing in this product
                // computes one (see the migration that erased its default), so a
                // seeder that filled it would be putting an unsupported claim on
                // the same screen that tells a buyer whom to trust.
                'kyc_verified_at' => $status === Organization::STATUS_VERIFIED ? now()->subDays(40) : null,
                'metadata' => ['sandbox' => true],
            ]);

            // The company's own role rows, from the same code path the platform
            // runs when a business registers — not a hand-typed copy of the config.
            OrganizationRole::provisionOrganization($organization->id);

            $users = [];

            foreach ($standings as $index => $standing) {
                $user = User::create([
                    'name' => $people[$index],
                    'email' => $slug . '.' . $standing . '@hassal.test',
                    'phone' => '+96777' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT),
                    'password' => $this->password,
                    'status' => 'active',
                    'email_verified_at' => now(),
                    'phone_verified_at' => now(),
                ]);

                OrganizationMembership::create([
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'role' => $standing,
                    'is_active' => true,
                    'joined_at' => now()->subDays(40 - $index),
                ]);

                $users[$standing] = $user;
            }

            $this->businesses[$slug] = [
                'org' => $organization,
                'users' => $users,
                'warehouse' => Warehouse::create([
                    'organization_id' => $organization->id,
                    'name' => 'مستودع ' . $nameAr,
                    'code' => 'WH-' . Str::upper($slug),
                    'city' => self::CITY,
                    'address_line' => 'المنطقة الصناعية، ' . self::CITY,
                    'latitude' => self::LAT + (random_int(-30, 30) / 10000),
                    'longitude' => self::LNG + (random_int(-30, 30) / 10000),
                    'is_active' => true,
                ]),
            ];
        }
    }

    // ─── Catalogue ───────────────────────────────────────────────────────────

    protected function buildCatalog(): void
    {
        $catalogue = [
            ['amal', 'DAIRY', 'AMAL-MILK', 'حليب طازج ١ لتر', 'Fresh Milk 1L', 'كرتون', 'AMAL-MILK-12'],
            ['amal', 'OILS_FATS', 'AMAL-OIL', 'زيت دوار الشمس ١.٨ لتر', 'Sunflower Oil 1.8L', 'كرتون', 'AMAL-OIL-6'],
            ['amal', 'OILS_FATS', 'AMAL-GHEE', 'سمن بلدي', 'Ghee', 'كرتون', 'AMAL-GHEE-10'],
            ['majid', 'FROZEN', 'MAJD-CHKN', 'دجاج مجمد', 'Frozen Chicken', 'كرتون', 'MAJD-CHKN-20'],
            ['majid', 'CANNED', 'MAJD-TUNA', 'تونة معلبة', 'Canned Tuna', 'كرتون', 'MAJD-TUNA-48'],
            ['sahil', 'DRY_GOODS', 'SAHL-RICE', 'أرز بسمتي', 'Basmati Rice', 'كيس', 'SAHL-RICE-25'],
            ['sahil', 'BAKERY_RAW', 'SAHL-FLOUR', 'دقيق قمح', 'Wheat Flour', 'كيس', 'SAHL-FLOUR-50'],
            ['sahil', 'DRY_GOODS', 'SAHL-SUGAR', 'سكر أبيض', 'White Sugar', 'كيس', 'SAHL-SUGAR-50'],
            ['qaria', 'CLEANING', 'QARI-SOAP', 'صابون منظف', 'Cleaning Soap', 'كرتون', 'QARI-SOAP-24'],
        ];

        foreach ($catalogue as [$sellerSlug, $categoryCode, $sku, $nameAr, $nameEn, $unit, $variantKey]) {
            $seller = $this->businesses[$sellerSlug]['org'];
            $suffix = Str::upper(Str::random(4));

            $product = Product::create([
                'organization_id' => $seller->id,
                'category_id' => Category::where('code', $categoryCode)->value('id'),
                'sku' => $sku . '-' . $suffix,
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'unit' => $unit,
            ]);

            $this->variants[$variantKey] = ProductVariant::create([
                'product_id' => $product->id,
                'sku' => $variantKey . '-' . $suffix,
                'barcode' => '621' . random_int(100000000000, 999999999999),
                'name_ar' => $nameAr . ' — عبوة جملة',
                'name_en' => $nameEn . ' — wholesale pack',
                'packaging_spec' => $unit,
            ]);
        }
    }

    // ─── Listings ────────────────────────────────────────────────────────────

    protected function buildOffers(): void
    {
        $rows = [
            // key, seller, variant, status, total, unit price, reason, title, featured
            ['milk', 'amal', 'AMAL-MILK-12', Offer::STATUS_ACTIVE, 400.00, 6500.0000, Offer::REASON_SURPLUS_STOCK, 'حليب طازج — فائض الأسبوع', true],
            ['oil', 'amal', 'AMAL-OIL-6', Offer::STATUS_ACTIVE, 240.00, 14500.0000, Offer::REASON_SLOW_MOVING, 'زيت دوار الشمس بالجملة', true],
            ['ghee-last', 'amal', 'AMAL-GHEE-10', Offer::STATUS_ACTIVE, 20.00, 18500.0000, Offer::REASON_LIQUIDATION, 'سمن بلدي — آخر الكمية', false],
            ['ghee-door', 'amal', 'AMAL-GHEE-10', Offer::STATUS_ACTIVE, 60.00, 18200.0000, Offer::REASON_SEASONAL_STOCK, 'سمن بلدي — جاهز للتسليم', false],
            ['chicken', 'majid', 'MAJD-CHKN-20', Offer::STATUS_ACTIVE, 300.00, 21000.0000, Offer::REASON_SEASONAL_STOCK, 'دجاج مجمد — عبوة المطاعم', false],
            ['tuna', 'majid', 'MAJD-TUNA-48', Offer::STATUS_ACTIVE, 180.00, 9800.0000, Offer::REASON_EXCESS_INVENTORY, 'تونة معلبة', true],
            ['rice', 'sahil', 'SAHL-RICE-25', Offer::STATUS_ACTIVE, 500.00, 32000.0000, Offer::REASON_SURPLUS_STOCK, 'أرز بسمتي — كيس ٢٥ كجم', false],
            ['flour', 'sahil', 'SAHL-FLOUR-50', Offer::STATUS_ACTIVE, 260.00, 28000.0000, Offer::REASON_LOW_LOCAL_DEMAND, 'دقيق قمح للمخابز', false],
            ['sugar', 'sahil', 'SAHL-SUGAR-50', Offer::STATUS_ACTIVE, 200.00, 24000.0000, Offer::REASON_SURPLUS_STOCK, 'سكر أبيض', false],
            ['sugar-draft', 'sahil', 'SAHL-SUGAR-50', Offer::STATUS_DRAFT, 120.00, 24500.0000, Offer::REASON_SLOW_MOVING, 'سكر أبيض — لم يُنشر بعد', false],
            ['milk-paused', 'amal', 'AMAL-MILK-12', Offer::STATUS_PAUSED, 60.00, 6800.0000, Offer::REASON_SEASONAL_STOCK, 'حليب طازج — موقوف مؤقتًا', false],
            ['oil-review', 'amal', 'AMAL-OIL-6', Offer::STATUS_PENDING_REVIEW, 75.00, 15000.0000, Offer::REASON_SURPLUS_STOCK, 'زيت جديد — بانتظار مراجعة المنصة', false],
            ['rice-cancelled', 'sahil', 'SAHL-RICE-25', Offer::STATUS_CANCELLED, 40.00, 33000.0000, Offer::REASON_BRANCH_CLOSURE, 'أرز — سحبت المنشأة العرض', false],
            ['soap', 'qaria', 'QARI-SOAP-24', Offer::STATUS_ACTIVE, 90.00, 7200.0000, Offer::REASON_LIQUIDATION, 'صابون منظف — تصفية', false],
        ];

        foreach ($rows as [$key, $sellerSlug, $variantKey, $status, $total, $price, $reason, $title, $featured]) {
            $this->offers[$key] = $this->listing($sellerSlug, $variantKey, $status, $total, $price, $reason, $title, $featured);
        }

        // SOLD_OUT and EXPIRED are the two listing states no seller writes. One
        // comes from the quantity columns reaching zero, the other from the
        // reservation service meeting a dead date — so the machine produced both,
        // and the second is produced here by the very attempt that fails.
        $this->offers['rice-expired'] = $this->listing(
            'sahil', 'SAHL-RICE-25', Offer::STATUS_ACTIVE, 45.00, 31500.0000,
            Offer::REASON_SLOW_MOVING, 'أرز — انتهت فترة الإعلان', false,
            ['expires_at' => now()->subHour()]
        );

        try {
            $this->reservations->reserve(
                $this->offers['rice-expired'],
                $this->businesses['fajr']['users']['owner'],
                (int) $this->businesses['fajr']['org']->id,
                10.00
            );
        } catch (OfferExpiredException) {
            // Expected: the service rolls the attempt back and marks the listing
            // EXPIRED, which is the only way that state is ever reached.
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    protected function listing(
        string $sellerSlug,
        string $variantKey,
        string $status,
        float $total,
        float $price,
        string $reason,
        string $title,
        bool $featured,
        array $extra = []
    ): Offer {
        $seller = $this->businesses[$sellerSlug];
        $variant = $this->variants[$variantKey] ?? throw new RuntimeException("Unknown sandbox variant '{$variantKey}'.");

        $offer = Offer::unguarded(fn () => Offer::create(array_merge([
            'seller_organization_id' => $seller['org']->id,
            'warehouse_id' => $seller['warehouse']->id,
            'product_variant_id' => $variant->id,
            'title_ar' => $title,
            'title_en' => $variant->name_en,
            'description_ar' => 'بيان ضمن بيئة العرض؛ الكميات والأسعار ليست عروضًا حقيقية.',
            'opportunity_reason' => $reason,
            'status' => $status,
            'show_seller_identity' => true,
            'min_order_quantity' => 5.00,
            'total_quantity' => $total,
            'available_quantity' => $total,
            'reserved_quantity' => 0.00,
            'sold_quantity' => 0.00,
            'unit' => $variant->product->unit,
            'unit_price' => $price,
            'currency_code' => 'YER',
            'storage_condition' => 'جاف ومظلل',
            // `delivery_terms` is a code the whole platform reads — the ask sheet
            // opens or closes its two delivery halves on it, and `?delivery_terms=`
            // compares against it. The Arabic phrase this row used to hold was what
            // a card would have shown, not what the column is for; `Offer`
            // validates the three codes on every write, so a seeder that skipped
            // the vocabulary produced rows no filter could ever match.
            'delivery_terms' => Offer::DELIVERY_BUYER_PICKUP,
            'inspection_window_hours' => 24,
            'published_at' => $status === Offer::STATUS_DRAFT ? null : now()->subDays(random_int(1, 9)),
            'expires_at' => now()->addDays(random_int(10, 40)),
        ], $extra)));

        OfferItem::create([
            'offer_id' => $offer->id,
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'quantity' => $total,
            'unit_price' => $price,
            'currency_code' => 'YER',
        ]);

        if ($featured) {
            $offer->forceFill(['featured_at' => now()->subHours(random_int(1, 40))])->save();
        }

        return $offer;
    }

    // ─── Demands, quotations and matches ─────────────────────────────────────

    protected function buildDemands(): void
    {
        $rows = [
            ['sultan', 'لبن طازج لمطاعم السلطان', 120.00, Demand::STATUS_OPEN, 15, 'DAIRY'],
            ['nakhil', 'زيت دوار الشمس للبقالة', 60.00, Demand::STATUS_QUOTED, 20, 'OILS_FATS'],
            ['fajr', 'دقيق قمح لمخبز الفجر', 80.00, Demand::STATUS_ACCEPTED, 30, 'BAKERY_RAW'],
            ['sultan', 'تونة معلبة — طلب مُلغي بعد الإغلاق', 40.00, Demand::STATUS_CLOSED, 45, 'CANNED'],
            ['nakhil', 'سكر أبيض — انتهى وقته', 25.00, Demand::STATUS_EXPIRED, -3, 'DRY_GOODS'],
        ];

        $matching = app(DemandMatchingService::class);

        foreach ($rows as [$slug, $title, $quantity, $status, $expiresInDays, $categoryCode]) {
            $buyer = $this->businesses[$slug];

            $demand = Demand::create([
                'demand_number' => 'DEM-' . now()->format('ymd') . '-' . Str::upper(Str::random(6)),
                'buyer_organization_id' => $buyer['org']->id,
                'buyer_user_id' => $buyer['users']['owner']->id,
                'category_id' => Category::where('code', $categoryCode)->value('id'),
                'title_ar' => $title,
                'description' => 'طلب احتياج ضمن بيئة العرض.',
                'target_quantity' => $quantity,
                'unit' => 'كرتون',
                'currency_code' => 'YER',
                'delivery_city' => self::CITY,
                'delivery_latitude' => self::LAT,
                'delivery_longitude' => self::LNG,
                'status' => $status,
                'expires_at' => now()->addDays($expiresInDays),
            ]);

            // The matches are the matching service's own output, so the discovery
            // screen shows what the algorithm actually produces today rather than
            // rows no distance or price rule ever agreed to.
            try {
                $matching->matchDemand($demand);
            } catch (Throwable $e) {
                report($e);
            }

            if ($status === Demand::STATUS_QUOTED || $status === Demand::STATUS_ACCEPTED) {
                $this->quote($demand, $status === Demand::STATUS_ACCEPTED);
            }
        }
    }

    protected function quote(Demand $demand, bool $accepted): void
    {
        $seller = $this->businesses['amal'];

        Quotation::create([
            'quotation_number' => 'QUO-' . now()->format('ymd') . '-' . Str::upper(Str::random(6)),
            'demand_id' => $demand->id,
            'seller_organization_id' => $seller['org']->id,
            'seller_user_id' => $seller['users']['owner']->id,
            'quoted_quantity' => (float) $demand->target_quantity,
            'unit_price' => 6600.0000,
            'currency_code' => $demand->currency_code,
            'total_amount' => round((float) $demand->target_quantity * 6600, 4),
            'validity_days' => 7,
            'notes' => 'عرض سعر ضمن بيئة العرض.',
            'status' => $accepted ? Quotation::STATUS_ACCEPTED : Quotation::STATUS_SUBMITTED,
            'accepted_at' => $accepted ? now()->subDay() : null,
        ]);
    }

    // ─── Purchase requests ───────────────────────────────────────────────────

    /**
     * All five states, each reached the way a business reaches it.
     *
     * The accepted one is the interesting row: a seller acceptance is the only
     * thing in this API that puts stock on hold, so it runs
     * `InventoryReservationService` exactly as `PurchaseRequestController::accept()`
     * does — the buyer's organization and the buyer's account, not the seller's.
     */
    protected function buildPurchaseRequests(): void
    {
        $buyer = $this->businesses['sultan'];

        $rows = [
            ['milk', 20.00, PurchaseRequest::STATUS_REQUESTED],
            ['oil', 12.00, PurchaseRequest::STATUS_PROPOSED],
            ['chicken', 30.00, PurchaseRequest::STATUS_REJECTED],
            ['flour', 15.00, PurchaseRequest::STATUS_CANCELLED],
            ['ghee-door', 10.00, PurchaseRequest::STATUS_ACCEPTED],
        ];

        foreach ($rows as [$offerKey, $quantity, $status]) {
            $offer = $this->offers[$offerKey];
            $sellerUser = $this->sellerUserOf($offer, 'owner');

            $request = PurchaseRequest::create([
                'purchase_request_number' => 'PRQ-' . now()->format('ymd') . '-' . Str::upper(Str::random(6)),
                'offer_id' => $offer->id,
                'buyer_organization_id' => $buyer['org']->id,
                'seller_organization_id' => $offer->seller_organization_id,
                'buyer_user_id' => $buyer['users']['owner']->id,
                'requested_quantity' => $quantity,
                'unit' => $offer->unit,
                'requested_price' => (float) $offer->unit_price,
                'currency_code' => $offer->currency_code,
                'delivery_preference' => 'PICKUP',
                'status' => $status,
                'notes' => 'طلب شراء ضمن بيئة العرض.',
            ]);

            if ($status !== PurchaseRequest::STATUS_REQUESTED) {
                $request->update(['seller_user_id' => $sellerUser->id]);
            }

            match ($status) {
                PurchaseRequest::STATUS_PROPOSED => $request->update([
                    'proposed_terms' => [
                        'unit_price' => round((float) $offer->unit_price * 1.04, 4),
                        'min_order_quantity' => 12,
                        'message' => 'السعر يرتفع قليلًا لهذه الكمية، وقابل للبحث عند ١٢ وحدة.',
                    ],
                ]),
                PurchaseRequest::STATUS_REJECTED => $request->update([
                    'notes' => 'رُفض الطلب: الكمية المطلوبة غير متوفرة هذا الأسبوع.',
                ]),
                PurchaseRequest::STATUS_ACCEPTED => $request->update([
                    'reservation_id' => $this->reservations->reserve(
                        $offer,
                        $buyer['users']['owner'],
                        (int) $buyer['org']->id,
                        $quantity,
                        'PICKUP',
                        'حجز أثر قبول الطلب ' . $request->purchase_request_number
                    )->id,
                ]),
                default => null,
            };
        }
    }

    /**
     * Which account of the selling company acts on one of its listings.
     */
    protected function sellerUserOf(Offer $offer, string $standing): User
    {
        foreach ($this->businesses as $business) {
            if ((int) $business['org']->id === (int) $offer->seller_organization_id) {
                return $business['users'][$standing] ?? $business['users']['owner'];
            }
        }

        throw new RuntimeException('A sandbox listing names a seller this seeder never created.');
    }

    // ─── Orders: the cycle itself ────────────────────────────────────────────

    /**
     * Walk the sales cycle into every state an order can actually reach.
     *
     * Each order opens on a live hold the way `DealController::store()` opens one
     * — pricing snapshot first, then the deal, then the reservation converted —
     * and is then moved by `DealStateMachineService`. Nobody writes `status`. That
     * is why the status histories, the accepted/dispatched/delivered timestamps,
     * the inspection deadlines, the handover codes and the offer counters agree at
     * the end: the machine wrote all of them.
     */
    protected function buildOrders(): void
    {
        // 1  Waiting on the seller's signature.
        $this->order('sultan', 'milk', 20.00, 'PENDING');

        // 2  Accepted: the order document exists and its code is printed.
        $this->order('nakhil', 'oil', 18.00, 'ACCEPTED');

        // 3  Picked and being packed.
        $this->order('fajr', 'flour', 40.00, 'PROCESSING');

        // 4  At the door, waiting for the buyer's truck.
        $this->order('sultan', 'chicken', 35.00, 'READY_FOR_PICKUP');

        // 5  On the road.
        $this->order('nakhil', 'rice', 22.00, 'DISPATCHED');

        // 6  Arrived and unscanned — the order that must refuse to close.
        $this->order('fajr', 'ghee-door', 12.00, 'DELIVERED');

        // 7  Scanned by a camera, inside the inspection window.
        $this->order('sultan', 'tuna', 25.00, 'INSPECTION');

        // 8  Scanned by hand, then confirmed: closed, and the fee accrued.
        $this->order('nakhil', 'milk', 30.00, 'COMPLETED');

        // 9  Closed for the last unit on the shelf: the listing is now SOLD_OUT.
        $this->order('fajr', 'ghee-last', 20.00, 'COMPLETED');

        // 10 Called off before the goods moved: quantity back on the listing,
        //    code retired in the same transaction.
        $this->order('sultan', 'rice', 16.00, 'CANCELLED');
    }

    /**
     * Open one order on a fresh hold and walk it to `$endsAt`.
     */
    protected function order(string $buyerSlug, string $offerKey, float $quantity, string $endsAt): Deal
    {
        $buyer = $this->businesses[$buyerSlug];
        $offer = $this->offers[$offerKey];
        $sellerUser = $this->sellerUserOf($offer, 'owner');
        $buyerUser = $buyer['users']['owner'];

        $reservation = $this->reservations->reserve(
            $offer,
            $buyerUser,
            (int) $buyer['org']->id,
            $quantity,
            'PICKUP',
            'حجز ضمن بيئة العرض'
        );

        $deal = $this->openOrder($reservation, $offer, $buyer['org'], $buyerUser, $quantity);

        $steps = [
            'PENDING' => [],
            'ACCEPTED' => [Deal::STATUS_ACCEPTED],
            'PROCESSING' => [Deal::STATUS_ACCEPTED, Deal::STATUS_PROCESSING],
            'READY_FOR_PICKUP' => [Deal::STATUS_ACCEPTED, Deal::STATUS_PROCESSING, Deal::STATUS_READY_FOR_PICKUP],
            'DISPATCHED' => [Deal::STATUS_ACCEPTED, Deal::STATUS_PROCESSING, Deal::STATUS_DISPATCHED],
            'DELIVERED' => [Deal::STATUS_ACCEPTED, Deal::STATUS_PROCESSING, Deal::STATUS_DISPATCHED, Deal::STATUS_DELIVERED],
            'INSPECTION' => [Deal::STATUS_ACCEPTED, Deal::STATUS_PROCESSING, Deal::STATUS_DISPATCHED, Deal::STATUS_DELIVERED, Deal::STATUS_INSPECTION],
            'COMPLETED' => [Deal::STATUS_ACCEPTED, Deal::STATUS_PROCESSING, Deal::STATUS_DISPATCHED, Deal::STATUS_DELIVERED, Deal::STATUS_COMPLETED],
            'CANCELLED' => [Deal::STATUS_ACCEPTED, Deal::STATUS_PROCESSING, Deal::STATUS_CANCELLED],
        ];

        if (!isset($steps[$endsAt])) {
            throw new RuntimeException("Unknown sandbox endpoint '{$endsAt}'.");
        }

        // The states the seller is the one who moves the order into, straight from
        // `DealStateMachineService::TRANSITION_RULES`. Getting this wrong is not a
        // seeder bug — it is a refusal, and the machine is right and this file
        // would be wrong.
        $sellerSteps = [
            Deal::STATUS_ACCEPTED, Deal::STATUS_PROCESSING, Deal::STATUS_READY_FOR_PICKUP,
            Deal::STATUS_DISPATCHED, Deal::STATUS_DELIVERED,
        ];

        $scanned = false;

        foreach ($steps[$endsAt] as $step) {
            // The code is spent between the delivery and the buyer's word — the
            // order in which the two businesses actually meet at the back of a
            // truck. A camera payload on one order and a typed code on the next,
            // because both are how a real scan arrives.
            if (!$scanned && in_array($step, [Deal::STATUS_INSPECTION, Deal::STATUS_COMPLETED], true)) {
                $this->attest($deal, $buyer, $sellerUser, $step === Deal::STATUS_INSPECTION);
                $scanned = true;
            }

            $bySeller = in_array($step, $sellerSteps, true);

            $this->stateMachine->transition(
                $deal,
                $step,
                $bySeller ? $sellerUser : $buyerUser,
                $step === Deal::STATUS_CANCELLED
                    ? 'اتُّفق بين الطرفين على إيقاف الطلب قبل تحرك الشحنة'
                    : 'انتقال ضمن بيئة العرض: ' . $step,
                null,
                $bySeller ? (int) $offer->seller_organization_id : (int) $buyer['org']->id
            );
        }

        return $deal->fresh();
    }

    /**
     * Mirror of `DealController::store()`: the terms are snapshotted once, here,
     * by the same pricing service the endpoint calls.
     */
    protected function openOrder(Reservation $reservation, Offer $offer, Organization $buyerOrg, User $buyerUser, float $quantity): Deal
    {
        $pricing = $this->pricing->calculateDealPricing(
            $quantity,
            (float) $reservation->unit_price,
            $offer->currency_code
        );

        // The pricing array is read key by key rather than merged whole, exactly
        // as the endpoint does it. Two of its figures — the line total in the
        // transaction currency and the display conversion — are not `deals`
        // columns at all; they exist for the response body, and copying the array
        // in wholesale asks the database for columns that were removed when the
        // duplicated currency facts were.
        $deal = Deal::create([
            'deal_number' => 'DL-' . now()->format('ymd') . '-' . Str::upper(Str::random(6)),
            'offer_id' => $offer->id,
            'reservation_id' => $reservation->id,
            'buyer_organization_id' => $buyerOrg->id,
            'seller_organization_id' => $offer->seller_organization_id,
            'buyer_user_id' => $buyerUser->id,
            'warehouse_id' => $offer->warehouse_id,
            'quantity' => $quantity,
            'unit_price' => (float) $reservation->unit_price,
            'currency_code' => $offer->currency_code,
            'base_currency' => $pricing['base_currency'],
            'exchange_rate_snapshot' => $pricing['exchange_rate_snapshot'],
            'rate_source' => $pricing['rate_source'],
            'rate_timestamp' => $pricing['rate_timestamp'],
            'base_amount' => $pricing['base_amount'],
            'discount_amount' => $pricing['discount_amount'],
            'tax_rate_snapshot' => $pricing['tax_rate_snapshot'],
            'tax_amount' => $pricing['tax_amount'],
            'platform_fee_rate_snapshot' => $pricing['platform_fee_rate_snapshot'],
            'platform_fee_amount' => $pricing['platform_fee_amount'],
            'delivery_fee_amount' => $pricing['delivery_fee_amount'],
            'buyer_total_amount' => $pricing['buyer_total_amount'],
            'seller_net_amount' => $pricing['seller_net_amount'],
            'status' => Deal::STATUS_PENDING_SELLER_APPROVAL,
            'delivery_method' => 'PICKUP',
            'delivery_address' => 'مستودع ' . $buyerOrg->name_ar . '، ' . self::CITY,
            'inspection_window_hours' => 24,
        ]);

        DealItem::create([
            'deal_id' => $deal->id,
            'product_variant_id' => $offer->product_variant_id,
            'snapshot_product_name' => $offer->variant->product->name_ar,
            'snapshot_sku' => $offer->variant->sku,
            'snapshot_unit_name' => $offer->unit,
            'quantity' => $quantity,
            'unit_price' => (float) $reservation->unit_price,
            'subtotal_amount' => $pricing['base_amount'],
        ]);

        $reservation->update([
            'status' => Reservation::STATUS_CONVERTED_TO_DEAL,
            'converted_at' => Carbon::now(),
        ]);

        return $deal;
    }

    /**
     * Spend the order's code at the door, as the buyer's staff.
     *
     * Staff rather than the owner, because that is who stands at the receiving
     * bay — and the scan endpoint's whole permission design assumes it.
     */
    protected function attest(Deal $deal, array $buyer, User $sellerUser, bool $fromCamera): void
    {
        $handover = $this->handover->forDeal($deal, $sellerUser->id);

        if ($handover === null) {
            throw new RuntimeException('An order in fulfillment reached the door without a handover code.');
        }

        $presented = $fromCamera ? $handover->payload($deal->fresh()) : $handover->code;

        $this->handover->verify(
            $deal,
            $presented,
            $buyer['users']['staff'] ?? $buyer['users']['owner'],
            (int) $buyer['org']->id,
            $fromCamera ? 'مسح بالكاميرا عند بوابة الاستلام' : 'رُمز التسليم كُتب يدويًا عند البوابة'
        );
    }

    // ─── The two ends a hold can come to ─────────────────────────────────────

    /**
     * A reservation that timed out and a reservation the buyer let go.
     *
     * Both are the service's own `expire()` and `release()`, which is also what
     * proves they still return the quantity to the shelf: the offer's four
     * quantity columns are read back after each one.
     */
    protected function buildReservationEnds(): void
    {
        $offer = $this->offers['sugar'];
        $buyer = $this->businesses['nakhil'];

        $expiring = $this->reservations->reserve(
            $this->offers['flour'],
            $buyer['users']['owner'],
            (int) $buyer['org']->id,
            10.00,
            'PICKUP',
            'حجز قصير المدى لم يُحوَّل إلى أمر'
        );

        // Five minutes, so the sandbox shows a hold that is visibly dying as well
        // as one that already died. The setting is put back afterwards: it is the
        // platform's configuration, and a seeder that left it changed would be a
        // seeder that changed how long a real buyer's hold lasts.
        $this->settings->set('reservation.timeout', '5', 'integer', 'operations');
        $this->reservations->reserve($offer, $buyer['users']['owner'], (int) $buyer['org']->id, 8.00, 'DELIVERY', 'حجز نشط بانتظار تحويله لأمر');
        $this->settings->set('reservation.timeout', (string) config('hassal.reservations.default_timeout_minutes', 30), 'integer', 'operations');

        $this->reservations->expire($expiring);

        $this->reservations->release(
            $this->reservations->reserve(
                $this->offers['tuna'],
                $this->businesses['fajr']['users']['owner'],
                (int) $this->businesses['fajr']['org']->id,
                6.00,
                'PICKUP',
                'حجز ألغاه المشتري'
            ),
            'المشتري لم يعد بحاجة إلى الكمية'
        );
    }

    // ─── Disputes ────────────────────────────────────────────────────────────

    /**
     * Three cases: one open, one awaiting evidence, and one under review whose
     * ruling let the order stand.
     *
     * Filing moves the order through the machine rather than setting
     * `deals.status` next to a dispute row, so "ruled" and "never disputed" are
     * two different things in the data — which is exactly what the platform's
     * queue has to tell apart. The fourth case, a ruling that calls the order off,
     * is only written when an operator exists to make it: see `ruling()`.
     */
    protected function buildDisputes(): void
    {
        // Reached the door, never scanned, and the buyer complained first.
        $open = $this->order('nakhil', 'chicken', 15.00, 'DELIVERED');
        $this->file($open, 'QUALITY_DEFECT', 'جزء من الكراتين وصل مكسورًا ولم يُتحقق من التسليم بعد.', 980000.0000, Dispute::STATUS_OPEN);

        // Awaiting evidence, and then ruled.
        $evidence = $this->order('fajr', 'sugar', 14.00, 'DELIVERED');
        $this->file($evidence, 'WRONG_ITEM', 'وصل صنف مختلف عن المتفق عليه في العرض.', 420000.0000, Dispute::STATUS_EVIDENCE_REQUIRED);

        // Scanned, delivered, and complained about afterwards — the case whose
        // ruling leaves the order alive, because the proof of handover exists.
        $standing = $this->order('sultan', 'tuna', 12.00, 'DELIVERED');
        $this->attest($standing, $this->businesses['sultan'], $this->sellerUserOf($this->offers['tuna'], 'owner'), false);
        $ruled = $this->file($standing, 'DELIVERY_DELAY', 'تأخرت الشحنة يومين لكن البضاعة مطابقة للمواصفات.', 250000.0000, Dispute::STATUS_UNDER_REVIEW);

        $this->ruling($ruled, Dispute::STATUS_RESOLVED_RELEASE, 'التأخير مثبت والبضاعة مطابقة؛ الأمر قائم ويُغلق بعد تأكيد الاستلام.');

        // And one ruled the other way: the quantity goes back to the shelf and the
        // code is retired, both by the machine rather than by this file.
        $calledOff = $this->order('sultan', 'oil', 9.00, 'DELIVERED');
        $cancelled = $this->file($calledOff, 'TEMPERATURE_ABUSE', 'الشحنة وصلت دون تبريد كافٍ.', 310000.0000, Dispute::STATUS_EVIDENCE_REQUIRED);

        $this->ruling($cancelled, Dispute::STATUS_RESOLVED_CANCELLATION, 'صور مقياس الحرارة حسمت الأمر: الطلب يُلغى وتعود الكمية إلى العرض.');
    }

    protected function file(Deal $deal, string $reason, string $description, float $claimed, string $status): Dispute
    {
        $initiatorOrgId = (int) $deal->buyer_organization_id;
        $initiatorUser = null;

        foreach ($this->businesses as $business) {
            if ((int) $business['org']->id === $initiatorOrgId) {
                $initiatorUser = $business['users']['owner'];
            }
        }

        $dispute = Dispute::create([
            'dispute_number' => 'DSP-' . now()->format('ymd') . '-' . Str::upper(Str::random(6)),
            'deal_id' => $deal->id,
            'initiator_organization_id' => $initiatorOrgId,
            'initiator_user_id' => $initiatorUser?->id,
            'reason' => $reason,
            'description' => $description,
            'claimed_amount' => $claimed,
            'evidence_urls' => ['https://sandbox.hassal.test/evidence/' . Str::lower(Str::random(8)) . '.jpg'],
            'status' => $status,
        ]);

        $this->stateMachine->transition(
            $deal,
            Deal::STATUS_DISPUTED,
            $initiatorUser,
            'Dispute opened: ' . $reason,
            null,
            $initiatorOrgId
        );

        return $dispute;
    }

    /**
     * Rule a case, the way `DisputeController::resolve()` does — through the
     * machine, which is what puts the order into `RESOLVED` or back onto the
     * shelf, and what retires a code that will never be scanned.
     *
     * With no operator on the database yet the case is left as filed. This seeder
     * does not create platform accounts; see the class docblock.
     */
    protected function ruling(Dispute $dispute, string $ruling, string $notes): void
    {
        if ($this->admin === null) {
            $this->command?->warn("No SuperAdmin exists, so dispute {$dispute->dispute_number} was left unruled. Run app:install-system and re-seed for the two closed cases.");

            return;
        }

        $deal = $dispute->deal;
        $cancels = $ruling === Dispute::STATUS_RESOLVED_CANCELLATION;

        if ($cancels) {
            $this->stateMachine->transition($deal, Deal::STATUS_RESOLVED, $this->admin, "Dispute {$dispute->dispute_number} ruled: {$notes}");
            $this->stateMachine->transition($deal->fresh(), Deal::STATUS_CANCELLED, $this->admin, "Dispute {$dispute->dispute_number}: order called off");
        } else {
            $this->stateMachine->transition($deal, Deal::STATUS_RESOLVED, $this->admin, "Dispute {$dispute->dispute_number} ruled: {$notes}");
        }

        $dispute->update([
            'status' => $ruling,
            'resolution_notes' => $notes,
            'resolved_by_user_id' => $this->admin->id,
            'resolved_at' => now()->subHours(random_int(1, 20)),
        ]);
    }

    // ─── The third context: people who own no business ────────────────────────

    /**
     * Consumer accounts — §6 of the platform's execution prompt names three
     * contexts, and the sandbox could previously demonstrate only two of them.
     *
     * These are real accounts with a verified phone and email and deliberately
     * no `organization_memberships` row. That absence is the whole point: the
     * browse-and-ask paths of both clients (`session.hasOrganization == false`)
     * were untestable against a seeded database until now, and a live check of
     * the consumer experience — public shelf, then the door that says
     * «أنشئ منشأتك لتشتري» instead of the seller console — needs somebody on
     * the other side of that line.
     *
     * They also file the two support tickets, ask and answer the marketplace
     * question below, and press the two hearts. One ticket carries no organization:
     * `support_tickets.organization_id` is nullable precisely for this reporter,
     * and the platform queue should show the row rather than lose it. The other
     * comes from an owner and is already answered, so the queue has both a
     * waiting case and a done one. `reference` is written after the insert
     * because it is derived from the id, exactly as `SupportController` does.
     */
    protected function buildConsumers(): void
    {
        $consumers = [];

        foreach ([
            ['سلمى المستوردة', 'salma.consumer@hassal.test'],
            ['طارق الزبون', 'tariq.consumer@hassal.test'],
        ] as [$name, $email]) {
            $consumers[] = User::create([
                'name' => $name,
                'email' => $email,
                'phone' => '+96777' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT),
                'password' => $this->password,
                'status' => 'active',
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
            ]);
        }

        $tickets = [
            [
                'user' => $consumers[0],
                'organization_id' => null,
                'category' => SupportTicket::CATEGORY_LISTING_TRADE,
                'message' => 'أسعار الأرز في صفحة العروض لا تطابق ما ظهر عند فتح العرض نفسه.',
                'screen' => 'marketplace_offer',
                'status' => SupportTicket::STATUS_OPEN,
            ],
            [
                'user' => $this->businesses['amal']['users']['owner'],
                'organization_id' => $this->businesses['amal']['org']->id,
                'category' => SupportTicket::CATEGORY_FUNCTIONAL_BUG,
                'message' => 'لم يصلني إشعار رمز التسليم بعد قبول طلب الشراء.',
                'screen' => 'deals',
                'status' => SupportTicket::STATUS_RESOLVED,
            ],
        ];

        // `forceFill` everywhere, not `create`: `SupportTicket` is
        // `$guarded = ['*']` so no request can set a reporter's facts, and that
        // guard applies to a seeder too. The controller writes rows the same
        // way, and the seeder that invented a shorter path would be inventing a
        // second truth about how this table is filled.
        foreach ($tickets as $filed) {
            $ticket = new SupportTicket();
            $ticket->forceFill([
                'user_id' => $filed['user']->id,
                'organization_id' => $filed['organization_id'],
                'category' => $filed['category'],
                'message' => $filed['message'],
                'screen' => $filed['screen'],
                'status' => $filed['status'],
            ])->save();

            $ticket->forceFill(['reference' => SupportTicket::referenceFor($ticket->id)])->save();

            if ($filed['status'] === SupportTicket::STATUS_RESOLVED && $this->admin) {
                $ticket->forceFill([
                    'reply' => 'تصل الإشعارات الآن فور إصدار الرمز؛ الصفقة الأقدم في ساحتك صدر رمزها قبل تفعيل المسار، وهي معروضة كاملة في شاشة أوامرك.',
                    'answered_by' => $this->admin->id,
                    'answered_at' => now()->subDay(),
                ])->save();
            }
        }

        // §14 asks for a consumer who can be *seen using* the marketplace, not
        // just a row in the accounts table. These are the person-attributed
        // engagement rows: a question asked in a private name, the selling
        // company's answer labelled as such, and two hearts pressed without
        // any company standing behind them. Written directly rather than
        // through HTTP — the seeder's job is the world, and the live path is
        // what the client smoke run proves on top of it.
        $rice = $this->offers['rice'];

        $question = OfferComment::create([
            'offer_id'        => $rice->id,
            'organization_id' => null,
            'user_id'         => $consumers[0]->id,
            'parent_id'       => null,
            'is_seller_reply' => false,
            'body'            => 'هل أكياس البسمتي ٢٥ كجم متوفرة للتسليم داخل مدينة عدن هذا الأسبوع؟',
            'status'          => OfferComment::STATUS_PUBLISHED,
        ]);

        OfferComment::create([
            'offer_id'        => $rice->id,
            'organization_id' => $rice->seller_organization_id,
            'user_id'         => $this->sellerUserOf($rice, 'owner')->id,
            'parent_id'       => $question->id,
            'is_seller_reply' => true,
            'body'            => 'نعم، المتوفر 500 كيس والتسليم خلال يومين داخل المدينة.',
            'status'          => OfferComment::STATUS_PUBLISHED,
        ]);

        foreach ([['milk', $consumers[0]], ['tuna', $consumers[1]]] as [$offerKey, $person]) {
            OfferLike::create([
                'offer_id'        => $this->offers[$offerKey]->id,
                'user_id'         => $person->id,
                'organization_id' => null,
            ]);
        }

        $this->consumers = $consumers;
    }

    // ─── What the person reading the console needs ───────────────────────────

    protected function report(): void
    {
        if ($this->command === null) {
            return;
        }

        $counts = [
            'منشآت' => Organization::count(),
            'حسابات' => User::count(),
            'عروض' => Offer::count(),
            'طلبات احتياج' => Demand::count(),
            'طلبات شراء' => PurchaseRequest::count(),
            'محجوزات' => Reservation::count(),
            'أوامر شراء' => Deal::count(),
            'رموز تسليم' => DealHandover::count(),
            'نزاعات' => Dispute::count(),
        ];

        foreach ($counts as $label => $count) {
            $this->command->line("  {$label}: {$count}");
        }

        $this->command->newLine();
        $this->command->line('  أوامر وصلت ولم يُمسح رمزها بعد (لا تُغلق قبل المسح):');

        foreach (Deal::with('handover')->where('status', Deal::STATUS_DELIVERED)->orderByDesc('id')->limit(3)->get() as $deal) {
            $this->command->line('    ' . $deal->deal_number . '  →  ' . ($deal->handover?->code ?? '(بلا رمز)'));
        }

        $this->command->newLine();
        $this->command->line('  تسجيل الدخول (كلمة المرور واحدة لكل حسابات المنشآت):');
        $this->command->line('    ' . implode('  |  ', [
            'amal.owner@hassal.test — بائع، مالك',
            'amal.staff@hassal.test — بائع، موظف',
            'sultan.owner@hassal.test — مشترٍ، مالك',
            'nakhil.staff@hassal.test — مشترٍ، موظف (يرتّب البضاعة)',
            'salma.consumer@hassal.test — مستهلك، بلا منشأة (تتصفح وتبلغ)',
        ]));
    }
}
