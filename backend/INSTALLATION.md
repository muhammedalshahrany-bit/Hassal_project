# HASSAL System Installation Guide (دليل التثبيت والتهيئة)

This guide documents the formal production installation process for the **HASSAL B2B Marketplace** engine.

---

## 1. System Requirements

- **PHP**: 8.3.0 or higher
  - Required Extensions (verified by the installer): `pdo`, `pdo_mysql`, `bcmath`,
    `mbstring`, `openssl`, `json`, `tokenizer`, `xml`, `ctype`, `curl`.
  - Optional: `redis` (only when `CACHE_STORE`/`SESSION_DRIVER` are set to redis).
- **MySQL**: 8.4 LTS
  - Engine: `InnoDB`
  - Character Set: `utf8mb4`
  - Collation: `utf8mb4_unicode_ci`
- **Composer**: 2.7+
- **Memory**: Minimum 2 GB RAM (4 GB recommended for production background workers).

---

## 2. Automated Installation Command

HASSAL includes an enterprise CLI installer that safely initializes the system without destructive operations:

```bash
php artisan app:install-system
```

### What `app:install-system` performs:
1. Pre-flight environment checks: PHP >= 8.3.0, the extensions `pdo`, `pdo_mysql`,
   `bcmath`, `mbstring`, `openssl`, `json`, `tokenizer`, `xml`, `ctype`, `curl`, and
   writable `storage/` and `bootstrap/cache/` paths. Bypass them with `--skip-prereqs`.
2. Executes all database migrations (`migrate --force` against `database/migrations/`;
   the installer never runs `migrate:fresh`).
3. Provisions Spatie RBAC from `config/rbac.php`, which declares two dictionaries:
   platform roles (`rbac.platform_roles`) — `SuperAdmin` and `Auditor`, held by the
   account wherever it acts — and the standing inside one company
   (`rbac.membership_roles` + `rbac.membership_permissions`) — `owner`, `admin`,
   `staff`, `finance`, `auditor` — which is what actually grants business
   capabilities, read per organization from `organization_memberships.role`.
   `SellerAdmin`, `SellerStaff`, `BuyerAdmin` and `BuyerStaff` are retired to empty
   grant lists so old assignments still resolve; a migration detaches them from
   accounts. (`OperationsManager`, `ComplianceOfficer` and `PlatformAuditor` were
   documented here before; they exist nowhere in the code, and an undocumented role
   is a permission nobody can grant.)
4. Seeds currencies: `YER` (ريال يمني) as the **active default base** at rate `1.000000`,
   plus `USD`, `AED` and `SAR` as **inactive** placeholder rows at a neutral rate of
   `1.000000`. A secondary currency is unusable until an administrator records an
   approved exchange rate and activates it (see `CONFIGURATION.md`, Multi-Currency).
5. Seeds the platform settings from `App\Support\SettingCatalog::defaults()` — the same
   rows the runtime reads by those exact keys: platform names, `platform.country=YE`,
   `platform.default_city=عدن`, `platform.default_currency=YER`, `platform.default_locale=ar`,
   `platform.timezone=Asia/Aden`, `tax.enabled=false` with `tax.rate=0.0000`,
   `commission.rate=0.0000`, `reservation.timeout=30`, `inspection.window=48`,
   `demand.expiry_days=14`, `offer.expiry_days=60`, `offer.requires_review=false`,
   `legal.require_commercial_registration=false`, `legal.require_vat_number=false`,
   `matching.geo_radius_km=100`, `geo.max_radius_km=500`.
   **Neither a tax percentage nor a commission percentage ships as a default** — the operator
   supplies both, and the platform takes nothing until it does.
6. Seeds the feature flags from `App\Support\FlagCatalog::defaults()`: `marketplace`,
   `demand_rfq`, `matching_engine`, `geo_discovery` on; `api_keys`, `webhooks`,
   `double_entry_ledger` and `escrow` **off**, because each claims a capability
   (an authenticating route, a public receiver, a real chart of accounts, a payment
   gateway) that this deployment has not established yet.
7. Seeds the standard Chart of Accounts for the double-entry financial ledger.
8. Seeds the FMCG product classification from `App\Support\CategoryCatalog` — **categories
   only**. Brands are created by the businesses that supply them; the installer invents none.
   If brand rows exist in a database, they came from demo data or from a real organization.
9. Creates the first platform operator (`createFirstAdmin`) unless `--skip-admin` is passed —
   see the detail below.
10. Runs `storage:link`, and when `APP_ENV=production`, also `config:cache`,
   `route:cache` and `view:cache`.

Re-running the installer is safe: every step uses `updateOrCreate` / `firstOrCreate`.
Pass `--force` only when you intend to overwrite edited defaults.

### Step 9 in detail: the first platform operator
Unless `--skip-admin` is passed, the installer asks for a name, email and password and creates
the single `SuperAdmin` the setup wizard needs. `--admin-password=` is accepted for automation but
can leak into shell history — prefer the interactive prompt, and never commit a password. With
`--skip-admin` the platform has no operator, no SuperAdmin and no reachable setup wizard, and
every authenticated route stays untestable until one is created.

