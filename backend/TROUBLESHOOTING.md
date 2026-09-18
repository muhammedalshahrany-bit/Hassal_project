# HASSAL Troubleshooting & Diagnostic Guide (دليل استكشاف الأخطاء والتشخيص)

---

## 1. System Health Check Endpoint

Query the health check endpoint to inspect system operational status:

```bash
curl -X GET https://marketplace.hassal.sa/api/v1/health -H "Accept: application/json"
```

Expected Response:
```json
{
  "success": true,
  "message": "HASSAL API Engine is running smoothly",
  "data": {
    "status": "healthy",
    "platform": "HASSAL B2B Marketplace API Engine",
    "version": "2.0.0-production",
    "target_db": "MySQL 8.4 (InnoDB)",
    "php_version": "8.3.x",
    "laravel_version": "13.x",
    "timestamp": "2026-09-15T12:00:00Z"
  }
}
```

---

## 2. Common Issues & Solutions

### Issue A: `ORGANIZATION_ACCESS_DENIED` (HTTP 403)
- **Cause**: The user is trying to perform an operation using an `X-Organization-Id` header for an organization they are not a member of.
- **Resolution**: Verify the user's active membership in `organization_memberships` table or select the correct organization ID via `/api/v1/auth/me`.

### Issue B: `INSUFFICIENT_STOCK` (HTTP 422)
- **Cause**: The requested reservation quantity exceeds the real-time `available_quantity` on the offer.
- **Resolution**: Query `/api/v1/marketplace/offers/{id}` to refresh latest real-time stock levels.

### Issue C: `INVALID_DEAL_TRANSITION` (HTTP 422)
- **Cause**: Attempting a state jump forbidden by the deal state machine (e.g. `DRAFT` directly to `SETTLED`).
- **Resolution**: Follow the strict sequential path defined in `ARCHITECTURE.md`.

### Issue D: `IDEMPOTENCY_REPLAY` (HTTP 200/201 with `X-Cache-Lookup: HIT`)
- **Cause**: The same `X-Idempotency-Key` was submitted within the cache TTL window.
- **Resolution**: Generate a new UUID v4 in `X-Idempotency-Key` for distinct new transactions.
