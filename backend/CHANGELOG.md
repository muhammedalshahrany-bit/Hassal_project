# HASSAL Engineering Changelog (سجل التغييرات والتحديثات)

All notable changes to the HASSAL B2B Marketplace codebase are documented in this file.

---

## [2.0.0-production] - 2026-09-15

### Added (إضافات جوهرية)
- **Productization Audit Complete**: Comprehensive audit and hardening of all backend systems.
- **Payment Abstraction**: Introduced `PaymentProviderInterface`, `PaymentGatewayManager`, `BankTransferPaymentProvider`, and `CashOnDeliveryPaymentProvider`.
- **Notification Abstraction**: Introduced `NotificationChannelInterface`, `NotificationManager`, `DatabaseNotificationChannel`, and `EmailNotificationChannel`.
- **Localization Engine**: Introduced `LocalizationMiddleware` supporting `Accept-Language` header resolution (`ar`, `en`) and language fallback.
- **Full Test Suite**: Added comprehensive feature tests covering multi-tenant isolation, IDOR prevention, atomic stock reservation, concurrency, deal state machine transitions, double-entry ledger balancing, currency conversions, and automated reservation expiry sweeps.
- **Scheduled Background Tasks**: Configured cron schedules in `routes/console.php` for automatic reservation sweeps and webhook retries.
- **Currency Support**: Added Yemeni Rial (`YER`) support alongside `SAR`, `USD`, and `AED`.
- **Complete Documentation Suite**: Delivered 13 formal documentation manuals (`README.md`, `INSTALLATION.md`, `CONFIGURATION.md`, `ARCHITECTURE.md`, `API.md`, `API_CONTRACT.md`, `OPENAPI.yaml`, `SECURITY.md`, `DEPLOYMENT.md`, `TESTING.md`, `TROUBLESHOOTING.md`, `PRODUCTION_CHECKLIST.md`, `CHANGELOG.md`).

### Fixed & Hardened (إصلاحات وأمان)
- **Zero Floating-Point Financials**: Enforced `DECIMAL(18,4)` precision across all monetary amounts, taxes, commissions, and quantities.
- **Pessimistic Concurrency**: Verified `lockForUpdate()` within atomic database transactions for all stock reservation and settlement operations.
- **Immutable Ledger**: Guaranteed that posted double-entry journal entries cannot be mutated; corrections require explicit compensating reversal entries.
- **Idempotency Protection**: Integrated `IdempotencyMiddleware` across all mutating HTTP POST/PUT operations.
- **Separation of Concerns**: Enforced domain separation across `User`, `Organization`, `Product`, `ProductVariant`, `Offer`, `Deal`, `Payment`, and `Dispute`.
