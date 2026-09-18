<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Deal;
use App\Models\DealItem;
use App\Models\Demand;
use App\Models\DemandMatch;
use App\Models\FeatureFlag;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseRequest;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        // ─── System Settings (Yemen-first, configurable) ──────────────────────
        $settings = [
            ['key' => 'platform.name',                'value' => 'حصّال',                       'type' => 'string',  'group' => 'platform',     'is_public' => true,  'description_ar' => 'اسم المنصة'],
            ['key' => 'platform.name_en',             'value' => 'HASSAL',                      'type' => 'string',  'group' => 'platform',     'is_public' => true,  'description_ar' => 'Platform name in English'],
            ['key' => 'platform.default_currency',    'value' => 'YER',                         'type' => 'string',  'group' => 'platform',     'is_public' => true,  'description_ar' => 'Default platform currency code'],
            ['key' => 'platform.default_country',     'value' => 'YE',                          'type' => 'string',  'group' => 'platform',     'is_public' => true,  'description_ar' => 'Default country ISO-2 code'],
            ['key' => 'platform.default_locale',      'value' => 'ar',                          'type' => 'string',  'group' => 'platform',     'is_public' => true,  'description_ar' => 'Default locale'],
            ['key' => 'platform.timezone',            'value' => 'Asia/Aden',                   'type' => 'string',  'group' => 'platform',     'is_public' => true,  'description_ar' => 'Default timezone'],
            ['key' => 'platform.version',             'value' => '1.0.0',                       'type' => 'string',  'group' => 'platform',     'is_public' => true,  'description_ar' => 'Platform version'],
            ['key' => 'platform.commission_rate',     'value' => '0.025',                       'type' => 'decimal', 'group' => 'financial',    'is_public' => false, 'description_ar' => 'Platform commission rate (2.5%)'],
            ['key' => 'platform.tax_rate',            'value' => '0.00',                        'type' => 'decimal', 'group' => 'financial',    'is_public' => false, 'description_ar' => 'Default tax rate (Yemen: 0% initially)'],
            ['key' => 'reservation.timeout',          'value' => '30',                          'type' => 'integer', 'group' => 'operations',   'is_public' => false, 'description_ar' => 'Reservation timeout in minutes'],
            ['key' => 'demand.expiry_days',           'value' => '14',                          'type' => 'integer', 'group' => 'operations',   'is_public' => false, 'description_ar' => 'Default demand expiry in days'],
            ['key' => 'offer.expiry_days',            'value' => '60',                          'type' => 'integer', 'group' => 'operations',   'is_public' => false, 'description_ar' => 'Default offer expiry in days'],
            ['key' => 'inspection.window',            'value' => '48',                          'type' => 'integer', 'group' => 'operations',   'is_public' => false, 'description_ar' => 'Inspection window in hours after delivery'],
            ['key' => 'matching.min_score',           'value' => '45',                          'type' => 'integer', 'group' => 'matching',     'is_public' => false, 'description_ar' => 'Minimum match score threshold (0-100)'],
            ['key' => 'matching.geo_radius_km',       'value' => '100',                         'type' => 'integer', 'group' => 'matching',     'is_public' => false, 'description_ar' => 'Default geo-search radius (km)'],
            ['key' => 'geo.max_radius_km',            'value' => '500',                         'type' => 'integer', 'group' => 'geo',          'is_public' => true,  'description_ar' => 'Maximum allowed geo search radius'],
            ['key' => 'registration.requires_verification', 'value' => '0',                    'type' => 'boolean', 'group' => 'registration', 'is_public' => true,  'description_ar' => 'Require manual verification after registration'],
            ['key' => 'offer.requires_review',        'value' => '0',                           'type' => 'boolean', 'group' => 'offers',       'is_public' => false, 'description_ar' => 'Require admin review before offer goes ACTIVE'],
        ];

        foreach ($settings as $setting) {
            SystemSetting::updateOrCreate(['key' => $setting['key']], $setting);
        }

        // ─── Currencies ────────────────────────────────────────────────────────
        $currencies = [
            ['iso_code' => 'YER', 'name_ar' => 'ريال يمني',       'name_en' => 'Yemeni Rial',      'symbol' => '﷼', 'is_default' => true,  'is_active' => true,  'exchange_rate_to_base' => 1.0000],
            ['iso_code' => 'USD', 'name_ar' => 'دولار أمريكي',    'name_en' => 'US Dollar',        'symbol' => '$', 'is_default' => false, 'is_active' => true,  'exchange_rate_to_base' => 0.0040],
            ['iso_code' => 'SAR', 'name_ar' => 'ريال سعودي',      'name_en' => 'Saudi Riyal',      'symbol' => '﷼', 'is_default' => false, 'is_active' => true,  'exchange_rate_to_base' => 0.0148],
            ['iso_code' => 'AED', 'name_ar' => 'درهم إماراتي',    'name_en' => 'UAE Dirham',       'symbol' => 'د.إ', 'is_default' => false, 'is_active' => true, 'exchange_rate_to_base' => 0.0147],
        ];

        foreach ($currencies as $currency) {
            Currency::updateOrCreate(['iso_code' => $currency['iso_code']], $currency);
        }

        // ─── Feature Flags ─────────────────────────────────────────────────────
        $flags = [
            ['key' => 'matching_engine',      'is_enabled' => true,  'name_ar' => 'محرك المطابقة التلقائية', 'name_en' => 'Auto-Matching Engine', 'description_ar' => 'تفعيل محرك المطابقة التلقائية'],
            ['key' => 'geo_discovery',         'is_enabled' => true,  'name_ar' => 'البحث الجغرافي', 'name_en' => 'Geo-Discovery', 'description_ar' => 'تفعيل البحث الجغرافي للعروض القريبة'],
            ['key' => 'demand_rfq',            'is_enabled' => true,  'name_ar' => 'طلبات الشراء (RFQ)', 'name_en' => 'Demands (RFQ)', 'description_ar' => 'تفعيل طلبات الشراء (RFQ)'],
            ['key' => 'double_entry_ledger',   'is_enabled' => true,  'name_ar' => 'دفتر الأستاذ المزدوج', 'name_en' => 'Double Entry Ledger', 'description_ar' => 'تفعيل دفتر الأستاذ المزدوج'],
            ['key' => 'webhooks',              'is_enabled' => true,  'name_ar' => 'التكامل الخارجي', 'name_en' => 'Webhooks', 'description_ar' => 'تفعيل الـ Webhooks الخارجية'],
            ['key' => 'api_keys',              'is_enabled' => true,  'name_ar' => 'مفاتيح API', 'name_en' => 'API Keys', 'description_ar' => 'تفعيل API Keys للتكاملات'],
            ['key' => 'offer_review_required', 'is_enabled' => false, 'name_ar' => 'مراجعة العروض', 'name_en' => 'Offer Review', 'description_ar' => 'يتطلب مراجعة العروض قبل النشر'],
            ['key' => 'partial_fulfillment',   'is_enabled' => false, 'name_ar' => 'التسليم الجزئي', 'name_en' => 'Partial Fulfillment', 'description_ar' => 'دعم التسليم الجزئي من عدة عروض'],
        ];

        foreach ($flags as $flag) {
            FeatureFlag::updateOrCreate(['key' => $flag['key']], $flag);
        }

        // ─── Demo User Accounts ────────────────────────────────────────────────
        $demoAccounts = [
            ['role' => 'SuperAdmin',  'email' => 'superadmin@hassal.local',        'name' => 'مدير حصّال الرئيسي',    'type' => null],
            ['role' => 'SellerAdmin', 'email' => 'seller.admin@hassal.local',       'name' => 'مدير البائع',            'type' => 'seller'],
            ['role' => 'SellerStaff', 'email' => 'seller.staff@hassal.local',       'name' => 'موظف البائع',            'type' => 'seller'],
            ['role' => 'BuyerAdmin',  'email' => 'buyer.admin@hassal.local',        'name' => 'مدير المشتري',           'type' => 'buyer'],
            ['role' => 'BuyerStaff',  'email' => 'buyer.staff@hassal.local',        'name' => 'موظف المشتري',           'type' => 'buyer'],
            ['role' => 'Intermediary','email' => 'intermediary@hassal.local',       'name' => 'وسيط حصّال',             'type' => 'intermediary'],
            ['role' => 'Auditor',     'email' => 'auditor@hassal.local',            'name' => 'مدقق الحسابات',          'type' => 'buyer'],
        ];

        foreach ($demoAccounts as $account) {
            $user = User::updateOrCreate(
                ['email' => $account['email']],
                ['name' => $account['name'], 'status' => 'active', 'password' => Hash::make('Hassal@2026!')]
            );
            $user->syncRoles([$account['role']]);

            if ($account['type']) {
                $organization = Organization::updateOrCreate(
                    ['commercial_registration_number' => 'DEMO-' . strtoupper($account['role'])],
                    [
                        'name_ar'                        => 'منشأة ' . $account['name'],
                        'name_en'                        => $account['name'] . ' Organization',
                        'type'                           => $account['type'],
                        'status'                         => 'verified',
                        'country_code'                   => 'YE',
                        'city'                           => 'صنعاء',
                    ]
                );

                $membershipRole = match ($account['role']) {
                    'SellerAdmin', 'BuyerAdmin' => 'owner',
                    'Auditor'                   => 'auditor',
                    default                     => 'operator',
                };

                OrganizationMembership::updateOrCreate(
                    ['organization_id' => $organization->id, 'user_id' => $user->id],
                    ['role' => $membershipRole, 'is_active' => true, 'joined_at' => now()]
                );
            }
        }

        // ─── Yemeni Demo Organizations ──────────────────────────────────────────
        $yemeniOrganizations = [
            [
                'email'                          => 'seller@alwataniya.ye',
                'name_ar'                        => 'شركة الوطنية للمواد الغذائية',
                'name_en'                        => 'Al-Wataniya Food Supply Co.',
                'type'                           => 'seller',
                'commercial_registration_number' => 'CR-YE-2024-001',
                'country_code'                   => 'YE',
                'city'                           => 'صنعاء',
                'role'                           => 'SellerAdmin',
            ],
            [
                'email'                          => 'buyer@aden-trading.ye',
                'name_ar'                        => 'شركة عدن للتجارة والتوزيع',
                'name_en'                        => 'Aden Trading & Distribution Co.',
                'type'                           => 'buyer',
                'commercial_registration_number' => 'CR-YE-2024-002',
                'country_code'                   => 'YE',
                'city'                           => 'عدن',
                'role'                           => 'BuyerAdmin',
            ],
        ];

        foreach ($yemeniOrganizations as $orgAccount) {
            $user = User::updateOrCreate(
                ['email' => $orgAccount['email']],
                [
                    'name'     => $orgAccount['name_ar'],
                    'status'   => 'active',
                    'password' => Hash::make('Hassal@2026!'),
                ]
            );
            $user->syncRoles([$orgAccount['role']]);

            $organization = Organization::updateOrCreate(
                ['commercial_registration_number' => $orgAccount['commercial_registration_number']],
                [
                    'name_ar'                        => $orgAccount['name_ar'],
                    'name_en'                        => $orgAccount['name_en'],
                    'type'                           => $orgAccount['type'],
                    'status'                         => 'verified',
                    'country_code'                   => $orgAccount['country_code'],
                    'city'                           => $orgAccount['city'],
                ]
            );

            OrganizationMembership::updateOrCreate(
                ['organization_id' => $organization->id, 'user_id' => $user->id],
                ['role' => 'owner', 'is_active' => true, 'joined_at' => now()]
            );
        }

        // ─── Categories ────────────────────────────────────────────────────────
        $categories = collect([
            ['code' => 'BEVERAGES',   'slug' => 'beverages',   'name_ar' => 'المشروبات',        'name_en' => 'Beverages',        'icon' => '🥤', 'sort_order' => 1],
            ['code' => 'DAIRY',       'slug' => 'dairy',       'name_ar' => 'الألبان ومشتقاتها','name_en' => 'Dairy Products',   'icon' => '🥛', 'sort_order' => 2],
            ['code' => 'PANTRY',      'slug' => 'pantry',      'name_ar' => 'المواد التموينية', 'name_en' => 'Pantry Staples',   'icon' => '📦', 'sort_order' => 3],
            ['code' => 'GRAINS',      'slug' => 'grains',      'name_ar' => 'الحبوب والبقوليات','name_en' => 'Grains & Legumes', 'icon' => '🌾', 'sort_order' => 4],
            ['code' => 'COOKING_OIL', 'slug' => 'cooking-oil', 'name_ar' => 'الزيوت النباتية', 'name_en' => 'Cooking Oils',     'icon' => '🫙', 'sort_order' => 5],
            ['code' => 'CLEANING',    'slug' => 'cleaning',    'name_ar' => 'منتجات التنظيف',  'name_en' => 'Cleaning Products','icon' => '🧹', 'sort_order' => 6],
            ['code' => 'PERSONAL',    'slug' => 'personal',    'name_ar' => 'العناية الشخصية', 'name_en' => 'Personal Care',    'icon' => '🧴', 'sort_order' => 7],
        ])->mapWithKeys(function (array $category) {
            return [$category['code'] => Category::updateOrCreate(['code' => $category['code']], $category)];
        });

        // ─── Brands ────────────────────────────────────────────────────────────
        $brands = collect([
            ['slug' => 'hassal-select',  'name_ar' => 'حصّال المختار',  'name_en' => 'Hassal Select'],
            ['slug' => 'wadi-foods',     'name_ar' => 'وادي الأغذية',   'name_en' => 'Wadi Foods'],
            ['slug' => 'nour',           'name_ar' => 'نور',             'name_en' => 'Nour'],
            ['slug' => 'shabwa-farms',   'name_ar' => 'مزارع شبوة',     'name_en' => 'Shabwa Farms'],
            ['slug' => 'aden-fresh',     'name_ar' => 'عدن الطازج',     'name_en' => 'Aden Fresh'],
        ])->mapWithKeys(function (array $brand) {
            return [$brand['slug'] => Brand::updateOrCreate(['slug' => $brand['slug']], $brand)];
        });

        // ─── Seller Organization & Warehouses ──────────────────────────────────
        $sellerOrganization = Organization::where('commercial_registration_number', 'DEMO-SELLERADMIN')->firstOrFail();

        $yemeniWarehouses = [
            [
                'code'         => 'SNA-01',
                'name'         => 'مستودع صنعاء المركزي',
                'city'         => 'صنعاء',
                'district'     => 'الحصبة',
                'address_line' => 'شارع المطار الدولي، منطقة الحصبة الصناعية',
                'latitude'     => 15.3694,
                'longitude'    => 44.1910,
            ],
            [
                'code'         => 'ADN-01',
                'name'         => 'مستودع عدن الغذائي',
                'city'         => 'عدن',
                'district'     => 'المعلا',
                'address_line' => 'منطقة المعلا الصناعية، عدن',
                'latitude'     => 12.7794,
                'longitude'    => 45.0367,
            ],
            [
                'code'         => 'HUD-01',
                'name'         => 'مستودع الحديدة الإقليمي',
                'city'         => 'الحديدة',
                'district'     => 'الميناء',
                'address_line' => 'منطقة الميناء التجاري، الحديدة',
                'latitude'     => 14.7980,
                'longitude'    => 42.9511,
            ],
        ];

        foreach ($yemeniWarehouses as $warehouse) {
            Warehouse::updateOrCreate(
                ['code' => $warehouse['code']],
                array_merge($warehouse, ['organization_id' => $sellerOrganization->id, 'is_active' => true])
            );
        }

        // ─── Products & Variants ───────────────────────────────────────────────
        $products = [
            [
                'sku'          => 'DEMO-WATER-001',
                'name_ar'      => 'مياه شرب نقية',
                'name_en'      => 'Pure Drinking Water',
                'package_size' => '24 × 330ml',
                'category_id'  => $categories['BEVERAGES']->id,
                'brand_id'     => $brands['hassal-select']->id,
                'unit'         => 'كرتون',
            ],
            [
                'sku'          => 'DEMO-MILK-001',
                'name_ar'      => 'حليب كامل الدسم',
                'name_en'      => 'Full Fat Long-Life Milk',
                'package_size' => '12 × 1L',
                'category_id'  => $categories['DAIRY']->id,
                'brand_id'     => $brands['nour']->id,
                'unit'         => 'كرتون',
            ],
            [
                'sku'          => 'DEMO-RICE-001',
                'name_ar'      => 'أرز بسمتي فاخر',
                'name_en'      => 'Premium Basmati Rice',
                'package_size' => '10kg',
                'category_id'  => $categories['GRAINS']->id,
                'brand_id'     => $brands['wadi-foods']->id,
                'unit'         => 'كيس',
            ],
            [
                'sku'          => 'DEMO-OIL-001',
                'name_ar'      => 'زيت طهي نباتي',
                'name_en'      => 'Vegetable Cooking Oil',
                'package_size' => '5L',
                'category_id'  => $categories['COOKING_OIL']->id,
                'brand_id'     => $brands['shabwa-farms']->id,
                'unit'         => 'جالون',
            ],
        ];

        foreach ($products as $productData) {
            $product = Product::updateOrCreate(
                ['sku' => $productData['sku']],
                array_merge($productData, ['organization_id' => $sellerOrganization->id, 'is_active' => true])
            );
            ProductVariant::updateOrCreate(
                ['sku' => $productData['sku'] . '-V1'],
                [
                    'product_id'      => $product->id,
                    'name_ar'         => $product->name_ar,
                    'name_en'         => $product->name_en,
                    'packaging_spec'  => $product->package_size,
                    'is_active'       => true,
                ]
            );
        }

        // ─── Demo Offers (Yemeni context) ─────────────────────────────────────
        $sellerAdmin    = User::where('email', 'seller.admin@hassal.local')->firstOrFail();
        $buyerAdmin     = User::where('email', 'buyer.admin@hassal.local')->firstOrFail();
        $buyerOrg       = Organization::where('commercial_registration_number', 'DEMO-BUYERADMIN')->firstOrFail();
        $demoVariants   = ProductVariant::whereIn('sku', ['DEMO-WATER-001-V1', 'DEMO-MILK-001-V1', 'DEMO-RICE-001-V1', 'DEMO-OIL-001-V1'])->get()->keyBy('sku');
        $demoWarehouses = Warehouse::where('organization_id', $sellerOrganization->id)->get()->keyBy('code');

        $offerDefinitions = [
            [
                'number'    => 'DEMO-OFFER-WATER',
                'variant'   => 'DEMO-WATER-001-V1',
                'warehouse' => 'SNA-01',
                'price'     => 4500.00,
                'reference' => 5500.00,
                'quantity'  => 420,
                'reason'    => Offer::REASON_SURPLUS_STOCK,
            ],
            [
                'number'    => 'DEMO-OFFER-MILK',
                'variant'   => 'DEMO-MILK-001-V1',
                'warehouse' => 'ADN-01',
                'price'     => 7500.00,
                'reference' => 9000.00,
                'quantity'  => 180,
                'reason'    => Offer::REASON_SLOW_MOVING,
            ],
            [
                'number'    => 'DEMO-OFFER-RICE',
                'variant'   => 'DEMO-RICE-001-V1',
                'warehouse' => 'HUD-01',
                'price'     => 9800.00,
                'reference' => 11000.00,
                'quantity'  => 260,
                'reason'    => Offer::REASON_SEASONAL_STOCK,
            ],
            [
                'number'    => 'DEMO-OFFER-OIL',
                'variant'   => 'DEMO-OIL-001-V1',
                'warehouse' => 'SNA-01',
                'price'     => 6200.00,
                'reference' => 7500.00,
                'quantity'  => 150,
                'reason'    => Offer::REASON_EXCESS_INVENTORY,
            ],
        ];

        $offers = [];
        foreach ($offerDefinitions as $definition) {
            $variant   = $demoVariants[$definition['variant']];
            $warehouse = $demoWarehouses[$definition['warehouse']];

            $offer = Offer::updateOrCreate(
                ['title_en' => $definition['number']],
                [
                    'seller_organization_id'  => $sellerOrganization->id,
                    'warehouse_id'            => $warehouse->id,
                    'product_variant_id'      => $variant->id,
                    'category_id'             => $variant->product->category_id,
                    'title_ar'                => 'عرض - ' . $variant->name_ar,
                    'title_en'                => $definition['number'],
                    'description_ar'          => 'بيانات تجريبية لمنصة حصّال — تجربة الحجز والمطابقة والصفقات.',
                    'description_en'          => 'HASSAL demo data — for matching, reservation, and deal workflows.',
                    'opportunity_reason'      => $definition['reason'],
                    'opportunity_reason_notes'=> 'بيانات تجريبية أولية للتحقق من عمل النظام.',
                    'status'                  => Offer::STATUS_ACTIVE,
                    'min_order_quantity'      => 10,
                    'max_order_quantity'      => $definition['quantity'],
                    'total_quantity'          => $definition['quantity'],
                    'available_quantity'      => $definition['quantity'],
                    'reserved_quantity'       => 0,
                    'sold_quantity'           => 0,
                    'unit'                    => $variant->product->unit ?? 'كرتون',
                    'unit_price'              => $definition['price'],
                    'market_reference_price'  => $definition['reference'],
                    'discount_percentage'     => round((($definition['reference'] - $definition['price']) / $definition['reference']) * 100, 2),
                    'currency_code'           => 'YER',
                    'production_date'         => Carbon::now()->subMonths(2)->toDateString(),
                    'expiry_date'             => Carbon::now()->addMonths(10)->toDateString(),
                    'storage_condition'       => 'ambient',
                    'delivery_terms'          => 'BOTH',
                    'inspection_window_hours' => 48,
                    'published_at'            => Carbon::now()->subDays(3),
                    'expires_at'              => Carbon::now()->addMonths(2),
                ]
            );

            OfferItem::updateOrCreate(
                ['offer_id' => $offer->id, 'product_variant_id' => $variant->id],
                [
                    'product_id'    => $variant->product_id,
                    'quantity'      => $definition['quantity'],
                    'unit_price'    => $definition['price'],
                    'currency_code' => 'YER',
                ]
            );

            $offers[] = $offer;
        }

        // ─── Demo Demand (Yemeni context) ─────────────────────────────────────
        $demand = Demand::updateOrCreate(
            ['demand_number' => 'DEMO-REQ-001'],
            [
                'buyer_organization_id' => $buyerOrg->id,
                'buyer_user_id'         => $buyerAdmin->id,
                'category_id'           => $categories['BEVERAGES']->id,
                'title_ar'              => 'طلب توريد مياه شرب للمطاعم',
                'title_en'              => 'Drinking water supply for restaurants',
                'description'           => 'طلب تجريبي لاختبار محرك المطابقة وسير عمل طلب الشراء في منصة حصّال.',
                'target_quantity'       => 120,
                'unit'                  => 'كرتون',
                'target_unit_price'     => 5000.00,
                'currency_code'         => 'YER',
                'target_delivery_date'  => Carbon::now()->addDays(14)->toDateString(),
                'delivery_city'         => 'صنعاء',
                'delivery_latitude'     => 15.3694,
                'delivery_longitude'    => 44.1910,
                'special_requirements'  => 'تغليف سليم وتسليم خلال نافذة الاستلام المحددة.',
                'status'                => Demand::STATUS_OPEN,
                'expires_at'            => Carbon::now()->addDays(14),
            ]
        );

        // ─── Demo Match ────────────────────────────────────────────────────────
        $waterOffer = collect($offers)->first(fn(Offer $o) => $o->title_en === 'DEMO-OFFER-WATER');

        $match = DemandMatch::updateOrCreate(
            ['demand_id' => $demand->id, 'offer_id' => $waterOffer->id],
            [
                'buyer_organization_id'  => $buyerOrg->id,
                'seller_organization_id' => $sellerOrganization->id,
                'requested_quantity'     => 120,
                'match_score'            => 90.00,
                'match_reasons'          => [
                    'category_match'     => 'تصنيف المنتج مطابق',
                    'quantity_available' => 'الكمية المطلوبة متوفرة',
                    'price_in_range'     => 'السعر ضمن النطاق المستهدف',
                    'city_match'         => 'موقع المستودع يطابق مدينة التسليم',
                    'offer_active'       => 'العرض نشط ومتاح',
                ],
                'estimated_total'        => 120 * 4500.00,
                'status'                 => DemandMatch::STATUS_ACTIVE,
                'expires_at'             => Carbon::now()->addDays(7),
            ]
        );

        // ─── Demo Purchase Request ─────────────────────────────────────────────
        PurchaseRequest::updateOrCreate(
            ['purchase_request_number' => 'DEMO-PR-001'],
            [
                'demand_id'              => $demand->id,
                'demand_match_id'        => $match->id,
                'offer_id'               => $waterOffer->id,
                'buyer_organization_id'  => $buyerOrg->id,
                'seller_organization_id' => $sellerOrganization->id,
                'buyer_user_id'          => $buyerAdmin->id,
                'seller_user_id'         => $sellerAdmin->id,
                'requested_quantity'     => 120,
                'unit'                   => 'كرتون',
                'requested_price'        => 4500.00,
                'currency_code'          => 'YER',
                'delivery_preference'    => 'PICKUP',
                'status'                 => PurchaseRequest::STATUS_REQUESTED,
                'notes'                  => 'طلب تجريبي معلق — في انتظار مراجعة حساب البائع.',
                'proposed_terms'         => ['source' => 'demo-seed'],
            ]
        );

        // ─── Demo Deal ─────────────────────────────────────────────────────────
        $dealQuantity  = 60;
        $dealUnitPrice = 4500.00;
        $dealSubtotal  = $dealQuantity * $dealUnitPrice;
        $dealFee       = round($dealSubtotal * 0.025, 4);

        $deal = Deal::updateOrCreate(
            ['deal_number' => 'DEMO-DEAL-001'],
            [
                'offer_id'                    => $waterOffer->id,
                'buyer_organization_id'       => $buyerOrg->id,
                'seller_organization_id'      => $sellerOrganization->id,
                'buyer_user_id'               => $buyerAdmin->id,
                'seller_user_id'              => $sellerAdmin->id,
                'warehouse_id'                => $demoWarehouses['SNA-01']->id,
                'quantity'                    => $dealQuantity,
                'unit_price'                  => $dealUnitPrice,
                'currency_code'               => 'YER',
                'transaction_currency'        => 'YER',
                'base_currency'               => 'YER',
                'exchange_rate'               => 1,
                'exchange_rate_snapshot'      => 1,
                'base_amount'                 => $dealSubtotal,
                'discount_amount'             => 0,
                'tax_rate_snapshot'           => 0.00,
                'tax_amount'                  => 0.00,
                'platform_fee_rate_snapshot'  => 0.025,
                'platform_fee_amount'         => $dealFee,
                'delivery_fee_amount'         => 0,
                'buyer_total_amount'          => $dealSubtotal,
                'seller_net_amount'           => $dealSubtotal - $dealFee,
                'status'                      => Deal::STATUS_ACCEPTED,
                'delivery_method'             => 'BUYER_PICKUP',
                'inspection_window_hours'     => 48,
                'accepted_at'                 => Carbon::now()->subDay(),
                'metadata'                    => ['source' => 'demo-seed', 'country' => 'YE'],
            ]
        );

        DealItem::updateOrCreate(
            ['deal_id' => $deal->id, 'product_variant_id' => $demoVariants['DEMO-WATER-001-V1']->id],
            [
                'snapshot_product_name' => $demoVariants['DEMO-WATER-001-V1']->name_ar,
                'snapshot_sku'          => $demoVariants['DEMO-WATER-001-V1']->sku,
                'snapshot_unit_name'    => 'كرتون',
                'quantity'              => $dealQuantity,
                'unit_price'            => $dealUnitPrice,
                'subtotal_amount'       => $dealSubtotal,
            ]
        );
    }
}
