# HASSAL Configuration Catalog & Parameter Reference (دليل الإعدادات والتهيئة)

HASSAL features a centralized, dynamic configuration engine backed by database tables (`system_settings` and `feature_flags`) with runtime caching (`SystemSettingsService`, 1-hour cache TTL, invalidated immediately on write) and fallback to `.env` and a hard-coded service default.

**Identity rule (قاعدة الهوية):** HASSAL is a Yemeni product. Yemen (`YE` / `YER` / `ar` / `Asia/Aden`) is the seeded default, and country, currency, locale and tax are **administrator-configurable**. No country-specific system (currency regime, tax authority, payment rails) is intrinsic to the product, and no non-Yemeni value is enabled by default.

Sources of truth in code (this table only documents what those files actually write):
- `backend/app/Console/Commands/InstallSystemCommand.php` — `seedSystemSettings()`, `seedFeatureFlags()`, `seedCurrencies()`.
- `backend/app/Http/Controllers/Api/V1/SetupWizardController.php` — `POST /api/v1/setup-wizard/configure` (SuperAdmin) writes the commercial defaults at runtime.
- `backend/database/seeders/DatabaseSeeder.php` — demo/local data only (not run by the installer).
- `backend/.env.example` — deployment defaults.

---

## 1. Dynamic System Settings Catalog (`system_settings`)

Keys use **dot notation** (`tax.rate`, not `tax_rate`). Columns available per row: `key`, `value`, `type`, `group`, `description_ar`, `description_en`, `default_value`, `is_editable`, `is_public`, `validation_rules`, `organization_id` (per-organization override; global rows keep it `NULL`).

