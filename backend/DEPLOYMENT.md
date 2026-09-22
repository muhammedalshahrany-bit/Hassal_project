# HASSAL Production Deployment Guide (دليل النشر والتشغيل)

Every statement below is read from files in this repository: `backend/Dockerfile`,
`backend/Procfile`-less process model, `routes/console.php`, `server.ts`, and
`backend/.env.example`. Where the repository does not yet provide something — a
compose file, an nginx site, a release version — it says so instead of
describing an ideal.

---

## 1. What actually runs

There are **two** processes, not one:

```
        Browser
           │  HTTPS (443 → your TLS terminator)
           ▼
┌──────────────────────────────────────────────┐
│  Web gateway — Node, `server.ts`, port 3000  │
│  serves the built client AND proxies         │
│  /api, /api/*, /sanctum/* → Laravel          │
└───────────────────────┬──────────────────────┘
                        │ same-host HTTP
                        ▼
┌──────────────────────────────────────────────┐
│  Laravel API — backend/Dockerfile            │
│  php:8.3-fpm-alpine, `php-fpm`, **port 9000**│
│  (the image ships FPM only: no web server,   │
│   no supervisor, no scheduler inside it)     │
└───────────────────────┬──────────────────────┘
                        ▼
┌──────────────────────────────────────────────┐
│  MySQL 8.4 (InnoDB, utf8mb4_unicode_ci)      │
└──────────────────────────────────────────────┘
```

The client talks to **relative** paths (`src/lib/api.ts` uses `fetch(path)`), and
`server.ts` is what forwards `/api/*` to the Laravel port. So the API never has
to be public: putting the gateway in front of it is the supported shape, and it
is why `HASSAL_FRONTEND_URL` exists separately from `APP_URL`.

`backend/Dockerfile` contains no `CMD` for migrations, storage links, or the
scheduler. Anything below that must happen at deploy time is a pipeline step or
a second container — the image will not do it for you.

---

## 2. Build and release steps

```bash
# --- API image ---
docker build -t hassal-api:<immutable-tag> backend/

# --- Web client + gateway ---
npm ci
npm run build     # vite build  →  client assets;  esbuild server.ts → dist/server.cjs
npm start         # node dist/server.cjs — the gateway, on PORT (3000 by default)
```

Run these against the release database, in this order, from an environment that
has `APP_KEY` already set:

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Notes on what is deliberately absent:

- **`composer install` is not a release step.** It already ran inside the image
  (`FROM deps`, `--no-scripts`), and `package:discover` ran at build time — which
  is exactly why `config:cache` must run at *deploy* time against the live
  environment, not be baked into the image.
- **`php artisan event:cache` is not needed.** There is no `app/Events` or
  `app/Listeners` directory; domain effects are inline service calls
  (`DOMAIN_MODEL.md` §5). Caching an empty event map is harmless but it is not a
  step this application depends on.
- **`queue:work` is not a step either.** No class in `app/` implements
  `ShouldQueue` and there is no `app/Jobs` directory, so `QUEUE_CONNECTION` is
  inert today and a worker would idle forever.

---

## 3. The scheduler is not optional

`routes/console.php` registers exactly two commands, both every minute with
`withoutOverlapping()`:

| Command | What breaks without it |
| :--- | :--- |
| `app:expire-reservations` | Expired holds keep stock reserved. Buyers see quantities they cannot actually get, and the release only happens lazily where a code path checks. |
| `app:process-webhooks` | Failed webhook deliveries are never retried. Each tick performs **one** attempt; there is no backoff, so an outage longer than `HASSAL_WEBHOOK_MAX_ATTEMPTS` minutes exhausts the budget. |

Run one of these on a single host (not every API replica):

```cron
* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1
```

or a dedicated container whose only command is `php artisan schedule:work`.
A platform replica count of one on Cloud Run / App Engine is a valid way to run
this: `schedule:work` needs a persistent process, so it must not share a
request-scoped container that can scale to zero.

---

## 4. First install versus later deploys

