# HASSAL Configuration Catalog & Parameter Reference (دليل الإعدادات والتهيئة)

HASSAL features a centralized, dynamic configuration engine backed by database tables (`system_settings` and `feature_flags`) with runtime caching and fallback to `.env`.

---

## 1. Dynamic System Settings Catalog (`system_settings`)

| Key | Description | Type | Default Value | Scope | Requires Restart? |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `platform_name_ar` | Official platform title in Arabic | `string` | `منصة حصّال للفرص والمخزون الغذائي` | Public | No (Instant) |
| `platform_name_en` | Official platform title in English | `string` | `HASSAL B2B Food & Opportunities Marketplace` | Public | No (Instant) |
| `platform_vat_rate` | Standard VAT tax rate | `decimal` | `0.1500` (15%) | Financial | No (Applies to new deals) |
| `platform_commission_rate`| Platform take-rate on deal volume | `decimal` | `0.0250` (2.5%) | Financial | No (Applies to new deals) |
| `reservation_timeout_minutes`| Window before uncompleted stock reservations auto-expire | `integer` | `30` minutes | Marketplace | No (Instant) |
| `inspection_window_hours`| Inspection period for buyer upon delivery | `integer` | `48` hours | Marketplace | No (Applies to new deals) |
| `enforce_cr_verification`| Require verified Commercial Registration for trading | `boolean` | `true` | Compliance | No (Instant) |

---

## 2. Dynamic Feature Flags (`feature_flags`)

Feature flags allow enabling or disabling modules on-the-fly without modifying code or restarting servers:

| Flag Key | Module Name (Ar / En) | Default | Effect When Disabled |
| :--- | :--- | :--- | :--- |
| `marketplace` | سوق الفرص والمخزون / Marketplace Engine | `true` | Hides marketplace browsing and offer creation. |
| `demands` | طلبات الشراء / Demands & RFQ | `true` | Disables buyer RFQ creation and seller quote submissions. |
| `geo_discovery` | البحث الجغرافي / Geographical Discovery | `true` | Disables radius-based warehouse discovery. |
| `escrow` | الضمان المالي / Escrow & Safeguard | `true` | Switches settlement logic to direct settlement mode. |
| `webhooks` | إشعارات الويب هوك / Webhooks Engine | `true` | Halts automated dispatch of developer webhook payloads. |
| `api_keys` | مفاتيح الربط البرمجي / B2B API Keys | `true` | Disallows external M2M API key issuance and auth. |
| `disputes` | مركز النزاعات / Dispute Resolution | `true` | Disables buyer dispute filing post-delivery. |

---

## 3. Supported Currencies (`currencies`)

| ISO Code | Name (Ar / En) | Symbol | Base Rate (to SAR) | Decimals |
| :--- | :--- | :--- | :--- | :--- |
| `SAR` (Base) | ريال سعودي / Saudi Riyal | `ر.س` | `1.000000` | 2 |
| `USD` | دولار أمريكي / US Dollar | `$` | `3.750000` | 2 |
| `AED` | درهم إماراتي / UAE Dirham | `د.إ` | `1.020000` | 2 |
| `YER` | ريال يمني / Yemeni Rial | `ر.ي` | `0.015000` | 2 |
