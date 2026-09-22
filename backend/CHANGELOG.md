# HASSAL Engineering Changelog (سجل التغييرات والتحديثات)

All notable changes to the HASSAL B2B Marketplace codebase are documented in this file.

---

## [Unreleased] - community, media and verification round (2026-09-19)

### Added
- **Offer community, as attributed rows rather than client-side counters.** `OfferLikeController` and
  `OfferCommentController` record interest, question threads and reports with the `user_id` and
  `organization_id` that produced them, which is what makes removing them something the backend can
  check. A like is one row per actor per organization on an `ACTIVE` listing only; a reply to a reply
  flattens onto the question; `is_seller_reply` is decided by comparing the writer's organization to the
  seller's, never accepted from the client; withdrawal keeps the row at `status: removed` because a
  thread that silently rewrites itself is what a later dispute turns on. No star ratings and no seller
  score exist anywhere in this round — a single number would summarise a business nobody has read.
- **Platform moderation of that surface** (`CommunityModerationController`): a comment queue
  (`pending|published|removed|reported`), a report queue, and `GET /community/summary` whose six counters
  are read from the same tables the queues read. Upholding a report and removing the comment are two
  separate switches, so a reviewer's decision cannot be quietly half-applied.
- **Product media** (`ProductImageController`): upload, cover, ordering and deletion behind
  `catalog.manage`, with type detected from the file's bytes and the stored path generated server-side.
  Unusable files are reported per file while the rest are stored, rather than failing a whole batch.
- **Organization paperwork and the verification gate** (`OrganizationDocumentController`,
  `EnsureVerifiedOrganization`): a business files `commercial_registration|tax_certificate|trade_license|
  founding_statute|id_proof|other`, staff accept or reject each copy by name, and until the organization
  itself is `verified` the commercial acts refuse with `ORGANIZATION_NOT_VERIFIED` plus a checklist of
  what is missing. Filing, members, warehouses and browsing stay open — a gate that blocked the exit it
  demands would enforce onboarding by preventing onboarding. `accepted` means a staff member read this
  copy; no official register is consulted anywhere in this codebase.
- **Profile and depot correction**: `PATCH /auth/profile` (name, phone, national id — not the login
  address, not the company, not roles), `POST /auth/password` revoking the account's other tokens, and
  `PUT /warehouses/{id}` so a pin read from the browser can be corrected.
- Permissions added to `config/rbac.php` and the seeder: `offers.like`, `comments.create`,
  `community.moderate`. The first two are held by business roles; `community.moderate` is held by no
  business role, as reading another organization's reports is a platform act.
- Client screens for all of the above: the marketplace thread and interest controls, a favourites filter,
  `CommunityModerationPanel`, `StaffDocumentReview`, product images and product correction, browser
  geolocation offered (never assumed) when creating or correcting a warehouse, and a profile screen.
- Four feature files — `OfferCommunityTest`, `OrganizationVerificationTest`, `ProductImageTest`,
  `ProfileAndWarehouseEditTest`. Measured on 2026-09-19: **OK (213 tests, 1354 assertions)** over
  **120 `/api/v1` routes** (`route:list --json`).

### Security
- **Filed paperwork no longer sits on a public disk.** `OrganizationDocument` stores to the private
  `local` disk (`storage/app/private`), and `url()` mints an expiring signed route to the new
  `…/documents/{id}/file` action instead of returning a `/storage/...` path. A permanent URL for a
  registration scan survives every channel it can leak through and stays valid forever; a fifteen-minute
  signature bounds the window, and the link can only be minted by a request that already passed the same
  `maySee()` check. That check was also widened to match `review()`'s guard: a reviewer who may decide a
  paper had not been able to read it.

### Documentation
- `API.md`, `API_CONTRACT.md`, `SECURITY.md`, `TESTING.md`, `CONFIGURATION.md` and the root
  `API_CONTRACT.md` were brought back to the code: route and suite counts re-measured, the new endpoint
  groups and error codes written down, and the settings table given the two gate switches it was missing
  (`marketplace.require_verified_to_trade`, `community.moderate_before_publishing`).
- **Client↔route coverage re-measured, both directions.** Normalising `` `${id}` `` and `{param}` to the
  same placeholder, `src/lib/api.ts` issues 98 distinct paths and `routes/api.php` exposes 99 distinct
  paths across its 120 method-routes. Zero client calls point at a route that does not exist; the single
  route with no by-path call is `…/organizations/{id}/documents/{id}/file`, which is reached through the
  expiring signed URL the documents screen receives rather than a literal it could construct. The root
  `API_CONTRACT.md` count was corrected from 119 to 120 and `README.md`'s from 95 to 120. `READINESS_REPORT.md`
  at the repository root is a dated Arabic snapshot of what is provably working and what is not (2026-09-19),
  not a maintained document.

---

## [Unreleased] - corrections after 2.0.0-production (2026-09-19)

### Changed
- **Webhook signing secrets encrypted at rest**: `App\Models\Webhook` casts `secret` to `encrypted`, the
  plaintext is returned once at registration and never again, and
  `2026_09_19_090000_widen_webhooks_secret_for_encryption.php` widened the column for the ciphertext.
- **Frontend redirect is configuration**, not a string: `routes/web.php` reads `config('hassal.frontend.url')`
  ← `HASSAL_FRONTEND_URL`, replacing a hard-coded `http://localhost:3000`.
- **`system_status` is measured**: `BootstrapService` reports `OPERATIONAL`/`DEGRADED` from whether the
  database connection opens, instead of a constant.