`php artisan app:install-system` is a **first-install** command, not a per-deploy
step. It migrates, seeds roles/permissions, writes the settings and flag catalogs,
makes YER the base currency, and then **prompts interactively** for the platform
operator's name, email and password (`InstallSystemCommand::createFirstAdmin()`);
it has no default password and echoes none.

- Run it once, by hand, before the platform serves traffic.
- For automation, pass `--admin-name=`, `--admin-email=`, `--admin-password=`
  (the command refuses a non-interactive run with no password rather than
  inventing one), or `--skip-admin` when the operator already exists.
- Do **not** put it in a pipeline that runs on every deploy: it rewrites
  `system_settings` and `feature_flags` rows from the catalogs, so an operator's
  edit can be reverted by a build.
- After it, run the setup wizard (`POST /api/v1/setup-wizard/configure`,
  `SuperAdmin` only) to record country, locale and city. Until then those read as
  `null`, and the client shows them as unconfigured — which is the intended
  behaviour, not a bug.

---

## 5. Environment

Minimum production-shaped set, from `backend/.env.example`:

```bash
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.<your-domain>          # where Laravel is reachable
HASSAL_FRONTEND_URL=https://<your-domain>  # where the browser actually is
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database                        # or redis
DB_CONNECTION=mysql
QUEUE_CONNECTION=database                   # inert today; no jobs are dispatched
HASSAL_WEBHOOK_ALLOW_PRIVATE_ENDPOINTS=false
HASSAL_APP_VERSION=                         # empty until the release process declares it
```

- `SESSION_SECURE_COOKIE=true` is what the live production `.env` carries; a
  TLS-terminating proxy in front must forward the protocol so Laravel still
  marks the request secure, or the session cookie is dropped.
- `HASSAL_WEBHOOK_ALLOW_PRIVATE_ENDPOINTS=false` is the SSRF guard. Turning it on
  in production lets any registered business point a webhook at your internal
  network and have this server POST to it.
- There is deliberately **no** `TAX_RATE`, `PLATFORM_COUNTRY` or
  `PLATFORM_COMMISSION_RATE` here; those are `system_settings` edited in the
  console (`CONFIGURATION.md` §4).
- Rotating `APP_KEY` makes stored webhook signing secrets undecryptable
  (`INSTALLATION.md` §Notes). Re-register the endpoints afterwards.

---

## 6. Things this repository does NOT provide

Stated so no one discovers them at 3 a.m.:

- **No compose file, no Kubernetes manifest, no nginx or Caddy site config.** The
  Dockerfile exposes FPM on 9000 and assumes a PHP-aware front end that this
  repository does not ship.
- **No health-based release gate wired up.** `GET /api/v1/health` exists and
  measures the database at request time; nothing consumes it automatically.
- **No global API rate limit.** Only four routes carry throttling middleware
  (`SECURITY.md` §7). A capacity plan that assumes per-client request limits is
  assuming something the code does not do.
- **No Content-Security-Policy or Strict-Transport-Security middleware.** TLS and
  these headers are the operator's job at the proxy.
- **No migration rollback rehearsal.** `migrate --force` is forward-only in
  practice; take a snapshot before a release that touches money or quantity
  columns.
- **No electronic payment rails.** The `payments` table has no writer reachable
  from a route (`PRODUCTION_CHECKLIST.md` §5). Money moves off-platform, and the
  ledger records what an operator says happened.
- **CI exists but has never run.** `.github/workflows/production.yml` triggers on
  `main` / `production`, and this repository's only branch is `master` — every
  commit here has gone unchecked. `master` is now in both trigger lists, and a
  `web` job (`npm ci` → `npm run lint` → `npm run build`) was added because the
  pipeline had no client step at all and `build-and-push` now waits on it.
  Two things remain true: the backend job runs `migrate:fresh --seed`, so it
  exercises the demo seed data alongside the fixtures; and `build-and-push`
  publishes to the placeholder `user/hassal-backend:latest` and needs
  `DOCKERHUB_USERNAME` / `DOCKERHUB_TOKEN`, so it cannot succeed until an
  operator names the registry and namespace.
