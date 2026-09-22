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

The installer performs non-destructive migrations, creates RBAC/configuration/catalog data, makes YER the base currency, and then asks for the platform operator's name, email and password (`--admin-name`, `--admin-email`, `--admin-password` exist for unattended runs). It creates exactly that one user and no demo commercial records; no default password exists in this repository, and the value is never echoed or logged.

USD is seeded present but inactive until an administrator records a real rate. Set `HASSAL_FRONTEND_URL` to the address the browser actually uses (locally `http://localhost:3000`) — the password-reset redirect in `routes/web.php` points there.

Run the API:

```powershell
php artisan serve
php artisan schedule:work
```

There is no `queue:work` step. Nothing in `app/` implements `ShouldQueue` and there is no `app/Jobs` directory: webhook delivery happens inline, and the only scheduled work is two commands (`app:expire-reservations` releasing overdue reservations, `app:process-webhooks` retrying failed deliveries), which `schedule:work` runs every minute.

From the repository root, run `npm install` then `npm run dev` to start the web gateway and client.

## URLs

- Web gateway: `http://localhost:3000`
- API base: `http://127.0.0.1:8000/api/v1`
- Health: `http://127.0.0.1:8000/api/v1/health`
- Client bootstrap (locale, currency, categories, opportunity reasons, flags, tax/commission settings; plus roles and current organization when signed in): `http://127.0.0.1:8000/api/v1/bootstrap`

## Troubleshooting

Run `composer validate`, `php artisan migrate:status`, and `php artisan test` before diagnosing application behaviour. Do not use `migrate:fresh` against a production database. Exchange-rate-dependent currencies are deliberately inactive until an authorized administrator records an approved rate.
