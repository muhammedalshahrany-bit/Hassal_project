# HASSAL Production Deployment Guide (دليل النشر والتشغيل)

---

## 1. Production Architecture (Cloud Run / Containerized)

HASSAL is architected for containerized deployment (e.g. Google Cloud Run, AWS ECS, or Kubernetes) running PHP 8.3 FPM / Nginx and connecting to managed MySQL 8.4 (Cloud SQL / RDS).

```
┌─────────────────────────────────┐
│     Cloud Load Balancer (HTTPS) │
└────────────────┬────────────────┘
                 │
                 ▼
┌─────────────────────────────────┐
│     Cloud Run Container (App)   │
│   ├─ Nginx Ingress (Port 3000)  │
│   ├─ PHP 8.3 Engine             │
│   └─ Laravel API Framework      │
└────────────────┬────────────────┘
                 │
                 ▼
┌─────────────────────────────────┐
│   Managed MySQL 8.4 (InnoDB)    │
│   (Private VPC Connection)      │
└─────────────────────────────────┘
```

---

## 2. Pre-Deployment Optimization Commands

Run these build steps inside the container image or deployment pipeline:

```bash
# 1. Install optimized production dependencies
composer install --no-dev --optimize-autoloader --no-interaction

# 2. Cache core Laravel structures
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 3. Apply database migrations safely
php artisan migrate --force

# 4. Ensure Chart of Accounts and base system data are initialized
php artisan app:install-system
```

---

## 3. Production Environment Checklist

- `APP_ENV=production`
- `APP_DEBUG=false`
- `DB_CONNECTION=mysql`
- `SESSION_DRIVER=database` (or `redis`)
- `CACHE_STORE=database` (or `redis`)
- `QUEUE_CONNECTION=database` (or `redis`)
- `LOG_CHANNEL=stack` / `stderr`