| Key | Description | Type | Value Written by `app:install-system` | Scope | Requires Restart? |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `platform.country` | Default platform country (ISO-3166-1 alpha-2), configurable | `string` | `YE` | Platform | No (Instant) |
| `platform.name_ar` | Official platform title in Arabic | `string` | `منصة حصّال` | Platform | No (Instant) |
| `platform.name_en` | Official platform title in English | `string` | `HASSAL` | Platform | No (Instant) |
| `platform.default_locale` | Default locale | `string` | `ar` | Platform | No (Instant) |
| `platform.default_city` | Launch city the platform starts from | `string` | `عدن` | Platform | No (Instant) |
| `platform.default_currency` | Platform base/label currency code | `string` | `YER` | Platform | No (Instant) |
| `platform.timezone` | Deployment timezone | `string` | `Asia/Aden` | Platform | No (Instant) |
| `tax.enabled` | Enable tax calculation at all | `boolean` | `false` | Tax | No (Applies to new deals) |
| `tax.name` | Tax label shown on documents | `string` | `ضريبة المبيعات` | Tax | No (Instant) |
| `tax.rate` | Tax rate as a decimal fraction (0–1); **set only after legal verification in the operating country** | `decimal` | `0.0000` | Tax | No (Applies to new deals) |
| `tax.inclusive` | Whether displayed prices include tax | `boolean` | `false` | Tax | No (Instant) |
| `commission.rate` | Platform take-rate on net deal value — **ships at zero; the operator sets it** | `decimal` | `0.0000` | Commission | No (Applies to new deals) |
| `reservation.timeout` | Minutes before uncompleted stock reservations auto-expire | `integer` | `30` | Operations | No (Instant) |
| `inspection.window` | Inspection period for buyer upon delivery, in hours | `integer` | `48` | Operations | No (Applies to new deals) |
| `demand.expiry_days` | Demand (RFQ) lifetime in days | `integer` | `14` | Operations | No (Instant) |
| `offer.expiry_days` | Offer lifetime in days | `integer` | `60` | Operations | No (Instant) |
| `offer.requires_review` | Moderate offers before they go live; read by `OfferController` when publishing (`PENDING_REVIEW` vs `ACTIVE`) | `boolean` | `false` | Operations | No (Instant) |
| `legal.require_commercial_registration` | Whether a CR number is required **when a business is registered** (`POST /organizations`, via `StoreOrganizationRequest`) — not at account sign-up, which creates a person and no company. Gates the form only; it does not mean any official register was checked | `boolean` | `false` | Legal | No (Instant) |
| `legal.require_vat_number` | Whether a tax number is required on the same request (same limitation as above; blank is stored as `NULL`, never as a placeholder that reads like a real number) | `boolean` | `false` | Legal | No (Instant) |
| `marketplace.require_verified_to_trade` | The verification gate itself: `EnsureVerifiedOrganization` refuses commercial acts (publish, purchase request, deal, demand/quotation, dispute, like, comment) until staff set the organization to `verified` via `PUT /organizations/{id}/verification`. Switching it off removes the gate, not the paperwork. **Nothing here consults an official register** — `verified` records that this platform's staff read the filed copies | `boolean` | `true` | Trust | No (Instant) |
| `community.moderate_before_publishing` | Whether a new comment enters the queue as `pending` or appears in the public thread as `published` immediately. Read per comment by `OfferCommentController` | `boolean` | `false` | Trust | No (Instant) |
| `matching.geo_radius_km` | Default radius used by the demand-matching engine | `integer` | `100` | Matching | No (Instant) |
| `geo.max_radius_km` | Maximum nearby-search radius the API accepts | `integer` | `500` | Matching | No (Instant) |
| `platform.installed` | Setup-wizard completion marker | `boolean` | not in the catalog — written by `POST /api/v1/setup-wizard/configure` | Platform | No (Instant) |
| `auth.google_enabled` | Whether a session may be opened with a Google account. Public (`is_public`), so the web client reads it from `bootstrap → platform_settings.public_configs` and draws the button only when it is on; `POST /auth/google` refuses `GOOGLE_SIGNIN_DISABLED` regardless of what any client believes | `boolean` | `false` | Auth | No (Instant) |
| `auth.google_client_id` | The OAuth client ID Google Cloud issued **to this deployment**. Public by design — Google's own documentation calls a client ID a public identifier, and the browser must hold it to ask for a token — which is why it is a setting an operator pastes rather than a constant compiled into the bundle. There is no client secret anywhere: an ID token is verified against Google's published keys. Empty with the switch on answers `GOOGLE_NOT_CONFIGURED` | `string` | `''` (empty) | Auth | No (Instant) |
| `auth.google_auto_register` | Whether a Google assertion for an address with no account opens one (else `GOOGLE_ACCOUNT_NOT_PROVISIONED`). Not public — a client must not know which addresses exist | `boolean` | `true` | Auth | No (Instant) |
| `account.require_phone_number` | Does this deployment demand a phone number from an account before it may found a business. Read by `AccountRequirements`, which both the refusal (`ACCOUNT_PROFILE_INCOMPLETE` at `POST /organizations`) and the report (`profile_completion.missing_fields`) are built from. Public | `boolean` | `true` | Auth | No (Instant) |
| `account.require_national_id` | Same for a national/civil ID. A number typed by the user and held by the platform — no register is consulted and none is claimed. Public | `boolean` | `false` | Auth | No (Instant) |
| `account.max_organizations_per_account` | How many businesses one account may **found** (memberships as `owner`; being hired as staff elsewhere does not count). `0` means no ceiling. `SuperAdmin` is exempt | `integer` | `3` | Auth | No (Instant) |
| `security.requests_per_minute` | Per-minute ceiling for one authenticated account; extras answer `429`. Read at request time by the limiter closures in `AppServiceProvider`, falling back to `config('hassal.rate_limit.per_account_per_minute')` | `integer` | `300` | Security | No (Instant) |
| `security.guest_requests_per_minute` | Same for unauthenticated addresses (`per_guest_per_minute` fallback) | `integer` | `60` | Security | No (Instant) |
| `security.organization_requests_per_minute` | A shared ceiling across every account of one organization (`per_organization_per_minute`) — what one tenant saturates is not other tenants' bandwidth | `integer` | `1500` | Security | No (Instant) |
| `uploads.quota_bytes_per_organization` | Total bytes one organization may hold in product images and filed documents; `0` means unlimited. Read by `App\Support\UploadQuota` before a file is accepted, and reported as `DOCUMENT_LIMIT_REACHED` / `IMAGE_LIMIT_REACHED` | `integer` | `268435456` (256 MiB) | Uploads | No (Instant) |

> **Three keys that used to be listed here do not exist in the code** and were removed:
> `marketplace.require_offer_moderation` (the real key is `offer.requires_review`),
> `compliance.enforce_kyb_verification` (the real keys are the two `legal.require_*` switches) and
> `geo.default_radius_km` (the real keys are `matching.geo_radius_km` / `geo.max_radius_km`).
> Documenting a key the runtime never reads is how an administrator's edit silently changes nothing.
> Every row above is `App\Support\SettingCatalog::defaults()`, which `InstallSystemCommand::seedSystemSettings()`
> writes with `organization_id = null`.
>
> **No VAT percentage is shipped as a default.** `tax.enabled` is `false` and `tax.rate` is `0.0000` until an administrator records a rate for the country of operation. `PricingAndCurrencyService` snapshots whatever `tax.enabled`/`tax.rate` resolve to at deal-creation time into `deals.tax_rate_snapshot` (`DECIMAL(6,4)`, default `0.0000`).

---

## 2. Dynamic Feature Flags (`feature_flags`)

