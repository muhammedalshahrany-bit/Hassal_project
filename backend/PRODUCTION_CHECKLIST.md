# HASSAL Pre-Production Launch Checklist (قائمة التحقق قبل الإطلاق الفعلي)

---

## 1. Security & Identity
- [x] Environment configured with `APP_ENV=production` and `APP_DEBUG=false`.
- [x] Unique, cryptographically strong `APP_KEY` generated.
- [x] Spatie RBAC roles and permissions seeded and validated.
- [x] Multi-tenancy context resolution middleware enabled and verified.
- [x] API Key secrets hashed using SHA-256 before database storage.
- [x] Outbound Webhook payloads signed with HMAC-SHA256 signatures.

## 2. Database & Data Integrity
- [x] MySQL 8.4 InnoDB database configured with `utf8mb4_unicode_ci`.
- [x] Foreign key constraints, composite unique constraints, and indexes verified across the migration set in `database/migrations/`.
- [x] Zero floating-point data types in financial and quantity columns — all `DECIMAL`: money `18,4`, quantities `14,2`, rates `6,4`, exchange rates `18,6`.
- [x] Concurrency-safe pessimistic locking (`lockForUpdate()`) tested on stock reservations.

## 3. Financial & Accounting Engine
- [x] Chart of Accounts initialized and verified.
- [x] Double-entry ledger enforces exact balance ($\sum \text{Debits} = \sum \text{Credits}$) on all journal entries.
- [x] Platform commission is read from `commission.rate` at deal creation and snapshotted on the deal. **The catalog default is `0.0000`** (SettingCatalog:52), so a fresh install charges nothing until an operator records a rate — verified by `tests/Feature/PricingAndCurrencyTest.php`.
- [x] Tax engine verified in its **shipped state: disabled** (`tax.enabled=false`, `tax.rate=0.0000`). Enabling it and recording a rate is an administrator decision per country of operation; no tax percentage is assumed by the product.
- [ ] Journal-entry immutability: **not enforced as a policy.** The API exposes the ledger read-only (`routes/api.php:237-241`, `permission:ledger.view` + `feature:double_entry_ledger`), so no request path edits an entry today — but there is no model guard and no database trigger preventing one. Enforcing it is a launch decision, not a completed item.

## 4. API & External Readiness
- [x] `/api/v1/bootstrap` serves reference data, roles and the current organization to the web client. (No mobile client exists in this repository; nothing has been verified against one.)
- [x] `Accept-Language` localization middleware active (supporting `ar` and `en`).
- [x] Background cron scheduler registered for reservation expiration sweeps and webhook delivery retries.
- [ ] OpenAPI 3.1.0 specification (`docs/openapi.yaml`) is a hand-maintained **subset** generated from `routes/api.php`; re-verify it against the route file before publishing it to partners.

## 5. Integration Distinction
- **WRITTEN, NOT WIRED (no route reaches it)** — `App\Services\Payments\PaymentGatewayManager` registers
  `BANK_TRANSFER` (`BankTransferPaymentProvider`, manual wire transfer + ledger posting) and
  `CASH_ON_DELIVERY` (`CashOnDeliveryPaymentProvider`) inside its own constructor, so the classes are coherent
  in isolation — but **no file outside `app/Services/Payments` references the manager, either adapter, or
  `PaymentProviderInterface`**: no controller, route, container binding, job or state transition resolves them.
  Nothing in a running request path can charge, refund or release through them, and no row is ever written to
  `payments`. Treat this layer as unbuilt from the API's point of view until a route authenticates, calls and
  records it under test.
- **IMPLEMENTED (Production-Ready)** — code exists and is reachable from a route:
  - `DatabaseNotificationChannel` and `EmailNotificationChannel`.
  - Webhooks dispatcher engine (HMAC-SHA256 signed outbound payloads, `OutboundEndpointPolicy` filtering,
    synchronous delivery + `app:process-webhooks` retry up to 5 attempts).
  - B2B API key **issuance and revocation** (`ApiKeyController`). Note: keys are not yet accepted as a request credential — see the gap below.
- **NOT IMPLEMENTED — no electronic payment gateway integration exists in this codebase:**
  - `PaymentGatewayManager::getSupportedProviders()` publishes exactly one additional entry, `ONLINE_PROVIDER`, with `is_ready: false` and `status: EXTERNAL_PROVIDER_REQUIRED`. It is a placeholder so clients can render an unavailable state; it performs no charging, capture, refund or webhook reconciliation.
  - Card / local debit rails (for example Mada, Apple Pay, STC Pay or any acquiring provider) are **not** implemented and are **not** required by the product. Each would be an optional, country-specific adapter implementing `App\Contracts\PaymentProviderInterface` and registered through `PaymentGatewayManager::registerProvider()`, activated by an operator for the market they serve. None of them defines HASSAL's identity.
  - E-invoicing / tax-authority integrations of any country (for example ZATCA Phase 2 cryptographic integration) are **not** implemented. What exists today is a generic tax snapshot on deals (`deals.tax_rate_snapshot`, `deals.tax_amount`, disabled by default) and a VAT output ledger account (`2030-VAT-OUTPUT`). No authority filing, ZIN/certification, or e-invoice issuance takes place.
  - Production SMS gateway and Firebase Cloud Messaging push: **not implemented** — no provider class, transport, or credential wiring exists; only the pluggable `NotificationChannelInterface` is in place for a future adapter.
