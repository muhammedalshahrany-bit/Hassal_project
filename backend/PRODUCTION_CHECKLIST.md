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
- [x] Foreign key constraints, composite unique constraints, and indexes verified across all 26 migrations.
- [x] Zero floating-point data types in financial and quantity columns (`DECIMAL(18,4)` enforced).
- [x] Concurrency-safe pessimistic locking (`lockForUpdate()`) tested on stock reservations.

## 3. Financial & Accounting Engine
- [x] Chart of Accounts initialized and verified.
- [x] Double-entry ledger enforces exact balance ($\sum \text{Debits} = \sum \text{Credits}$) on all journal entries.
- [x] VAT calculation (15%) and platform commission (2.5%) verified on deal creation.
- [x] Immutable posted journal entries policy enforced.

## 4. API & External Readiness
- [x] `/api/v1/bootstrap` endpoint provides complete initialization payload for Flutter and Web clients.
- [x] `Accept-Language` localization middleware active (supporting `ar` and `en`).
- [x] Background cron scheduler registered for reservation expiration sweeps and webhook delivery retries.
- [x] OpenAPI 3.1.0 specification verified and synchronized.

## 5. Integration Distinction
- **IMPLEMENTED (Production-Ready)**:
  - Bank Wire Transfer Settlement
  - Cash on Delivery (B2B Settlement)
  - Database Notification Channel
  - Email Notification Channel
  - Webhooks Dispatcher Engine
  - B2B API Keys System
- **ARCHITECTURE READY / PROVIDER INTEGRATION REQUIRED**:
  - Mada / Saudi Debit Cards (Requires HyperPay/Tap/Moyasar enterprise merchant account)
  - Apple Pay direct merchant integration
  - STC Pay business gateway
  - Production SMS Gateway (Unifonic / Taqnyat)
  - Firebase Cloud Messaging (FCM Mobile Push)
  - ZATCA Phase 2 E-Invoicing Cryptographic Integration
