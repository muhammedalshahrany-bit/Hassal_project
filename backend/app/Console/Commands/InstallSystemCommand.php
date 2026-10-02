<?php

namespace App\Console\Commands;

use App\Catalog\Models\Category;
use App\Platform\Models\Currency;
use App\Platform\Models\FeatureFlag;
use App\Organizations\Models\Organization;
use App\Organizations\Models\OrganizationMembership;
use App\Catalog\Models\Product;
use App\Catalog\Models\ProductVariant;
use App\Platform\Models\SystemSetting;
use App\Identity\Models\User;
use App\Inventory\Models\Warehouse;
use App\Trading\Services\DoubleEntryLedgerService;
use App\Catalog\Support\CategoryCatalog;
use App\Platform\Support\FlagCatalog;
use App\Platform\Support\SettingCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

class InstallSystemCommand extends Command
{
    protected $signature = 'app:install-system
                            {--force : Overwrite existing system defaults}
                            {--skip-prereqs : Skip environment pre-flight checks}
                            {--skip-admin : Do not create the first platform operator}
                            {--admin-name= : Full name of the first SuperAdmin}
                            {--admin-email= : Email of the first SuperAdmin}
                            {--admin-password= : Password for the first SuperAdmin (prefer the interactive prompt; this form can leak into shell history)}';
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

        $this->line('--- Step 9: First Platform Operator ---');
        if ($this->option('skip-admin')) {
            $this->line('   Skipped by request. Until an operator exists the API has no SuperAdmin and the setup wizard cannot be reached.');
        } else {
            $this->createFirstAdmin();
        }

        $this->line('--- Step 10: Storage Linking & Production Cache Optimization ---');
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

    /**
     * RBAC has exactly one source of truth: config/rbac.php, applied by
     * RolesAndPermissionsSeeder. The installer previously declared its own
     * matrix here, which (a) created permissions no route ever checked and
     * (b) overwrote the real grants through syncPermissions(). Never
     * re-introduce a second definition.
     */
    protected function seedRolesAndPermissions(): void
    {
        // `--force` because this command's whole purpose is to be run against a
        // production database, and `db:seed` asks an interactive question there.
        // The installer already runs `migrate --force` for the same reason; a
        // container with no terminal used to stop on this prompt with no RBAC
        // matrix written and nothing to say why.
        $this->call('db:seed', [
            '--class' => \Database\Seeders\RolesAndPermissionsSeeder::class,
            '--force' => true,
        ]);
    }

    /**
     * The installer previously finished with a fully migrated database that
     * nobody could sign into: every admin route requires a SuperAdmin token,
     * and the setup wizard itself is behind auth:sanctum. Creating the first
     * operator here is what breaks that loop.
     *
     * There is exactly one way in, and it is this command. No seeder creates an
     * operator, no demo account is a SuperAdmin, and nothing here echoes, logs
     * or stores the password as anything but the hash the `User` model makes on
     * assignment.
     */
    protected function createFirstAdmin(): void
    {
        // Command-line option, then `HASSAL_ADMIN_*`, then the prompt — in that
        // order, so an unattended install works and an interactive one still
        // asks instead of silently accepting an environment left behind.
        $configured = (array) config('hassal.install', []);

        $name = (string) ($this->option('admin-name')
            ?: $configured['admin_name']
            ?: $this->ask('Name of the platform operator', 'Platform Operator'));

        $email = strtolower((string) ($this->option('admin-email')
            ?: $configured['admin_email']
            ?: $this->ask('Email of the platform operator')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("'{$email}' is not a valid email address.");

            return;
        }

        $password = $this->option('admin-password') ?: ($configured['admin_password'] ?? '');

        if (! is_string($password) || $password === '') {
            if (! $this->getOutput()->isInteractive()) {
                $this->error('No admin password supplied and the terminal is not interactive. Pass --admin-password, set HASSAL_ADMIN_PASSWORD, or run interactively.');

                return;
            }

            $password = (string) $this->secret('Choose the operator password (12+ characters)');
        }

        if (strlen($password) < 12) {
            $this->error('The operator password must be at least 12 characters. Nothing was changed.');

            return;
        }

        // The User model casts `password` to `hashed`, so the raw value is
        // assigned and hashed exactly once by the model layer.
        $user = User::firstOrNew(['email' => $email]);
        $user->name = $name;
        $user->status = 'active';
        $user->password = $password;
        $user->save();

        $user->syncRoles([User::PLATFORM_OPERATOR_ROLE]);

        $this->info("✔ Platform operator ready: {$email} (role: SuperAdmin).");
        $this->warn('  Store the password in your own password manager. It is not recoverable from the database or from this console.');

        // `*` makes SuperAdmin the whole platform, so a second holder is a
        // second owner of the installation. This command will not delete one —
        // it did not create it, and an installer that removes accounts it finds
        // is a worse accident than a warning — but it has to say that the
        // single-admin rule §4 asks for is not what the database holds.
        $others = User::role(User::PLATFORM_OPERATOR_ROLE)->whereKeyNot($user->id)->count();

        if ($others > 0) {
            $this->warn("  {$others} other account(s) also hold SuperAdmin. HASSAL is designed to run with one; revoke the rest from the platform console.");
        }
    }

    protected function seedCurrencies(): void
    {
        Currency::where('iso_code', '!=', 'YER')->update(['is_default' => false]);

        Currency::updateOrCreate(['iso_code' => 'YER'], [
            'name_ar' => 'ريال يمني',
            'name_en' => 'Yemeni Rial',
            'symbol' => '﷼',
            'decimal_places' => 2,
            'exchange_rate_to_base' => 1.000000,
            'rate_source' => 'BASE_CURRENCY',
            'is_active' => true,
            'is_default' => true,
        ]);

        // Present but inactive: a currency is only usable once an operator
        // supplies a rate and a source for it. Seeding a guessed conversion
        // would put invented arithmetic into real invoices.
        Currency::updateOrCreate(['iso_code' => 'USD'], [
            'name_ar' => 'دولار أمريكي',
            'name_en' => 'US Dollar',
            'symbol' => '$',
            'decimal_places' => 2,
            'exchange_rate_to_base' => 1.000000,
            'rate_source' => 'UNSET_SET_BEFORE_USE',
            'is_active' => false,
            'is_default' => false,
        ]);
    }

    protected function seedSystemSettings(): void
    {
        // Seeded from App\Platform\Support\SettingCatalog — the same list the reference
        // seeder uses and the list the runtime code reads by these exact keys.
        // Declaring settings here as well is what produced two vocabularies,
        // and an operator's edit in the admin console then changed a row that
        // no service ever looked at.
        foreach (SettingCatalog::defaults() as $setting) {
            SystemSetting::updateOrCreate(
                ['key' => $setting['key'], 'organization_id' => null],
                $setting
            );
        }
    }

    protected function seedFeatureFlags(): void
    {
        foreach (FlagCatalog::defaults() as $flag) {
            FeatureFlag::updateOrCreate(['key' => $flag['key']], $flag);
        }
    }

    protected function seedCatalog(): void
    {
        // App\Catalog\Support\CategoryCatalog is the single source; brands are created
        // by the businesses that supply them, never invented by the platform.
        foreach (CategoryCatalog::defaults() as $category) {
            Category::updateOrCreate(['code' => $category['code']], $category);
        }
    }
}
