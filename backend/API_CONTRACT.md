# HASSAL API Contract & Integration Specification

This document details the exact JSON contract specifications for client applications. The contract is client-agnostic: anything that speaks `/api/v1` can use it. Today the only client in this repository is the web application at the repository root — **no mobile client has been built**, so no section here has been verified against one.

---

## 1. Bootstrap Endpoint Contract (`GET /api/v1/bootstrap`)

Used by client apps at cold start to load all runtime requirements without hardcoding. The payload below is the shape produced by `App\Services\BootstrapService::getBootstrapPayload()` and wrapped by the standard `ApiResponse` envelope. **Every identity field is data-driven** — country, currency, locale, tax and commission come from `system_settings` / `currencies`, and the installer leaves them for an operator to set:

- `base_currency` is `YER` because the installer creates the Yemeni Rial as the active default currency (`currencies.iso_code = 'YER'`, and `offers.currency_code` defaults to `YER` in the schema).
- `country`, `default_locale` and `default_city` are `null` until the setup wizard writes them (`platform.country`, `platform.default_locale`, `platform.default_city`) — the example values below are what a configured Yemen installation returns, not a code constant.

```json
{
  "success": true,
  "message": "Application bootstrap data loaded successfully",
  "data": {
    "app_name": "HASSAL",
    "app_version": "2.0.0-production",
    "api_version": "v1",
    "system_status": "OPERATIONAL",
    "server_time": "2026-09-15T12:00:00+00:00",
    "default_locale": "ar",
    "supported_locales": ["ar", "en"],
    "active_locale": "ar",
    "country": "YE",
    "base_currency": "YER",
    "currencies": [
      {
        "id": 1,
        "iso_code": "YER",
        "name_ar": "ريال يمني",
        "name_en": "Yemeni Rial",
        "symbol": "ر.ي",
        "decimal_places": 2,
        "exchange_rate_to_base": "1.000000",
        "is_active": true,
        "is_default": true
      }
    ],
    "categories": [],
    "opportunity_reasons": [
      { "code": "SURPLUS_STOCK", "label_ar": "مخزون راكد وفائض", "label_en": "Surplus Stock" }
    ],
    "feature_flags": {
      "marketplace": { "key": "marketplace", "name_ar": "سوق الفرص والمخزون", "name_en": "Marketplace Engine", "is_enabled": true, "rules_json": null }
    },
    "platform_settings": {
      "tax": {
        "enabled": false,
        "name": "ضريبة المبيعات",
        "rate": 0.0,
        "inclusive": false
      },
      "commission_rate": 0.0,
      "reservation_timeout_minutes": 30,
      "inspection_window_hours": 48,
      "default_city": null,
      "public_configs": []
    },
    "user": null,
    "tenant": null
  },
  "meta": {
    "timestamp": "2026-09-15T12:00:00+00:00",
    "correlation_id": "01JH..."
  }
}
```

Notes for client implementers:
- `app_version` comes from `HASSAL_APP_VERSION` (`config/hassal.php:29`) and both `.env` and `.env.example`
  ship it **empty**, so a fresh install returns `"app_version": ""`. Do not render it as a version badge or
  gate behaviour on it; treat an empty string as "the operator has not labelled this deployment".
- `system_status` is measured per request — `OPERATIONAL` when the database answered moments before the payload was built, `DEGRADED` when it did not (`BootstrapService::databaseIsReachable()`). It is not a constant, so a client must render `DEGRADED` rather than assume the field always says the platform is fine. It reports database reachability only: no queue, storage volume or exchange feed is observed, so `OPERATIONAL` is not a guarantee that the platform is healthy.
- `currencies` contains **only active** rows. After a fresh install, `YER` (`is_default: true`, base) is the only active currency; secondary currencies (`USD`, `AED`, `SAR`) ship `is_active: false` and are therefore **absent** from this array until an administrator activates them and records an approved `exchange_rate_to_base`. Never hardcode a currency, symbol, country or tax rate — read them from this payload at runtime.
- `feature_flags` is an object keyed by flag `key` (not a flat boolean map), and each value is the flag row: `rules_json` is `null` unless the flag carries targeting rules.
- `user` and `tenant` are `null` for unauthenticated cold starts; when a Sanctum token is presented they are populated with roles, permissions, and the active organization (`role_in_organization`, `commercial_registration_number`, `vat_number`).
- `platform_settings.tax.rate`, `commission_rate`, `reservation_timeout_minutes`, `inspection_window_hours` and `default_city` are read from `system_settings` per request. A fresh install has **no** tax and **no** commission row, so both arrive as `0.0`; `30` and `48` are the code-level fallbacks used only while the `reservation.timeout` / `inspection.window` rows are absent. A client must treat them as data, never as constants — the earlier 2.5% commission and 15% VAT figures were removed from the schema because they were assumptions, not settings.

---

## 2. Webhook Event Payloads

Signed with HMAC-SHA256 over `"t={timestamp}.{jsonPayload}"` using the endpoint's secret, and sent in
`X-Hassal-Signature: t={timestamp},v1={signature}`. The receiver should recompute the signature and reject a
`timestamp` outside its replay window (`WebhookDispatcherService::verify()` uses 300 seconds).

The delivered body wraps the event — `data` is the event payload, and `timestamp` is a **Unix integer**, not an
ISO string:

```json
{
  "id": "evt_xxxxxxxxxxxxxxxxxxxxxxxx",
  "event": "deal.status_changed",
  "timestamp": 1789000000,
  "data": {
    "deal_id": 142,
    "deal_number": "DEAL-260915-ABCD",
    "from_status": "PENDING_PAYMENT",
    "to_status": "ESCROW_FUNDED",
    "updated_at": "2026-09-15T12:00:00+00:00"
  }
}
```

### The events that exist

| Event | Emitted from | `data` keys | Sent to |
| :--- | :--- | :--- | :--- |
| `deal.status_changed` | `DealStateMachineService` | `deal_id`, `deal_number`, `from_status`, `to_status`, `updated_at` | Buyer **and** seller organizations |
| `inventory.reserved` | `InventoryReservationService::reserve()` | `reservation_id`, `reservation_number`, `offer_id`, `quantity`, `expires_at` | The seller organization that owns the offer |
| `inventory.released` | `InventoryReservationService::release()` | `reservation_id`, `reservation_number`, `offer_id`, `quantity`, `reason` | The seller organization that owns the offer |

An endpoint subscribes with an event list; `["*"]` receives all three. Any other event name in an integration
specification is not implemented, and no delivery is attempted for it.
