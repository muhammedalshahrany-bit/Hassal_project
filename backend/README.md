# HASSAL B2B Marketplace Engine (حصّال)

**Enterprise B2B Wholesale, Food Surplus, & Opportunity Liquidation Platform**

[![Laravel](https://img.shields.io/badge/Laravel-13.x-red.svg)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-blue.svg)](https://php.net)
[![Database](https://img.shields.io/badge/Database-MySQL_8.4_InnoDB-orange.svg)](https://mysql.com)
[![License](https://img.shields.io/badge/License-Proprietary-darkgreen.svg)](#)

---

## 1. Executive Summary & Overview (نظرة عامة على النظام)

**HASSAL (حصّال)** is a production-oriented B2B Food & FMCG marketplace engine designed first for Yemen, with configuration and localization boundaries that allow later operation in additional countries and regions.

The platform provides:
- **Surplus & Short-Dated Batch Liquidation**: marketplace for secondary and wholesale food batches, driven by
  offers → purchase requests → seller acceptance → reservation → deal. A buyer never creates a deal directly.
- **Pessimistic Concurrency & Stock Locking**: `DB::transaction` + `lockForUpdate()` on the offer row inside
  `InventoryReservationService`, so a single hold cannot be double-allocated. This is a per-transaction guard,
  not a mathematical guarantee — it is covered by `InventoryReservationAndConcurrencyTest`, which drives the
  service in one process and does not simulate concurrent HTTP clients.
- **Strict Multi-Tenant Isolation**: `X-Organization-Id` resolved by `ResolveOrganizationContext`, `permission:`
  checks on each route, and `where('organization_id', …)` applied per query. There are no Eloquent global scopes
  and no `app/Policies` directory, so isolation depends on each query being scoped deliberately;
  `TenantIsolationAndIdorTest` exercises selected paths, not every route.
- **Double-Entry Financial Ledger**: journal entries with balanced debit/credit lines, recording an escrow
  *holding as an accounting entry*, tax liability and commission. **No third party takes custody of money** —
  the `escrow` flag ships off because no payment gateway is connected, and the `payments` table has no writer.
- **Dynamic Configuration & Feature Flags**: `system_settings` / `feature_flags` read at runtime through a
  cache-backed service, toggled without deployment; a disabled capability answers `503 FEATURE_DISABLED`.
- **RESTful API (`/api/v1`)**: 120 routes (`route:list --json`, 2026-09-19), currently consumed by the React
  single-page dashboard in this
  repository. **There is no Flutter client in the repo yet.** External integration means HMAC-signed webhooks
  and per-organization API keys — and no route authenticates an API key today, so a key grants no access.

---

## 2. Technology Stack & Prerequisites (التقنيات المعتمدة)

| Component | Technology | Version | Purpose |
| :--- | :--- | :--- | :--- |
| **Framework** | Laravel Framework | 13.x (installed: 13.32) | Backend REST API Engine |
| **Runtime** | PHP | 8.3+ | CLI, Worker, and FPM Runtime |
| **Primary Database** | MySQL | 8.4 LTS (InnoDB, utf8mb4) | Relational Storage & Row-Level Locking |
| **Authentication** | Laravel Sanctum | 4.x | Bearer Token Management |
| **Authorization** | Spatie Laravel Permission | 6.x | Role-Based Access Control (RBAC) |
| **Data Types** | Decimal Precision | `DECIMAL(18,4)` money · `DECIMAL(14,2)` quantities · `DECIMAL(18,6)` FX rates | Exact currency, quantity and rate math |

---

## 3. Core Architecture Highlights

```
                    ┌────────────────────────────────────────┐
                    │    Web client  /  any /api/v1 client    │
                    └───────────────────┬────────────────────┘
                                        │ (HTTPS / Bearer Sanctum)
                                        ▼
    ┌────────────────────────────────────────────────────────────────────────┐
    │                       HASSAL API Gateway (v1)                          │
    │  ├─ IdempotencyMiddleware (X-Idempotency-Key)                         │
    │  ├─ LocalizationMiddleware (Accept-Language: ar / en)                  │
    │  ├─ TenantContext (X-Organization-Id Resolution)                       │
    │  └─ AuditLogMiddleware (High-fidelity compliance trails)              │
    └───────────────────┬────────────────────────────────┬───────────────────┘
                        │                                │
                        ▼                                ▼
       ┌─────────────────────────────────┐   ┌─────────────────────────────┐
       │   Inventory & Deal Lifecycle    │   │  Double-Entry Ledger Engine │
       │  ├─ lockForUpdate() Reservation │   │  ├─ Balanced Journal Entries│
       │  ├─ State Machine Transitions   │   │  ├─ Escrow Holding (Debit)  │
       │  └─ Auto-Expiry Sweeper         │   │  └─ Platform Fees & VAT     │
       └────────────────┬────────────────┘   └──────────────┬──────────────┘
                        │                                   │
                        └─────────────────┬─────────────────┘
                                          ▼
                      ┌──────────────────────────────────────┐
                      │    MySQL 8.4 InnoDB Database Engine  │
                      └──────────────────────────────────────┘
```

---

## 4. Quick Start & Installation

```bash
# 1. Clone repository and install dependencies
cd /backend
composer install --no-dev --optimize-autoloader

# 2. Configure environment file
cp .env.example .env
php artisan key:generate

# 3. Execute the automated production installer
php artisan app:install-system

# 4. Start the server
php artisan serve --port=8000
```

---

## 5. Documentation Suite Index

- **[INSTALLATION.md](./INSTALLATION.md)**: Step-by-step installation, deployment, and environment setup.
- **[CONFIGURATION.md](./CONFIGURATION.md)**: Catalog of dynamic system settings, feature flags, and environment variables.
- **[ARCHITECTURE.md](./ARCHITECTURE.md)**: Deep-dive into Multi-tenancy, State Machine, Ledger, and Concurrency models.
- **[API.md](./API.md)** & **[API_CONTRACT.md](./API_CONTRACT.md)**: Complete endpoint contract, authentication, and headers.
- **[OPENAPI.yaml](./docs/openapi.yaml)**: Formal OpenAPI 3.1.0 specification.
- **[SECURITY.md](./SECURITY.md)**: Security protocols, IDOR hardening, RBAC, HMAC Webhook signatures.
- **[DEPLOYMENT.md](./DEPLOYMENT.md)**: Cloud Run, Docker, Nginx, and production optimization guide.
- **[TESTING.md](./TESTING.md)**: Automated test suite, concurrency simulation, and coverage reports.
- **[TROUBLESHOOTING.md](./TROUBLESHOOTING.md)**: Common failure scenarios and recovery procedures.
- **[PRODUCTION_CHECKLIST.md](./PRODUCTION_CHECKLIST.md)**: Pre-launch sign-off checklist.
- **[CHANGELOG.md](./CHANGELOG.md)**: Comprehensive record of versions and enhancements.
