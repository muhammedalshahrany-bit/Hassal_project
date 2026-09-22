# HASSAL API Reference (دليل واجهة برمجة التطبيقات)

The HASSAL API engine provides RESTful JSON endpoints versioned under `/api/v1`.

---

## 1. Global Headers & Conventions

All API requests must supply:
- `Accept: application/json`
- `Content-Type: application/json`
- `Accept-Language: ar` (or `en`)
- `Authorization: Bearer <sanctum_token>` (for authenticated endpoints)
- `X-Organization-Id: <org_id>` (context header identifying active enterprise)
- `X-Idempotency-Key: <uuid>` (replay guard on mutations that carry the `idempotency` middleware). Send it and
  a retry within 24 hours returns the stored answer; reuse the same key with a different body and the request is
  refused with `IDEMPOTENCY_CONFLICT`. Omit it and the request runs normally with no replay protection — the
  middleware never blocks an unkeyed write, which is why "safe to retry" is the caller's choice to make.

---

## 2. Standard Response Schemas

### Standard Success Response (200 / 201)
```json
{
  "success": true,
  "message": "Offer created successfully",
  "data": { ... },
  "meta": {
    "timestamp": "2026-09-15T12:00:00Z"
  }
}
```

### Standard Paginated Response
`pagination` sits at the top level, next to `data`, and its keys are exactly the ones `ApiResponse::paginated()` emits.
```json
{
  "success": true,
  "message": "Data retrieved successfully",
  "data": [ ... ],
  "pagination": {
    "current_page": 1,
    "per_page": 15,
    "total_items": 45,
    "total_pages": 3,
    "has_more": true
  },
  "meta": {
    "timestamp": "2026-09-15T12:00:00Z",
    "correlation_id": "…"
  }
}
```

### Standard Error Response (4xx / 5xx)
This is the shape `ApiResponse::error()` returns — note there is no `message` and no `errors` key at the top level.
Validation refusals put the per-field messages under `error.details`.
```json
{
  "success": false,
  "error_code": "QUANTITY_EXCEEEDS_AVAILABLE",
  "error": {
    "code": "QUANTITY_EXCEEEDS_AVAILABLE",
    "message": "Requested quantity exceeds available quantity",
    "details": []
  },
  "meta": {
    "timestamp": "2026-09-15T12:00:00Z",
    "correlation_id": "…"
  }
}
```

> `QUANTITY_EXCEEEDS_AVAILABLE` carries a spelling error in the code itself. It is documented here as shipped
> because clients already match on it; renaming it is a breaking contract change, not a typo fix.

---

## 3. Key Endpoint Catalog

Generated from `php artisan route:list --path=api/v1` (**120 routes**, counted from `route:list --json` on
2026-09-19). Read this table as the route list, not as a design document: every path below exists in
`routes/api.php`, and a buyer-facing "reserve stock" route does not.

