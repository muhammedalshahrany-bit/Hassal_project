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
- `X-Idempotency-Key: <uuid>` (mandatory for safe retries on mutation endpoints)

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
```json
{
  "success": true,
  "message": "Offers retrieved successfully",
  "data": [ ... ],
  "pagination": {
    "total": 45,
    "per_page": 15,
    "current_page": 1,
    "last_page": 3,
    "from": 1,
    "to": 15
  }
}
```

### Standard Error Response (4xx / 5xx)
```json
{
  "success": false,
  "message": "Insufficient available stock for reservation",
  "error_code": "INSUFFICIENT_STOCK",
  "errors": null
}
```

---

## 3. Key Endpoint Catalog

| Group | Method | Path | Description | Access |
| :--- | :--- | :--- | :--- | :--- |
| **System** | `GET` | `/api/v1/health` | Diagnostics & Engine Status | Public |
| **System** | `GET` | `/api/v1/bootstrap` | Full Client Startup Config | Public/Auth |
| **Auth** | `POST` | `/api/v1/auth/register` | Register User & Organization | Public |
| **Auth** | `POST` | `/api/v1/auth/login` | Login & Issue Sanctum Token | Public |
| **Auth** | `GET` | `/api/v1/auth/me` | Profile & Current Organization | Authenticated |
| **Marketplace** | `GET` | `/api/v1/marketplace/offers` | Filterable Offers Catalog | Public/Auth |
| **Marketplace** | `GET` | `/api/v1/marketplace/offers/nearby` | Geo-radius Nearby Offers | Public/Auth |
| **Marketplace** | `POST` | `/api/v1/marketplace/offers` | Create New Offer Listing | SellerAdmin/Staff |
| **Reservations**| `POST` | `/api/v1/marketplace/reservations` | Atomic Stock Reservation | BuyerAdmin/Staff |
| **Deals** | `GET` | `/api/v1/deals` | List Organization Deals | Buyer/Seller |
| **Deals** | `POST` | `/api/v1/deals` | Create Deal from Reservation | BuyerAdmin/Staff |
| **Deals** | `POST` | `/api/v1/deals/{id}/transition` | State Machine Transition | Authorized Role |
| **Ledger** | `GET` | `/api/v1/ledger/journal-entries` | Double-Entry Journal Entries | PlatformAuditor/Admin |
| **Ledger** | `GET` | `/api/v1/ledger/trial-balance` | Trial Balance Verification | PlatformAuditor/Admin |
| **Demands** | `POST` | `/api/v1/demands` | Create Buyer RFQ Demand | BuyerAdmin |
| **Demands** | `POST` | `/api/v1/demands/{id}/quotations`| Submit Seller Quotation | SellerAdmin |
| **API Keys** | `POST` | `/api/v1/api-keys` | Generate B2B Integration Key | Admin |
| **Webhooks** | `POST` | `/api/v1/webhooks` | Register Webhook Endpoint | Admin |
