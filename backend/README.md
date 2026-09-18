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
- **Surplus & Short-Dated Batch Liquidation**: Real-time marketplace for secondary and wholesale food batches.
- **Pessimistic Concurrency & Stock Locking**: Zero overselling guarantee via atomic database transactions with row-level locks.
- **Strict Multi-Tenant Isolation**: Header-driven (`X-Organization-Id`) tenant isolation with complete IDOR immunity.
- **Double-Entry Financial Ledger**: Immutable accounting recording escrow holding, VAT liability, and platform commission.
- **Dynamic Configuration & Feature Flags**: Real-time toggles and platform parameter management without code deployments.
- **Standardized RESTful API**: Production contract powering Flutter Mobile, Single-Page Web Applications, and ERP/WMS connectors.

---

## 2. Technology Stack & Prerequisites (التقنيات المعتمدة)

| Component | Technology | Version | Purpose |
| :--- | :--- | :--- | :--- |
| **Framework** | Laravel Framework | 13.x / 12.x | Backend REST API Engine |
| **Runtime** | PHP | 8.3+ | CLI, Worker, and FPM Runtime |
| **Primary Database** | MySQL | 8.4 LTS (InnoDB, utf8mb4) | Relational Storage & Row-Level Locking |
| **Authentication** | Laravel Sanctum | 4.x | Bearer Token Management |
| **Authorization** | Spatie Laravel Permission | 6.x | Role-Based Access Control (RBAC) |
| **Data Types** | Decimal Precision | `DECIMAL(18,4)` | Exact Currency & Quantity Calculations |

---

## 3. Core Architecture Highlights

```
                    ┌────────────────────────────────────────┐
                    │     Flutter App / Web Client / ERP     │
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
