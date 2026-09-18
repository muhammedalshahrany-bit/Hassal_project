# HASSAL API Contract & Integration Specification

This document details the exact JSON contract specifications for external client applications, including the **Flutter Mobile App**, Web dashboards, and B2B ERP connections.

---

## 1. Bootstrap Endpoint Contract (`GET /api/v1/bootstrap`)

Used by client apps at cold start to load all runtime requirements without hardcoding:

```json
{
  "success": true,
  "message": "Bootstrap configuration loaded",
  "data": {
    "app": {
      "name_ar": "منصة حصّال للفرص والمخزون الغذائي",
      "name_en": "HASSAL B2B Food & Opportunities Marketplace",
      "version": "2.0.0-production",
      "environment": "production",
      "maintenance_mode": false
    },
    "locales": {
      "default": "ar",
      "supported": [
        {"code": "ar", "name": "العربية", "dir": "rtl"},
        {"code": "en", "name": "English", "dir": "ltr"}
      ]
    },
    "currencies": [
      {
        "code": "SAR",
        "name_ar": "ريال سعودي",
        "name_en": "Saudi Riyal",
        "symbol": "ر.س",
        "decimal_places": 2,
        "is_default": true,
        "exchange_rate_to_base": "1.000000"
      },
      {
        "code": "USD",
        "name_ar": "دولار أمريكي",
        "name_en": "US Dollar",
        "symbol": "$",
        "decimal_places": 2,
        "is_default": false,
        "exchange_rate_to_base": "3.750000"
      }
    ],
    "feature_flags": {
      "marketplace": true,
      "demands": true,
      "geo_discovery": true,
      "escrow": true,
      "webhooks": true,
      "api_keys": true,
      "disputes": true
    },
    "public_settings": {
      "platform_vat_rate": "0.1500",
      "reservation_timeout_minutes": "30",
      "inspection_window_hours": "48"
    },
    "categories": [ ... ],
    "user": { ... } // (null if unauthenticated)
  }
}
```

---

## 2. Webhook Event Payloads

HASSAL dispatches HMAC-SHA256 signed events with signature sent in `X-Hassal-Signature` header:

```json
{
  "event": "deal.status_changed",
  "timestamp": "2026-09-15T12:00:00Z",
  "payload": {
    "deal_id": 142,
    "deal_number": "DEAL-260915-ABCD",
    "from_status": "PENDING_PAYMENT",
    "to_status": "ESCROW_FUNDED",
    "updated_at": "2026-09-15T12:00:00Z"
  }
}
```