| Group | Method | Path | Description | Access |
| :--- | :--- | :--- | :--- | :--- |
| **System** | `GET` | `/api/v1/health` | Diagnostics & Engine Status | Public |
| **System** | `GET` | `/api/v1/bootstrap` | Client startup config: settings, flags, currencies, permissions | Public, or Auth + `tenant` |
| **System** | `GET` | `/api/v1/setup-wizard/status` | Whether the platform is configured | Public |
| **System** | `POST` | `/api/v1/setup-wizard/configure` | Write the first platform settings | `role:SuperAdmin` |
| **Auth** | `POST` | `/api/v1/auth/register` | Open a **person's** account (no company, no role; `organization_*`/CR/VAT fields are refused with a pointer to `/organizations`) | Public (throttled 10/min) |
| **Auth** | `POST` | `/api/v1/auth/login` | Login by email **or** phone & issue an expiring Sanctum token | Public (throttled 10/min) |
| **Auth** | `POST` | `/api/v1/auth/google` | Exchange a Google Identity Services ID token + its `nonce` for a session; refuses unless `auth.google_enabled` **and** `auth.google_client_id` are set | Public (throttled 10/min) |
| **Auth** | `POST` | `/api/v1/auth/logout` | Revoke the current token | Authenticated |
| **Auth** | `GET` | `/api/v1/auth/me` | Profile, memberships, this request's permissions, and `profile_completion` (what the account still owes, whether it has a company, whether it has a local password) | Authenticated |
| **Auth** | `POST` | `/api/v1/auth/switch-organization` | Change active organization context | Authenticated |
| **Auth** | `POST` | `/api/v1/auth/forgot-password` | Issue a reset token | Public (throttled 6/min) |
| **Auth** | `POST` | `/api/v1/auth/reset-password` | Consume a reset token | Public (throttled 6/min) |
| **Marketplace** | `GET` | `/api/v1/marketplace/offers` | Filterable offers catalog | Public |
| **Marketplace** | `GET` | `/api/v1/marketplace/offers/nearby` | Geo-radius nearby offers | Public, `feature:geo_discovery` |
| **Marketplace** | `GET` | `/api/v1/marketplace/offers/{id}` | Single offer | Public |
| **Marketplace** | `GET` | `/api/v1/marketplace/warehouses` | Warehouses shown on listings | Public |
| **Marketplace** | `GET` | `/api/v1/marketplace/categories` | Category tree | Public |
| **Marketplace** | `GET` | `/api/v1/marketplace/brands` | Brands | Public |
| **Marketplace** | `POST` | `/api/v1/marketplace/offers` | Create a DRAFT listing | `offers.create`, `feature:marketplace` |
| **Marketplace** | `GET` | `/api/v1/marketplace/my-offers` | This organization's listings, any status | `offers.view`, `feature:marketplace` |
| **Marketplace** | `POST` | `/api/v1/marketplace/offers/{id}/submit` | Put a DRAFT up for moderation | `offers.publish` |
| **Marketplace** | `PUT` | `/api/v1/marketplace/offers/{id}/status` | Activate / pause / cancel | `offers.publish` |
| **Marketplace** | `PUT` | `/api/v1/marketplace/offers/{id}` | Edit a DRAFT or PAUSED listing | `offers.update` |
| **Marketplace** | `DELETE` | `/api/v1/marketplace/offers/{id}` | Remove a listing | `offers.delete` |
| **Marketplace** | `GET` | `/api/v1/marketplace/moderation-queue` | Listings awaiting review | `offers.moderate` |
| **Marketplace** | `PUT` | `/api/v1/marketplace/offers/{id}/review` | Approve or reject a submission | `offers.moderate` |
| **Catalog** | `GET` | `/api/v1/catalog/products` | Shared product catalog | `offers.view` |
| **Catalog** | `GET` | `/api/v1/catalog/products/{id}/variants` | A product's variants | Authenticated |
| **Catalog** | `POST` | `/api/v1/catalog/products` | Add a product | `catalog.manage` |
| **Purchase requests** | `POST` | `/api/v1/purchase-requests` | Buyer's ask against an offer — **holds nothing** | `requests.view` + `requests.create` |
| **Purchase requests** | `POST` | `/api/v1/purchase-requests/matches/{matchId}` | The same ask raised from a demand match | `requests.view` + `requests.create` |
| **Purchase requests** | `GET` | `/api/v1/purchase-requests` | This organization's asks, either side | `requests.view` |
| **Purchase requests** | `GET` | `/api/v1/purchase-requests/{id}` | Single ask with its terms history | `requests.view` |
| **Purchase requests** | `POST` | `/api/v1/purchase-requests/{id}/accept` | Seller accepts — **this is where stock is held** | `requests.approve` |
| **Purchase requests** | `POST` | `/api/v1/purchase-requests/{id}/reject` | Seller declines with a reason | `requests.reject` |
| **Purchase requests** | `POST` | `/api/v1/purchase-requests/{id}/propose` | Seller counter-offer | `requests.update` |
| **Purchase requests** | `POST` | `/api/v1/purchase-requests/{id}/accept-proposal` | Buyer adopts the counter-offer | `requests.update` |
| **Purchase requests** | `POST` | `/api/v1/purchase-requests/{id}/cancel` | Buyer withdraws the ask | `requests.cancel` |
| **Reservations** | `GET` | `/api/v1/marketplace/reservations` | Holds made for the current buyer organization | `feature:marketplace` |
| **Reservations** | `POST` | `/api/v1/marketplace/reservations/{id}/cancel` | Release a hold back to the seller's shelf | `requests.cancel` |
| **Inventory** | `GET` | `/api/v1/inventory-movements` | The seller's stock ledger | `warehouses.view` |
| **Inventory** | `POST` | `/api/v1/inventory-movements/adjust` | Record IN / ADJUSTED / RETURNED | `warehouses.manage` |
| **Warehouses** | `GET` | `/api/v1/warehouses` | Organization warehouses | `warehouses.view` |
| **Warehouses** | `POST` | `/api/v1/warehouses` | Register a warehouse | `warehouses.manage` |
| **Demands** | `POST` | `/api/v1/demands` | Create buyer RFQ demand | `demands.view` + `demands.create`, `feature:demand_rfq` |
| **Demands** | `GET` | `/api/v1/demands` | This organization's demands | `demands.view`, `feature:demand_rfq` |
| **Demands** | `GET` | `/api/v1/demands/{id}/matches` | Offers matched to a demand | `demands.view` + `feature:matching_engine` |
| **Demands** | `POST` | `/api/v1/demands/{id}/quotations` | Seller quotation | `demands.view` + `demands.quote` |
| **Deals** | `GET` | `/api/v1/deals` | Organization deals, buyer/seller perspective | `deals.view`, `feature:marketplace` |
| **Deals** | `GET` | `/api/v1/deals/{id}` | Deal detail | `deals.view` |
| **Deals** | `POST` | `/api/v1/deals` | Create Deal from a held reservation | `deals.view` + `deals.create` |
| **Deals** | `POST` | `/api/v1/deals/{id}/transition` | State machine transition | `deals.view`; the target state declares its own permission and actor inside |
| **Disputes** | `GET` | `/api/v1/disputes` | Dispute list | `disputes.open` |
| **Disputes** | `POST` | `/api/v1/disputes` | Raise a dispute on a deal | `disputes.open` |
| **Disputes** | `POST` | `/api/v1/disputes/{id}/resolve` | Platform resolution | `disputes.resolve` |
| **Ledger** | `GET` | `/api/v1/ledger/accounts` | Chart of accounts | `ledger.view`, `feature:double_entry_ledger` |
| **Ledger** | `GET` | `/api/v1/ledger/journal-entries` | Double-Entry Journal Entries | `ledger.view`, `feature:double_entry_ledger` |
| **Ledger** | `GET` | `/api/v1/ledger/trial-balance` | Trial Balance Verification | `ledger.view`, `feature:double_entry_ledger` |
| **Ledger** | `GET` | `/api/v1/reconciliation/audit` | Ledger ↔ deal reconciliation | `ledger.view`, `feature:double_entry_ledger` |
| **Reports** | `GET` | `/api/v1/reports/sales-summary` | Aggregated sales | `reports.view` |
| **Reports** | `GET` | `/api/v1/reports/export/deals-csv` | Deals CSV export | `reports.view` |
| **Organizations** | `GET` | `/api/v1/organizations`, `/api/v1/organizations/{id}` | Read organizations | Authenticated + `tenant` |
| **Organizations** | `POST` | `/api/v1/organizations` | Register an organization — the only door to a company record: account-completeness gate, per-account founding ceiling, legal-identifier rules and CR/VAT uniqueness | Authenticated + `idempotency` |
| **Organizations** | `PUT` | `/api/v1/organizations/{id}` | Update an organization | `organizations.manage` |
| **Organizations** | `PUT` | `/api/v1/organizations/{id}/verification` | Record verification evidence | `organizations.verify` |
| **Organizations** | `GET` | `/api/v1/organizations/{id}/members` | List members | Authenticated + `tenant` |
| **Organizations** | `POST`/`PUT`/`DELETE` | `/api/v1/organizations/{id}/members[/{userId}]` | Add / change / remove a membership | `members.manage` |
| **Users** | `GET` | `/api/v1/users`, `/api/v1/users/{id}`, `/api/v1/users/roles` | Platform user directory | `users.manage` |
| **Users** | `PUT` | `/api/v1/users/{id}/status` | Activate / suspend a user | `users.manage` |
| **Users** | `POST`/`DELETE` | `/api/v1/users/{id}/roles[/{roleName}]` | Grant / revoke a role | `users.manage` |
| **Platform** | `GET` | `/api/v1/settings` | Current platform settings | `role:SuperAdmin` |
| **Platform** | `POST` | `/api/v1/settings/update` | Change a setting | `role:SuperAdmin` |
| **Platform** | `GET` | `/api/v1/feature-flags` | Feature flags | `settings.manage` |
| **Platform** | `POST` | `/api/v1/feature-flags/{key}/toggle` | Flip a flag | `settings.manage` |
| **Platform** | `GET`/`POST`/`PUT` | `/api/v1/currencies[...]` | Manage currencies and the default | `role:SuperAdmin` |
| **Platform** | `GET` | `/api/v1/audit-logs` | Audit trail — SuperAdmin sees all, others are scoped to their organization | `audit.view` |
| **Integration** | `GET`/`POST` | `/api/v1/api-keys` | List / issue B2B keys | `api.manage`, `feature:api_keys` |
| **Integration** | `DELETE` | `/api/v1/api-keys/{id}` | Revoke a key | `api.manage`, `feature:api_keys` |
| **Integration** | `GET`/`POST` | `/api/v1/webhooks` | List / register webhook endpoints | `api.manage`, `feature:webhooks` |
| **Integration** | `GET` | `/api/v1/webhooks/{id}/deliveries` | Delivery attempts | `api.manage`, `feature:webhooks` |
| **Integration** | `POST` | `/api/v1/webhooks/{id}/ping` | Test delivery | `api.manage`, `feature:webhooks` |
| **Notifications** | `GET` | `/api/v1/notifications`, `/api/v1/notifications/unread-count` | The acting user's inbox | `notifications.view` |
| **Notifications** | `POST` | `/api/v1/notifications/{id}/read`, `/api/v1/notifications/read-all` | Mark read | `notifications.view` |
| **Auth** | `PATCH` | `/api/v1/auth/profile` | Correct own name / phone / national id (never email, never the company) | Authenticated + `idempotency` |
| **Auth** | `POST` | `/api/v1/auth/password` | Change password; revokes the account's other tokens | Authenticated + `idempotency` |
| **Community** | `GET` | `/api/v1/marketplace/offers/{id}/comments` | The published question thread, replies nested | Public, `feature:community` |
| **Community** | `POST`/`DELETE` | `/api/v1/marketplace/offers/{id}/like` | Record / withdraw one attributed interest per organization | `offers.like`; POST also `verified_tenant` |
| **Community** | `GET` | `/api/v1/marketplace/liked-offers` | Which listings the current organization liked | Authenticated, `feature:community` |
| **Community** | `POST` | `/api/v1/marketplace/offers/{id}/comments` | Ask a question or reply (a reply to a reply flattens onto the question) | `comments.create` + `verified_tenant` |
| **Community** | `DELETE` | `/api/v1/marketplace/comments/{id}` | Withdraw: author, the author's org owner/admin, or platform | Authenticated (row-scoped) |
| **Community** | `POST` | `/api/v1/marketplace/comments/{id}/report` | Flag a comment, reason from a closed list | `comments.create` |
| **Moderation** | `GET` | `/api/v1/community/summary` | Six queue counters, read from the tables the queues read | `community.moderate` |
| **Moderation** | `GET` | `/api/v1/community/comments?status=` | `pending` (default) / `published` / `removed` / `reported` | `community.moderate` |
| **Moderation** | `PUT` | `/api/v1/community/comments/{id}/moderate` | `publish` / `remove` (reason required) / `pending` | `community.moderate` |
| **Moderation** | `GET` | `/api/v1/community/reports?status=` | Report queue, `open` by default | `community.moderate` |
| **Moderation** | `POST` | `/api/v1/community/reports/{id}/review` | `upheld` (reason required) / `dismissed`; `remove_comment` is a separate switch | `community.moderate` |
| **Catalog** | `PUT` | `/api/v1/catalog/products/{id}` | Correct an owned product; `sku` is not editable | `catalog.manage` |
| **Catalog** | `GET`/`POST` | `/api/v1/catalog/products/{productId}/images` | List / upload media (`images[]`; unusable files are reported per file, the rest are stored) | `catalog.manage` |
| **Catalog** | `PUT`/`DELETE` | `/api/v1/catalog/products/{productId}/images/{imageId}` | Cover, order, delete | `catalog.manage` |
| **Organizations** | `GET`/`POST` | `/api/v1/organizations/{organizationId}/documents` | Read / file the legal paperwork | read: own tenant, `organizations.verify` or SuperAdmin; write: `organizations.manage` |
| **Organizations** | `DELETE` | `/api/v1/organizations/{organizationId}/documents/{documentId}` | Withdraw an unreviewed copy | `organizations.manage` |
| **Organizations** | `PUT` | `/api/v1/organizations/{organizationId}/documents/{documentId}/review` | Accept or reject a filed copy | `organizations.verify` |
| **Organizations** | `GET` | `/api/v1/organizations/{organizationId}/documents/{documentId}/file` | Stream the copy through the expiring signed link the list returns | `signed` (no bearer token) |
| **Warehouses** | `PUT` | `/api/v1/warehouses/{id}` | Correct a depot, including re-read coordinates | `warehouses.manage` |

Every route in the protected group also passes `auth:sanctum` → `tenant` → `audit`, and every write route above
carries `idempotency`, so a replayed `X-Idempotency-Key` returns the first result instead of acting twice.
A suspended organization is refused by `tenant` with `403 ORGANIZATION_SUSPENDED` before any controller runs,
so a permission grant never outranks that.

### The one route that is not here

`POST /api/v1/marketplace/reservations` was removed. Stock cannot leave the shelf on a buyer's own click:
§19–§26 of the brief put the seller's decision between finding a listing and taking something off it. A
reservation row exists only as the result of `POST /api/v1/purchase-requests/{id}/accept`. Calling the old path
now returns `405`.
