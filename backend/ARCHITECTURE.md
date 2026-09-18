# HASSAL System Architecture (الهندسة المعمارية للنظام)

---

## 1. Domain Separation & Data Boundaries

To prevent systemic coupling and data corruption, HASSAL enforces clear structural separation:

- **User ≠ Organization**: A `User` is an individual physical identity. An `Organization` is a commercial legal entity (holding a CR Number and VAT certificate). Users belong to Organizations through `OrganizationMembership` records with granular RBAC permissions.
- **Product ≠ ProductVariant**: A `Product` represents the core brand SKU (e.g. "Almarai Long Life Milk 1L"). A `ProductVariant` captures unit packaging (e.g. "Carton of 12", "Pallet of 120 Cartons").
- **Offer ≠ Inventory**: An `Offer` represents a commercial listing with pricing, minimum order quantities, and batch expiration dates. `Inventory` quantities are tracked with `available_quantity`, `reserved_quantity`, and `sold_quantity`.
- **Offer ≠ Deal**: An `Offer` is an open marketplace listing. A `Deal` is an executed binding B2B contract between one Buyer and one Seller for an agreed quantity and price.
- **Deal ≠ Payment ≠ Ledger**: A `Deal` governs the fulfillment contract. A `Payment` records the transactional remittance. The `DoubleEntryLedger` (`JournalEntry` & `LedgerLine`) tracks exact balance movements across institutional accounts.
- **Dispute ≠ Deal**: A `Dispute` is an independent legal and operational claim filed against a Deal during the inspection window.

---

## 2. Concurrency & Stock Locking Architecture

HASSAL guarantees zero overselling and race-condition immunity during concurrent reservations:

```php
// Step 1: Begin Database Transaction
DB::transaction(function () {
    // Step 2: Acquire Exclusive Pessimistic Lock on MySQL Row
    $offer = Offer::where('id', $offerId)->lockForUpdate()->firstOrFail();

    // Step 3: Validate available stock under lock
    if ($offer->available_quantity < $requestedQuantity) {
        throw new Exception("Insufficient stock available.");
    }

    // Step 4: Atomically update available and reserved counters
    $offer->decrement('available_quantity', $requestedQuantity);
    $offer->increment('reserved_quantity', $requestedQuantity);

    // Step 5: Issue Reservation Record with Expiry
    return Reservation::create([...]);
});
```

---

## 3. B2B Deal State Machine

```
   [DRAFT]
      │
      ▼
   [PENDING_SELLER_APPROVAL] ──(Reject)──► [CANCELLED]
      │
   (Approve)
      ▼
   [ACCEPTED] ──► [PENDING_PAYMENT]
      │                 │
      │           (Escrow Fund)
      │                 ▼
      └─────────► [ESCROW_FUNDED] ──(Cancel before dispatch)──► [CANCELLED]
                        │
                  (Seller Process)
                        ▼
                  [PROCESSING]
                        │
                  (Dispatch / Logistics)
                        ▼
                  [DISPATCHED] / [READY_FOR_PICKUP]
                        │
                  (Proof of Delivery)
                        ▼
                  [DELIVERED]
                        │
                  (48h Inspection Window)
                        ▼
                  [INSPECTION] ──(Dispute filed)──► [DISPUTED] ──► [RESOLVED] / [REFUNDED]
                        │
                  (Buyer Acceptance / Window Closes)
                        ▼
                  [COMPLETED]
                        │
                  (Release Escrow & Ledger Settlement)
                        ▼
                  [SETTLED]
```

---

## 4. Double-Entry Accounting Model

Every financial lifecycle event produces balanced Journal Entries where:

$$\sum \text{Debits} = \sum \text{Credits}$$

### Standard Accounts:
- `1010-CASH-ESCROW`: Escrow Cash Asset Account.
- `2010-ESCROW-HOLDING`: Escrow Liability Account (funds held in trust for buyer/seller).
- `2020-SELLER-PAYABLE`: Seller Payable Account.
- `2030-VAT-LIABILITY`: VAT Tax Liability Account.
- `4010-PLATFORM-FEE`: Platform Commission Revenue Account.
