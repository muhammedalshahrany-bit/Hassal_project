# HASSAL Security Architecture & Threat Model (دليل الأمان والحماية)

---

## 1. Multi-Tenant Isolation & IDOR Protection

HASSAL enforces strict tenant boundaries:
- All sensitive database queries are scoped to the active tenant identified by `X-Organization-Id`.
- The `ResolveOrganizationContext` middleware validates that the authenticated `User` holds an active `OrganizationMembership` with the specified Organization before setting the execution context.
- Super-administrators can cross tenant boundaries exclusively for audit and system management purposes.

---

## 2. Cryptographic Security & API Keys

- **API Keys**: Stored exclusively as cryptographic SHA-256 hashes (`key_hash`). Plain-text secret tokens are presented to the organization administrator only once upon creation.
- **Webhooks**: Every outbound payload includes an `X-Hassal-Signature` header calculated as `hash_hmac('sha256', payload_json, webhook_secret)`.
- **Passswords**: Hashed using `bcrypt` (work factor 12) or Argon2id.

---

## 3. Idempotency & Replay Attack Defense

Mutation endpoints support the `X-Idempotency-Key` header:
- When a request is first processed, the response code and body are cached alongside the key and user ID.
- Duplicate or replayed requests with the identical key return the cached response with `X-Cache-Lookup: HIT`, preventing duplicate deal creations or charges.

---

## 4. Financial & Concurrency Safeguards

- Stock reservations use database transaction row locks (`lockForUpdate()`), preventing overselling even during high-frequency concurrent requests.
- All monetary fields are stored as `DECIMAL(18, 4)` to eliminate floating-point arithmetic errors.
- Double-entry ledger entries are immutable once posted. Corrections require compensating reversal entries.