Seeded by `InstallSystemCommand::seedFeatureFlags()` from `App\Support\FlagCatalog::defaults()`. Toggled at
runtime via `POST /api/v1/feature-flags/{key}/toggle` (SuperAdmin). A disabled capability answers
`503 SERVICE UNAVAILABLE` with error code `FEATURE_DISABLED` — `App\Http\Middleware\EnsureFeatureEnabled`
(alias `feature:{key}`), which is the only place `FeatureFlagService::isEnabled()` is consulted for routing.

| Flag Key | Module Name (Ar / En) | Seeded default | Where it is actually enforced |
| :--- | :--- | :--- | :--- |
| `marketplace` | سوق العروض والطلبات / Marketplace | `true` | `feature:marketplace` on the whole `/marketplace/*` group and on `/deals/*`. Off ⇒ browsing, offers, requests, reservations and deals all return 503. |
| `demand_rfq` | طلبات الشراء وعروض الأسعار / Demands & RFQ | `true` | `feature:demand_rfq` on the `/demands/*` group. Off ⇒ RFQ creation and seller quotes return 503. |
| `matching_engine` | محرك المطابقة / Matching Engine | `true` | `feature:matching_engine` on `GET /demands/{id}/matches`. Off ⇒ matching only. |
| `geo_discovery` | الفرص القريبة / Nearby Discovery | `true` | `feature:geo_discovery` on `GET /offers/nearby`. Off ⇒ radius search is unavailable; city-text search still works. |
| `api_keys` | مفاتيح API / API Keys | `false` | `feature:api_keys` on the `/api-keys/*` group. **Off by design:** issuing a key is built (hashed, scoped, revocable) but no request guard authenticates one, so `ApiKeyAuthenticationService::authenticate()` has no callers and a key handed to a partner opens nothing today. |
| `webhooks` | إشعارات الويب هوك / Webhooks | `false` | `feature:webhooks` on the `/webhooks/*` management group. Delivery itself goes through `WebhookDispatcherService` + the `app:process-webhooks` command; out URLs are filtered by `OutboundEndpointPolicy` (internal addresses refused) and signed with HMAC. |
| `double_entry_ledger` | دفتر الأستاذ المزدوج / Double-Entry Ledger | `false` | Two places: `feature:double_entry_ledger` on `/ledger/*`, **and** `DealStateMachineService`, which at `COMPLETED` calls `DoubleEntryLedgerService::recordDealFee()` only when the flag is on — one fee accrual (`1010-FEE-RECEIVABLE` / `4010-COMMISSION-REVENUE`). Off ⇒ the ledger endpoints 503 and deal transitions post nothing. |

> **There is no `escrow` flag** (removed 2026-10-01 with the whole payment layer, per §20 of the platform
> master prompt). `FlagCatalog` carries no payment or escrow capability on purpose: HASSAL records deal
> money as a snapshot number, it never holds funds.

> **Flags that do not exist:** `demands`, `disputes`, `reconciliation`, `escrow` and `offer_review_required`
> are not flag keys. Disputes are gated by the `disputes.open` / `disputes.resolve` permissions; there is no
> reconciliation route at all; offer moderation is the `offer.requires_review` *setting*,
> not a flag. Keeping a switch that gates no code path would advertise a control that does not exist.
>
> **Current live values may differ from the seeded defaults** — they are operator data. As measured on
> 2026-10-01 in this deployment every flag matches its seeded default: `marketplace`, `demand_rfq`,
> `matching_engine`, `geo_discovery` and `community` are on, while `api_keys`, `webhooks` and
> `double_entry_ledger` are off.

---

## 3. Currencies & Exchange Rates (`currencies`)

The base currency is **data-driven, not hard-coded**: `PricingAndCurrencyService` resolves it from the `currencies` row where `is_default = true`.

`InstallSystemCommand::seedCurrencies()` forces every non-`YER` row to `is_default = false` and then writes:

| ISO Code | Name (Ar / En) | Symbol | `exchange_rate_to_base` | `is_active` | `is_default` |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `YER` (default base) | ريال يمني / Yemeni Rial | `ر.ي` | `1.000000` | `true` | `true` |
| `USD` | دولار أمريكي / US Dollar | `$` | `1.000000` (placeholder) | `false` | `false` |
| `AED` | درهم إماراتي / UAE Dirham | `د.إ` | `1.000000` (placeholder) | `false` | `false` |
| `SAR` | ريال سعودي / Saudi Riyal | `ر.س` | `1.000000` (placeholder) | `false` | `false` |

