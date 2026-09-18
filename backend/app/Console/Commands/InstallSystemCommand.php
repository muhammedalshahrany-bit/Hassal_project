<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Currency;
use App\Models\FeatureFlag;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DoubleEntryLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class InstallSystemCommand extends Command
{
    protected $signature = 'app:install-system {--force : Overwrite existing system defaults} {--skip-prereqs : Skip environment pre-flight checks}';
    protected $description = 'Production Installer for HASSAL B2B Platform (Environment verification, migrations, RBAC, currencies, settings, and ledger initialization).';

    public function handle(DoubleEntryLedgerService $ledgerService): int
    {
        $this->info('================================================================');
        $this->info('  HASSAL B2B Marketplace - Commercial System Installation CLI  ');
        $this->info('================================================================');

        if (!$this->option('skip-prereqs')) {
            $this->line('--- Step 1: Pre-flight Environment & Requirements Checks ---');
            if (!$this->verifyPrerequisites()) {
                $this->error('Installation halted due to unmet system prerequisites.');
                return Command::FAILURE;
            }
            $this->info('✔ All system prerequisites verified successfully.');
        }

        $this->line('--- Step 2: Database Connectivity & Schema Migrations ---');
        try {
            DB::connection()->getPdo();
            $this->info('✔ Database connection established.');
        } catch (\Exception $e) {
            $this->error('Failed to connect to the database: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Production rule: Never run migrate:fresh in production!
        $this->line('   Executing safe database migrations (migrate --force)...');
        Artisan::call('migrate', ['--force' => true]);
        $this->line(Artisan::output());
        $this->info('✔ Migrations completed successfully.');

        $this->line('--- Step 3: RBAC Roles & Permissions Matrix ---');
        $this->seedRolesAndPermissions();
        $this->info('✔ Roles and permissions configured.');

        $this->line('--- Step 4: Multi-Currency & Database-backed Exchange Rates ---');
        $this->seedCurrencies();
        $this->info('✔ Base and secondary currencies seeded with configurable rates.');

        $this->line('--- Step 5: Structured Dynamic System Settings ---');
        $this->seedSystemSettings();
        $this->info('✔ Structured system settings seeded.');

        $this->line('--- Step 6: Feature Flags Engine ---');
        $this->seedFeatureFlags();
        $this->info('✔ Feature flags seeded.');

        $this->line('--- Step 7: Double-Entry Ledger Chart of Accounts ---');
        $ledgerService->ensureChartOfAccounts();
        $this->info('✔ Standard chart of accounts initialized.');

        $this->line('--- Step 8: Standard FMCG & Food Industry Classification ---');
        $this->seedCatalog();
        $this->info('✔ Industry categories initialized.');

        $this->line('--- Step 9: Storage Linking & Production Cache Optimization ---');
        try {
            Artisan::call('storage:link');
        } catch (\Exception $e) {
            // storage:link might already exist
        }

        if (app()->environment('production')) {
            $this->line('   Optimizing config, route, and view caches...');
            Artisan::call('config:cache');
            Artisan::call('route:cache');
            Artisan::call('view:cache');
            $this->info('✔ Production caches generated.');
        }

        $this->info('================================================================');
        $this->info('  HASSAL Commercial Platform Installed & Ready for Deployment!  ');
        $this->info('================================================================');

        return Command::SUCCESS;
    }

    protected function verifyPrerequisites(): bool
    {
        $allPassed = true;

        // 1. PHP Version
        $phpVersion = phpversion();
        $this->line("   Checking PHP Version (Required: >= 8.3.0) -> Current: {$phpVersion}");
        if (version_compare($phpVersion, '8.3.0', '<')) {
            $this->error("   [FAIL] PHP 8.3.0 or higher is required.");
            $allPassed = false;
        } else {
            $this->info("   [PASS] PHP version compatible.");
        }

        // 2. Required PHP Extensions
        $requiredExtensions = [
            'pdo', 'pdo_mysql', 'bcmath', 'mbstring', 'openssl', 'json', 'tokenizer', 'xml', 'ctype', 'curl'
        ];

        foreach ($requiredExtensions as $ext) {
            if (!extension_loaded($ext)) {
                // Warning if running in non-MySQL environments like sqlite during tests
                if ($ext === 'pdo_mysql' && config('database.default') !== 'mysql') {
                    $this->warn("   [NOTICE] Extension '{$ext}' not active for current non-mysql driver (" . config('database.default') . ").");
                    continue;
                }
                $this->error("   [FAIL] Required PHP extension '{$ext}' is missing.");
                $allPassed = false;
            } else {
                $this->info("   [PASS] Extension '{$ext}' loaded.");
            }
        }

        // 3. Writable Storage & Cache Directories
        $writablePaths = [
            storage_path('app'),
            storage_path('framework'),
            storage_path('framework/cache'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ];

        foreach ($writablePaths as $path) {
            if (!File::isDirectory($path)) {
                File::makeDirectory($path, 0775, true, true);
            }
            if (!is_writable($path)) {
                $this->error("   [FAIL] Directory is not writable: {$path}");
                $allPassed = false;
            } else {
                $this->info("   [PASS] Directory writable: {$path}");
            }
        }

        return $allPassed;
    }

    protected function seedRolesAndPermissions(): void
    {
        $permissions = [
            'marketplace.view', 'offers.view', 'offers.create', 'offers.edit', 'offers.delete', 'offers.moderate',
            'requests.create', 'requests.cancel', 'requests.view', 'demands.view',
            'deals.view', 'deals.create', 'deals.transition',
            'warehouses.view', 'warehouses.manage', 'inventory.manage', 'inventory.adjust',
            'team.manage', 'ledger.view', 'settings.manage', 'audit.view',
            'reconciliation.view', 'reconciliation.execute', 'api-keys.manage', 'webhooks.manage',
            'disputes.open', 'disputes.resolve', 'organizations.manage', 'organizations.verify',
            'users.manage', 'currencies.manage', 'catalog.manage'
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $roles = [
            'SuperAdmin' => $permissions,
            'OperationsManager' => [
                'marketplace.view', 'offers.view', 'offers.moderate', 'deals.view', 'deals.transition',
                'warehouses.view', 'warehouses.manage', 'ledger.view', 'disputes.resolve',
                'organizations.manage', 'organizations.verify', 'audit.view'
            ],
            'ComplianceOfficer' => [
                'marketplace.view', 'offers.view', 'offers.moderate', 'ledger.view', 'disputes.resolve',
                'organizations.verify', 'reconciliation.view', 'audit.view'
            ],
            'SellerAdmin' => [
                'marketplace.view', 'offers.view', 'offers.create', 'offers.edit', 'offers.delete', 'deals.view',
                'deals.transition', 'warehouses.view', 'warehouses.manage', 'inventory.manage',
                'team.manage', 'api-keys.manage', 'webhooks.manage', 'disputes.open'
            ],
            'SellerStaff' => [
                'marketplace.view', 'offers.view', 'offers.create', 'offers.edit', 'deals.transition', 'warehouses.view'
            ],
            'BuyerAdmin' => [
                'marketplace.view', 'offers.view', 'requests.create', 'requests.cancel', 'requests.view', 'demands.view',
                'deals.create', 'deals.view', 'deals.transition', 'team.manage', 'api-keys.manage',
                'webhooks.manage', 'disputes.open'
            ],
            'BuyerStaff' => [
                'marketplace.view', 'offers.view', 'requests.create', 'requests.view', 'demands.view', 'deals.create'
            ],
            'PlatformAuditor' => [
                'marketplace.view', 'offers.view', 'ledger.view', 'reconciliation.view', 'audit.view'
            ],
        ];

        foreach ($roles as $roleName => $rolePerms) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($rolePerms);
        }
    }

    protected function seedCurrencies(): void
    {
        Currency::where('iso_code', '!=', 'YER')->update(['is_default' => false]);

        Currency::updateOrCreate(['iso_code' => 'YER'], [
            'name_ar' => 'ريال يمني',
            'name_en' => 'Yemeni Rial',
            'symbol' => 'ر.ي',
            'decimal_places' => 2,
            'exchange_rate_to_base' => 1.000000,
            'is_active' => true,
            'is_default' => true,
        ]);

        Currency::updateOrCreate(['iso_code' => 'USD'], [
            'name_ar' => 'دولار أمريكي',
            'name_en' => 'US Dollar',
            'symbol' => '$',
            'decimal_places' => 2,
            // A rate must be supplied and approved by an administrator before use.
            'exchange_rate_to_base' => 1.000000,
            'is_active' => false,
            'is_default' => false,
        ]);

        Currency::updateOrCreate(['iso_code' => 'AED'], [
            'name_ar' => 'درهم إماراتي',
            'name_en' => 'UAE Dirham',
            'symbol' => 'د.إ',
            'decimal_places' => 2,
            'exchange_rate_to_base' => 1.000000,
            'is_active' => false,
            'is_default' => false,
        ]);

        Currency::updateOrCreate(['iso_code' => 'SAR'], [
            'name_ar' => 'ريال سعودي',
            'name_en' => 'Saudi Riyal',
            'symbol' => 'ر.س',
            'decimal_places' => 2,
            'exchange_rate_to_base' => 1.000000,
            'is_active' => false,
            'is_default' => false,
        ]);
    }

    protected function seedSystemSettings(): void
    {
        $settings = [
            [
                'key' => 'platform.country',
                'value' => 'YE',
                'type' => 'string',
                'group' => 'platform',
                'description_ar' => 'الدولة الافتراضية للمنصة',
                'description_en' => 'Default platform country',
                'default_value' => 'YE',
                'is_editable' => true,
                'is_public' => true,
                'validation_rules' => ['required', 'size:2']
            ],
            [
                'key' => 'platform.name_ar',
                'value' => 'منصة حصّال للفرص والمخزون الغذائي',
                'type' => 'string',
                'group' => 'platform',
                'description_ar' => 'اسم المنصة التجاري بالعربية',
                'description_en' => 'Platform Commercial Name (Arabic)',
                'default_value' => 'منصة حصّال',
                'is_editable' => true,
                'is_public' => true,
                'validation_rules' => ['required', 'string', 'max:150']
            ],
            [
                'key' => 'platform.name_en',
                'value' => 'HASSAL B2B Food & Opportunities Marketplace',
                'type' => 'string',
                'group' => 'platform',
                'description_ar' => 'اسم المنصة التجاري بالإنجليزية',
                'description_en' => 'Platform Commercial Name (English)',
                'default_value' => 'HASSAL B2B Marketplace',
                'is_editable' => true,
                'is_public' => true,
                'validation_rules' => ['required', 'string', 'max:150']
            ],
            [
                'key' => 'platform.default_locale',
                'value' => 'ar',
                'type' => 'string',
                'group' => 'platform',
                'description_ar' => 'اللغة الافتراضية للنظام',
                'description_en' => 'Default Platform Locale',
                'default_value' => 'ar',
                'is_editable' => true,
                'is_public' => true,
                'validation_rules' => ['required', 'in:ar,en,fr,tr,ur']
            ],
            [
                'key' => 'platform.default_currency',
                'value' => 'YER',
                'type' => 'string',
                'group' => 'platform',
                'description_ar' => 'العملة الأساسية للمنصة',
                'description_en' => 'Platform Base Currency',
                'default_value' => 'YER',
                'is_editable' => true,
                'is_public' => true,
                'validation_rules' => ['required', 'string', 'size:3']
            ],
            [
                'key' => 'tax.enabled',
                'value' => 'false',
                'type' => 'boolean',
                'group' => 'tax',
                'description_ar' => 'تفعيل احتساب الضريبة',
                'description_en' => 'Enable tax calculation',
                'default_value' => 'false',
                'is_editable' => true,
                'is_public' => true,
                'validation_rules' => ['required', 'boolean']
            ],
            [
                'key' => 'tax.name',
                'value' => 'ضريبة المبيعات',
                'type' => 'string',
                'group' => 'tax',
                'description_ar' => 'اسم الضريبة الظاهر في المستندات',
                'description_en' => 'Tax label shown in documents',
                'default_value' => 'ضريبة المبيعات',
                'is_editable' => true,
                'is_public' => true,
                'validation_rules' => ['required', 'string', 'max:100']
            ],
            [
                'key' => 'tax.rate',
                'value' => '0.0000',
                'type' => 'decimal',
                'group' => 'tax',
                'description_ar' => 'نسبة الضريبة؛ تعتمد بعد التحقق القانوني',
                'description_en' => 'Tax rate; set only after legal verification',
                'default_value' => '0.0000',
                'is_editable' => true,
                'is_public' => true,
                'validation_rules' => ['required', 'numeric', 'min:0', 'max:1']
            ],
            [
                'key' => 'tax.inclusive',
                'value' => 'false',
                'type' => 'boolean',
                'group' => 'tax',
                'description_ar' => 'هل السعر شامل الضريبة',
                'description_en' => 'Whether displayed prices include tax',
                'default_value' => 'false',
                'is_editable' => true,
                'is_public' => true,
                'validation_rules' => ['required', 'boolean']
            ],
            [
                'key' => 'commission.rate',
                'value' => '0.0250',
                'type' => 'decimal',
                'group' => 'commission',
                'description_ar' => 'عمولة المنصة من إجمالي الصفقة',
                'description_en' => 'Platform commission rate',
                'default_value' => '0.0250',
                'is_editable' => true,
                'is_public' => false,
                'validation_rules' => ['required', 'numeric', 'min:0', 'max:0.5']
            ],
            [
                'key' => 'reservation.timeout',
                'value' => '30',
                'type' => 'integer',
                'group' => 'reservation',
                'description_ar' => 'مدة قفل المخزون المؤقت لحين السداد (بالدقائق)',
                'description_en' => 'Inventory Hold Lock Timeout in Minutes',
                'default_value' => '30',
                'is_editable' => true,
                'is_public' => true,
                'validation_rules' => ['required', 'integer', 'min:5', 'max:1440']
            ],
            [
                'key' => 'inspection.window',
                'value' => '48',
                'type' => 'integer',
                'group' => 'inspection',
                'description_ar' => 'مهلة الفحص ومطابقة البضاعة في المستودع (بالساعات)',
                'description_en' => 'Inspection and Quality Verification Window in Hours',
                'default_value' => '48',
                'is_editable' => true,
                'is_public' => true,
                'validation_rules' => ['required', 'integer', 'min:12', 'max:168']
            ],
            [
                'key' => 'marketplace.require_offer_moderation',
                'value' => 'true',
                'type' => 'boolean',
                'group' => 'marketplace',
                'description_ar' => 'إلزامية اعتماد ومراجعة العروض قبل النشر',
                'description_en' => 'Enforce Admin Moderation Before Offers Go Live',
                'default_value' => 'true',
                'is_editable' => true,
                'is_public' => false,
                'validation_rules' => ['required', 'boolean']
            ],
            [
                'key' => 'compliance.enforce_kyb_verification',
                'value' => 'true',
                'type' => 'boolean',
                'group' => 'compliance',
                'description_ar' => 'اشتراط توثيق السجل التجاري والشهادة الضريبية للمنشآت',
                'description_en' => 'Require KYB / Commercial Registration Verification',
                'default_value' => 'true',
                'is_editable' => true,
                'is_public' => false,
                'validation_rules' => ['required', 'boolean']
            ],
            [
                'key' => 'geo.default_radius_km',
                'value' => '250',
                'type' => 'integer',
                'group' => 'geo',
                'description_ar' => 'نصف قطر البحث الجغرافي الافتراضي (كم)',
                'description_en' => 'Default Geographic Search Radius in Kilometers',
                'default_value' => '250',
                'is_editable' => true,
                'is_public' => true,
                'validation_rules' => ['required', 'integer', 'min:10', 'max:2000']
            ],
        ];

        foreach ($settings as $s) {
            SystemSetting::updateOrCreate(
                ['key' => $s['key'], 'organization_id' => null],
                $s
            );
        }
    }

    protected function seedFeatureFlags(): void
    {
        $flags = [
            ['key' => 'marketplace', 'name_ar' => 'سوق الفرص والمخزون', 'name_en' => 'Marketplace Engine', 'is_enabled' => true],
            ['key' => 'demands', 'name_ar' => 'طلبات الشراء وعروض الأسعار (RFQ)', 'name_en' => 'Demands & RFQ', 'is_enabled' => true],
            ['key' => 'geo_discovery', 'name_ar' => 'البحث الجغرافي والفرص القريبة', 'name_en' => 'Geographical Discovery', 'is_enabled' => true],
            ['key' => 'escrow', 'name_ar' => 'الضمان المالي وحساب الأمانات', 'name_en' => 'Escrow & Financial Safeguard', 'is_enabled' => true],
            ['key' => 'webhooks', 'name_ar' => 'نظام إشعارات الويب هوك للمطورين', 'name_en' => 'Webhooks Engine', 'is_enabled' => true],
            ['key' => 'api_keys', 'name_ar' => 'مفاتيح الربط البرمجي للمنشآت (API Keys)', 'name_en' => 'B2B API Keys', 'is_enabled' => true],
            ['key' => 'disputes', 'name_ar' => 'مركز النزاعات والاعتراضات', 'name_en' => 'Dispute Resolution Center', 'is_enabled' => true],
            ['key' => 'reconciliation', 'name_ar' => 'محرك المطابقة والتسوية المالية الآلية', 'name_en' => 'Automated Financial Reconciliation', 'is_enabled' => true],
        ];

        foreach ($flags as $f) {
            FeatureFlag::updateOrCreate(['key' => $f['key']], $f);
        }
    }

    protected function seedCatalog(): void
    {
        $categories = [
            ['code' => 'DAIRY', 'slug' => 'dairy-beverages', 'name_ar' => 'الألبان والعصائر والمشروبات', 'name_en' => 'Dairy & Beverages', 'icon' => 'Milk', 'sort_order' => 1],
            ['code' => 'DRY_GOODS', 'slug' => 'dry-grains', 'name_ar' => 'الحبوب والبقوليات والمواد الجافة', 'name_en' => 'Dry Goods & Grains', 'icon' => 'Wheat', 'sort_order' => 2],
            ['code' => 'OILS_FATS', 'slug' => 'oils-ghee', 'name_ar' => 'الزيوت والدهون والسمن', 'name_en' => 'Oils & Ghee', 'icon' => 'Droplet', 'sort_order' => 3],
            ['code' => 'CANNED', 'slug' => 'canned-preserved', 'name_ar' => 'المعلبات والمحفوظات الغذائية', 'name_en' => 'Canned & Preserved Foods', 'icon' => 'Package', 'sort_order' => 4],
            ['code' => 'FROZEN', 'slug' => 'frozen-chilled', 'name_ar' => 'المجمدات واللحوم والدواجن', 'name_en' => 'Frozen & Poultry', 'icon' => 'Snowflake', 'sort_order' => 5],
            ['code' => 'SWEETS_SNACKS', 'slug' => 'confectionery-snacks', 'name_ar' => 'الحلويات والمقرمشات والشوكولاتة', 'name_en' => 'Confectionery & Snacks', 'icon' => 'Cookie', 'sort_order' => 6],
            ['code' => 'BAKERY_RAW', 'slug' => 'bakery-raw-materials', 'name_ar' => 'مستلزمات المخابز والمواد الأولية', 'name_en' => 'Bakery & Raw Ingredients', 'icon' => 'Cake', 'sort_order' => 7],
        ];

        foreach ($categories as $cat) {
            Category::updateOrCreate(['code' => $cat['code']], $cat);
        }

        $brands = [
            ['name_ar' => 'المراعي', 'name_en' => 'Almarai', 'slug' => 'almarai'],
            ['name_ar' => 'صافولا (عافية)', 'name_en' => 'Savola (Afia)', 'slug' => 'savola-afia'],
            ['name_ar' => 'نادك', 'name_en' => 'Nadec', 'slug' => 'nadec'],
            ['name_ar' => 'العلالي', 'name_en' => 'Al Alali', 'slug' => 'al-alali'],
            ['name_ar' => 'أمريكانا', 'name_en' => 'Americana', 'slug' => 'americana'],
            ['name_ar' => 'قودي', 'name_en' => 'Goody', 'slug' => 'goody'],
        ];

        foreach ($brands as $brand) {
            Brand::updateOrCreate(['slug' => $brand['slug']], $brand);
        }
    }
}