- **CI runs again**: `.github/workflows/production.yml` triggered only on `main`/`production` while the only
  branch is `master`, so it had never executed. `master` was added to both triggers and a `web` job
  (`npm ci` → `tsc --noEmit` → `vite build`) gates the image build.

### Added
- `tests/Feature/RbacMatrixTest.php`, `tests/Feature/PricingAndCurrencyTest.php` and
  `tests/Feature/TenantIsolationAndIdorTest.php` (suite then 173 tests / 1092 assertions; 213 / 1354 after
  the round above).
- Client screens wired to real endpoints for the platform-admin layer (moderation, verification, users),
  settings/flags/currencies/audit/reports/webhooks, memberships, catalog, and the remaining marketplace,
  demand, dispute, ledger and seller actions. At that point the client's `/api/v1` calls and the route
  paths matched one-to-one at 79 each; the round above re-measured that pair.

### Documentation
- `README.md`, `INSTALLATION.md`, `CONFIGURATION.md`, `LOCAL_SETUP.md`, `MIGRATION_GUIDE.md`,
  `PRODUCTION_CHECKLIST.md`, `DEPLOYMENT.md`, `API_CONTRACT.md`, `SECURITY.md` and `TESTING.md` were
  corrected against the code: Saudi-pilot residue, a "Flutter client" that does not exist, an inert
  `QUEUE_CONNECTION=database`, `exchange_rate_to_base` read as a multiply instead of a divide, ledger
  immutability described as enforced, and a `queue:work --tries=3` instruction for jobs that do not exist.

---

## [2.0.0-production] - 2026-09-15

### Added (إضافات جوهرية)
- **Productization Audit Complete**: Comprehensive audit and hardening of all backend systems.
- **Payment Abstraction (written, not wired)**: Introduced `PaymentProviderInterface`, `PaymentGatewayManager`,
  `BankTransferPaymentProvider`, and `CashOnDeliveryPaymentProvider`. No controller, route or job references them,
  so no request path can move money through this layer yet.
- **Notification Abstraction**: Introduced `NotificationChannelInterface`, `NotificationManager`, `DatabaseNotificationChannel`, and `EmailNotificationChannel`.
- **Localization Engine**: Introduced `LocalizationMiddleware` supporting `Accept-Language` header resolution (`ar`, `en`) and language fallback.
- **Feature Test Suite**: 16 feature files plus `tests/Unit/OutboundEndpointPolicyTest.php`, all on
  `RefreshDatabase` against a `*_test` schema. Measured result on 2026-09-19: **OK (173 tests, 1092 assertions)**.
  Covers tenant isolation as rival-business attacks (`TenantIsolationAndIdorTest`), enforcement of the RBAC
  catalog (`RbacMatrixTest`), pricing and currency direction (`PricingAndCurrencyTest`), atomic reservation and
  its failure modes, deal state transitions, ledger balancing, webhook signing, expiry sweeps, auth and
  organization idempotency. The earlier `PricingAndCurrencyTest.php` was **rewritten**, not revived: the old
  file called three service methods that do not exist and asserted a SAR base with 15% VAT, so it now drives
  `calculateDealPricing()` and the conversion path that actually ships. There is no HTTP-level concurrency test.
- **Scheduled Background Tasks**: Configured cron schedules in `routes/console.php` for automatic reservation sweeps and webhook retries.
- **Currency Support**: `YER` is installed as the active default/base currency; `USD`, `AED` and `SAR` are seeded
  **inactive** with a neutral `1.000000` placeholder rate, because an unapproved rate is not a market price.
- **Complete Documentation Suite**: Delivered 13 formal documentation manuals (`README.md`, `INSTALLATION.md`, `CONFIGURATION.md`, `ARCHITECTURE.md`, `API.md`, `API_CONTRACT.md`, `OPENAPI.yaml`, `SECURITY.md`, `DEPLOYMENT.md`, `TESTING.md`, `TROUBLESHOOTING.md`, `PRODUCTION_CHECKLIST.md`, `CHANGELOG.md`).

### Fixed & Hardened (إصلاحات وأمان)
- **No Floating-Point Financials**: Monetary amounts use `DECIMAL(18,4)`, quantities `DECIMAL(14,2)`, exchange
  rates `DECIMAL(18,6)`, tax/commission snapshots `DECIMAL(6,4)`/`DECIMAL(10,4)` — per `information_schema`, not one blanket precision.
- **Pessimistic Concurrency**: `lockForUpdate()` inside `DB::transaction` on the offer row for every reservation and release
  (`InventoryReservationService`), and on the deal row for transitions. Verified in-process by tests; not by concurrent HTTP clients.
- **Append-only Ledger via API**: No route updates or deletes `journal_entries` or `ledger_lines`; corrections are modelled as
  reversal entries (`is_reversal`, `reversal_of_entry_id`) and `JournalEntry::isBalanced()` gates posting. There is no
  database trigger or row-level policy enforcing that — direct SQL can still mutate a posting.
- **Idempotency Protection**: `IdempotencyMiddleware` on mutating routes, keyed by the optional `X-Idempotency-Key` header;
  a replayed key returns the stored response, a reused key with a different payload is refused with `IDEMPOTENCY_CONFLICT`.
  Requests without the header are not deduplicated.
- **Separation of Concerns**: Enforced domain separation across `User`, `Organization`, `Product`, `ProductVariant`, `Offer`, `Deal`, `Payment`, and `Dispute`.
