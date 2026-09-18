# HASSAL local setup

## Requirements

- PHP 8.3+ with `pdo_mysql`, `mbstring`, `openssl`, `bcmath`, `curl`, `xml`, and `zip`
- Composer 2.x
- MySQL 8.4
- Node.js 20+ for the web gateway

## Install

From `backend/`, copy the environment template and configure a local MySQL database:

```powershell
Copy-Item .env.example .env
```

Create a database, for example `CREATE DATABASE hassal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`, then set `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `.env`. Generate the key and install locked dependencies:

```powershell
composer install
php artisan key:generate
php artisan app:install-system
```

The installer performs non-destructive migrations, creates RBAC/configuration/catalog data, makes YER the base currency, and does not create users or demo commercial records.

Run the API:

```powershell
php artisan serve
php artisan queue:work --tries=3
php artisan schedule:work
```

From the repository root, run `npm install` then `npm run dev` to start the web gateway and client.

## URLs

- Web gateway: `http://localhost:3000`
- API base: `http://127.0.0.1:8000/api/v1`
- Health: `http://127.0.0.1:8000/api/v1/health`
- Flutter bootstrap: `http://127.0.0.1:8000/api/v1/bootstrap`

## Troubleshooting

Run `composer validate`, `php artisan migrate:status`, and `php artisan test` before diagnosing application behaviour. Do not use `migrate:fresh` against a production database. Exchange-rate-dependent currencies are deliberately inactive until an authorized administrator records an approved rate.
