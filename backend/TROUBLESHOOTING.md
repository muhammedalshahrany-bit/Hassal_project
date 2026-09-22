# HASSAL Troubleshooting & Diagnostic Guide (دليل استكشاف الأخطاء والتشخيص)

---

## 1. System Health Check Endpoint

Query the health check endpoint to inspect system operational status:

```bash
# Deployed instance (APP_URL from .env; the value below is the shipped placeholder)
curl -X GET https://marketplace.example.ye/api/v1/health -H "Accept: application/json"

# Local development gateway (server.ts proxying to the PHP server on :8000)
curl -X GET http://localhost:3000/api/v1/health -H "Accept: application/json"
```

Expected Response (shape from the `/v1/health` closure in `routes/api.php`; the
version fields reflect the running stack):
```json
{
  "success": true,
  "message": "HASSAL API Engine is running smoothly",
  "data": {
    "status": "healthy",
    "platform": "HASSAL B2B Marketplace API",
    "version": "2.0.0-production",
    "target_db": "MySQL 8.4 (InnoDB)",
    "php_version": "8.3.x",
    "laravel_version": "13.x",
    "timestamp": "2026-09-15T15:00:00+03:00"
  },
  "meta": { "timestamp": "2026-09-15T15:00:00+03:00", "correlation_id": "…" }
}
```

---

## 2. Common Issues & Solutions

All errors below are returned through the standard error envelope produced by
`App\Http\Responses\ApiResponse::error()`:

```json
{
  "success": false,
  "error_code": "ORGANIZATION_ACCESS_DENIED",
  "error": { "code": "ORGANIZATION_ACCESS_DENIED", "message": "…", "details": [] },
  "meta": { "timestamp": "…", "correlation_id": "…" }
}
```

### Issue A: `ORGANIZATION_ACCESS_DENIED` (HTTP 403)
- **Source**: `ResolveOrganizationContext` middleware.
- **Cause**: The request carries an `X-Organization-Id` for an organization the caller
  has no **active** membership in (SuperAdmin is exempted).
- **Resolution**: Verify the user's active membership in `organization_memberships`, or
  select the correct organization ID via `/api/v1/auth/me`.

### Issue B: `PURCHASE_REQUEST_ACCEPT_FAILED` (HTTP 422)
- **Source**: `PurchaseRequestController::accept()` catch-all — the only place a
  reservation is created, since the buyer-facing `POST /marketplace/reservations`
  route was removed.
- **Cause**: The seller's acceptance could not be honoured. The usual message coming
  out of `InventoryReservationService` is `Insufficient available stock`, because the
  offer's `available_quantity` dropped below the requested amount between the buyer's
  ask and the acceptance. `OFFER_EXPIRED` is returned separately when the listing's
  own validity ended first.
- **Resolution**: Re-read the offer via `/api/v1/marketplace/offers/{id}` for current
  stock, then counter-offer (`/propose`) or reject (`/reject`). The request stays
  `REQUESTED`, and no quantity moved. There is no `RESERVATION_FAILED` and no
  `INSUFFICIENT_STOCK` code in the API today.

### Issue B2: `RESERVATION_NOT_ACTIVE` (HTTP 422)
- **Source**: `ReservationController::cancel()`.
- **Cause**: The hold already ended — converted into a deal, timed out by the sweep,
  or released by an earlier call — so this release would have been a no-op reported
  as a success.
- **Resolution**: Read `error.details.status`, and refresh the reservation list rather
  than retrying the same call.

### Issue C: `STATE_TRANSITION_FAILED` (HTTP 422)
- **Source**: `DealController::transition()` catch block.
- **Cause**: The state machine rejected the jump, e.g.
  `Invalid deal state transition from 'DRAFT' to 'SETTLED'.`
- **Resolution**: Follow the sequential path documented in `ARCHITECTURE.md`. There is
  no dedicated `INVALID_DEAL_TRANSITION` code in the API today.

### Issue D: `IDEMPOTENCY_CONFLICT` (HTTP 422)
- **Source**: `IdempotencyMiddleware`.
- **Cause**: The same `X-Idempotency-Key` was submitted with a **different** request
  body/hash within the TTL window.
- **Resolution**: Generate a new UUID v4 in `X-Idempotency-Key` for a distinct
  transaction. Re-sending the *identical* request with the same key is not an error:
  the original response is replayed verbatim (same status code) with the headers
  `X-Cache-Lookup: HIT` and `X-Idempotency-Replay: true`. There is no
  `IDEMPOTENCY_REPLAY` code in the API today.