---

## 3. Step-by-Step Manual Setup

### Step 1: Environment Configuration
The template in `.env.example` is already production-shaped; copy it and fill in the
database credentials:

```bash
cp .env.example .env
php artisan key:generate
```

`backend/.env.example` ships with (excerpt — read the file for the full list):
```env
APP_NAME="HASSAL B2B Marketplace"
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Asia/Aden
APP_URL=https://marketplace.example.ye
APP_LOCALE=ar
APP_FALLBACK_LOCALE=en
APP_SUPPORTED_LOCALES=ar,en

DB_CONNECTION=mysql          # MySQL 8.4, utf8mb4 / utf8mb4_unicode_ci
SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database

# Deployment wiring read by config/hassal.php
HASSAL_BRAND_NAME=HASSAL
HASSAL_APP_VERSION=
HASSAL_FRONTEND_URL=https://marketplace.example.ye
HASSAL_TOKEN_TTL_MINUTES=720
HASSAL_GEO_MAX_RADIUS_KM=500
HASSAL_RESERVATION_TIMEOUT_MINUTES=30
HASSAL_RESERVATION_GRACE_SECONDS=60
HASSAL_PAYMENT_PROVIDER=manual
HASSAL_RATE_PROVIDER=manual
HASSAL_VERIFICATION_PROVIDER=manual
HASSAL_MAPS_PROVIDER=openstreetmap
HASSAL_WEBHOOK_ALLOW_PRIVATE_ENDPOINTS=false
HASSAL_WEBHOOK_MAX_ATTEMPTS=5
```

Notes:
- `https://marketplace.example.ye` is a placeholder — set `APP_URL` to your real deployment domain.
- `HASSAL_APP_VERSION` is **empty on purpose**. Until the release process declares it, clients read a
  null `app_version`; the code never prints a version it did not earn.
- `HASSAL_FRONTEND_URL` is the address the **browser** is served from. `routes/web.php` uses it for the
  two browser-facing redirects (the root redirect and the password-reset link). It is not `APP_URL`: if
  the API lives on `api.example.ye` and the site on `www.example.ye`, set `APP_URL` to the former and
  this to the latter. Left unset it falls back to `APP_URL`.
- **Rotating `APP_KEY` invalidates stored webhook signing secrets.** `webhooks.secret` is encrypted with
  this key (`Webhook::$casts`), so after a rotation old secrets cannot be decrypted and every receiver's
  signature stops verifying. Re-register the endpoints and hand out new secrets; there is no recovery path.
- `HASSAL_WEBHOOK_ALLOW_PRIVATE_ENDPOINTS=false` keeps "register a webhook" from becoming a way to make
  this server POST into its own network. Only a development machine should switch it on.
- The names this file used to list — `PLATFORM_COUNTRY`, `PLATFORM_DEFAULT_CURRENCY`, `TAX_ENABLED`,
  `TAX_RATE`, `TAX_INCLUSIVE`, `PLATFORM_COMMISSION_RATE`, `PLATFORM_INSPECTION_WINDOW_HOURS`,
  `WEBHOOK_MAX_RETRIES`, `IDEMPOTENCY_LIFETIME_HOURS` — **were removed because no line of code reads
  them.** Country, currency, tax, commission, timeouts and retry caps are `system_settings` /
  `feature_flags` data edited by an administrator; leaving env mirrors of them would let an operator
  set `TAX_RATE=0.15`, see nothing happen, and believe the platform was charging tax.

### Step 2: Run the System Initializer
```bash
php artisan app:install-system
```
This single command performs Steps 2a and 3 below (migrations, seeding, storage link and,
when `APP_ENV=production`, the config/route/view caches). Use the manual commands only
if you deliberately want to split the process, e.g. to inspect the schema before seeding.

### Step 2a (optional): Database Migration Only
```bash
php artisan migrate --force
```

### Step 3 (optional): Storage Link & Optimization
```bash
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## 4. Background Workers & Cron Scheduling

Configure system crontab to execute Laravel's scheduler every minute:

```crontab
* * * * * cd /path-to-backend && php artisan schedule:run >> /dev/null 2>&1
```

`routes/console.php` schedules exactly two commands, both every minute, so the cron
entry is required for correct platform behaviour:

- `app:expire-reservations` — releases expired stock reservations.
- `app:process-webhooks` — retries failed webhook deliveries, **one attempt per tick**, up to
  the cap in `HASSAL_WEBHOOK_MAX_ATTEMPTS` (default 5). There is no backoff delay: an hour with
  a dead endpoint consumes the whole attempt budget.

No queue worker is required. Nothing in `app/` implements `ShouldQueue` and there is no
`app/Jobs` directory, so `QUEUE_CONNECTION=database` is inert — a `queue:work` process would
idle forever. Start a worker only if queued work is introduced later.
