# HASSAL Automated Testing Suite (دليل الاختبارات وجودة الكود)

---

## 1. Test Suite Architecture

HASSAL includes an enterprise test suite powered by **PHPUnit 11** covering all critical operational pathways:

| Test Suite File | Domain Covered | Key Scenarios |
| :--- | :--- | :--- |
| `TenantIsolationAndIdorTest.php` | Multi-Tenancy & IDOR | Cross-organization access blocks, forbidden offer updates, unauthorized deal access. |
| `InventoryReservationAndConcurrencyTest.php`| Concurrency & Stock | Atomic reservation, overselling prevention under lock, cancellation restoration. |
| `DealStateMachineAndEscrowTest.php` | State Transitions | Full deal lifecycle (`DRAFT` → `SETTLED`), illegal transition blocking, escrow creation. |
| `DoubleEntryLedgerTest.php` | Financial Integrity | Escrow funding, settlement debit/credit balancing, imbalanced entry rejection. |
| `PricingAndCurrencyTest.php` | Multi-Currency & VAT | SAR base rate conversions, 15% VAT and 2.5% platform fee calculations. |
| `WebhookAndApiKeyIntegrationTest.php` | Developer Integrations | Cryptographic API key hashing, HMAC-SHA256 signature verification. |
| `ReservationExpirySweepTest.php` | Background Cleaners | Automatic expiration of stale reservations and inventory release. |
| `OrganizationTest.php` | Identity & Idempotency | Organization creation, idempotency replay caching, context header switching. |

---

## 2. Running Automated Tests

```bash
# Run all feature and unit tests
php artisan test

# Or run with PHPUnit directly
vendor/bin/phpunit --colors=always
```

---

## 3. Concurrency Verification

To simulate concurrent load on reservation endpoints:
```bash
# Simulates 50 concurrent buyers attempting to reserve the last remaining stock
php artisan test --filter=InventoryReservationAndConcurrencyTest
```
Guaranteed outcome: Exactly one reservation succeeds up to available quantity; remaining requests receive `INSUFFICIENT_STOCK`.