- **Direction of `exchange_rate_to_base`**: it answers "how many units of *this* currency equal one unit of the base". Converting a transaction amount *into* the base therefore **divides** by it: a 100 USD line at `0.004000` is `100 / 0.004 = 25,000` base units. Entering the reciprocal is not a cosmetic mistake — it inverts every converted figure. `PricingAndCurrencyService::calculateDealPricing()` and `tests/Feature/PricingAndCurrencyTest.php` both fix this direction; deal totals themselves always stay in the transaction currency.
- Secondary currencies (`USD`, `AED`, `SAR`) are seeded **inactive** on purpose. A rate is only meaningful after an authorized administrator records and approves it; `1.000000` is a neutral placeholder, not a market rate.
- `rate_source`, `rate_updated_at`, `rate_updated_by_user_id` audit columns exist on `currencies` (added by `2026_09_15_130022_add_currency_rate_audit_and_setting_overrides.php`) for that approval trail.
- Administered through the SuperAdmin-only endpoints `GET/POST /api/v1/currencies`, `PUT /api/v1/currencies/{id}`, `POST /api/v1/currencies/{id}/set-default`. Switching `set-default` to another currency is the supported way to operate the platform outside Yemen.
- `decimal_places` on `currencies` defaults to `2`.

---

## 4. Environment Keys (`backend/.env.example`)

No application service reads `env()` directly: values are resolved through
`config/hassal.php` or the `system_settings` table, so a deployed
`php artisan config:cache` cannot freeze a stale copy of a setting an
administrator has since changed.

| Env Key | Read By | Purpose |
| :--- | :--- | :--- |
| `HASSAL_RESERVATION_TIMEOUT_MINUTES` | `InventoryReservationService`, `BootstrapService` | The only business fallback in code: the hold duration used when the `reservation.timeout` row is absent |
| `HASSAL_RESERVATION_GRACE_SECONDS` | `InventoryReservationService` | Release grace so a reservation is not dropped while its owner is paying |
| `HASSAL_TOKEN_TTL_MINUTES`, `HASSAL_REMEMBER_ME_TTL_MINUTES` | `AuthController` | Bearer token lifetimes |
| `HASSAL_WEBHOOK_TIMEOUT_SECONDS`, `HASSAL_WEBHOOK_MAX_ATTEMPTS`, `HASSAL_WEBHOOK_ALLOW_PRIVATE_ENDPOINTS` | `WebhookDispatcherService`, `OutboundEndpointPolicy` | Delivery cost, retry budget, and whether a business may register a webhook that points inside this network |
| `HASSAL_APP_VERSION` | `BootstrapService`, health endpoint | Release surface, declared by the release process — an unset value shows as unset rather than a guessed one |
| `HASSAL_FRONTEND_URL` | `routes/web.php` (both browser redirects) | Where the browser-facing app lives; falls back to `APP_URL`. Not read by the JSON API |
| `APP_TIMEZONE` / `APP_LOCALE` | Laravel core | Runtime defaults (`Asia/Aden` / `ar` here) |

Declared in `config/hassal.php` but read by no code path today: `brand.name`,
the `geo.*` pair (`HASSAL_GEO_DEFAULT_RADIUS_KM` / `HASSAL_GEO_MAX_RADIUS_KM`;
discovery takes its radius from `matching.geo_radius_km` and its ceiling from
`geo.max_radius_km`, both settings) and the `integrations.*` provider names.

Names that appear in old notes and early installers but **no PHP code path
reads**:
`PLATFORM_COUNTRY`, `PLATFORM_DEFAULT_CURRENCY`, `PLATFORM_COMMISSION_RATE`,
`PLATFORM_RESERVATION_TIMEOUT_MINUTES`, `PLATFORM_INSPECTION_WINDOW_HOURS`,
`TAX_ENABLED`, `TAX_NAME`, `TAX_RATE`, `TAX_INCLUSIVE`, `WEBHOOK_MAX_RETRIES`,
`WEBHOOK_TIMEOUT_SECONDS`, `IDEMPOTENCY_LIFETIME_HOURS`. They are not in
`.env.example` either (the template says so at lines 55-56). Setting one changes
nothing: country, currency, tax and commission are edited in the console, not in
the environment (§27–§32). Every
`HASSAL_*` key in the table above **is** present in `.env.example`; the dead
`PLATFORM_*` / `TAX_*` names deliberately are not.

---

## 5. Changing Country, Currency, or Tax

1. **Setup wizard:** `POST /api/v1/setup-wizard/configure` (SuperAdmin, idempotent) writes `platform.name_*`, `platform.default_locale`, `platform.default_currency`, `tax.*`, `commission.rate`, `reservation.timeout`, `inspection.window`, moderation and KYB flags.
2. **Settings API:** `GET /api/v1/settings`, `POST /api/v1/settings/update` (SuperAdmin).
3. **Currencies API:** `POST /api/v1/currencies/{id}/set-default` to change the base currency; rates are entered by an administrator.
4. `platform.country` and `platform.default_city` are ordinary catalog
   settings: `POST /api/v1/settings/update` writes either one, and
   `POST /api/v1/setup-wizard/configure` writes both. Nothing falls back to
   `PLATFORM_COUNTRY`, so the environment is not an edit surface for them.
