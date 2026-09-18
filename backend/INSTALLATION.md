# HASSAL System Installation Guide (دليل التثبيت والتهيئة)

This guide documents the formal production installation process for the **HASSAL B2B Marketplace** engine.

---

## 1. System Requirements

- **PHP**: 8.3.0 or higher
  - Required Extensions: `pdo_mysql`, `mbstring`, `openssl`, `bcmath`, `curl`, `json`, `tokenizer`, `xml`, `redis` (optional for caching).
- **MySQL**: 8.4 LTS
  - Engine: `InnoDB`
  - Character Set: `utf8mb4`
  - Collation: `utf8mb4_unicode_ci`
- **Composer**: 2.7+
- **Memory**: Minimum 2 GB RAM (4 GB recommended for production background workers).

---

## 2. Automated Installation Command

HASSAL includes an enterprise CLI installer that safely initializes the system without destructive operations:

```bash
php artisan app:install-system
```

### What `app:install-system` performs:
1. Executes all database migrations (`database/migrations/`).
2. Provisions Spatie RBAC Roles & Permissions (`SuperAdmin`, `SellerAdmin`, `SellerStaff`, `BuyerAdmin`, `BuyerStaff`, `PlatformAuditor`).
3. Seeds standard base currencies (`SAR` Base, `USD`, `AED`, `YER`).
4. Seeds central dynamic platform settings (Platform names, default VAT 15%, platform commission 2.5%, timeouts).
5. Seeds feature flags (`marketplace`, `demands`, `geo_discovery`, `escrow`, `webhooks`, `api_keys`, `disputes`).
6. Seeds the complete Chart of Accounts for double-entry financial ledgering.
7. Populates FMCG product categories and top regional brands.

---

## 3. Step-by-Step Manual Setup

### Step 1: Environment Configuration
Copy the production environment template and set your credentials:
```bash
cp .env.example .env
php artisan key:generate
```

Ensure `.env` contains:
```env
APP_NAME=HASSAL
APP_ENV=production
APP_DEBUG=false
APP_URL=https://marketplace.hassal.sa

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hassal_production
DB_USERNAME=hassal_db_user
DB_PASSWORD=YOUR_SECURE_PASSWORD

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
```

### Step 2: Database Migration
```bash
php artisan migrate --force
```

### Step 3: Run the System Initializer
```bash
php artisan app:install-system
```

### Step 4: Storage Link & Optimization
```bash
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## 4. Background Workers & Cron Scheduling

Configure system crontab to execute Laravel's scheduler every minute:

```crontab
* * * * * cd /path-to-backend && php artisan schedule:run >> /dev/null 2>&1
```

Run queue worker for asynchronous processing:
```bash
php artisan queue:work --tries=3 --timeout=90
```
