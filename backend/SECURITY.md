# HASSAL Security Architecture & Threat Model (دليل الأمان والحماية)

Every claim below was checked against a file in this repository, with the path
named so it can be re-checked. Where the platform does not do something, this
document says so instead of describing an intention. Last verified: 2026-09-19,
against 120 routes under `/api/v1` (`artisan route:list --path=api/v1 --json`) and the suite reported in
`TESTING.md`.

---

## 1. Multi-Tenant Isolation

**How the tenant is chosen.** `app/Http/Middleware/ResolveOrganizationContext.php`
(alias `tenant`, registered on the authenticated group at `routes/api.php:125`)
resolves the bearer user, reads `X-Organization-Id`, loads that organization and
refuses the request unless the user either holds `SuperAdmin` or has an
**active** `OrganizationMembership` for it (`ORGANIZATION_ACCESS_DENIED`, 403).
Suspended and rejected organizations are refused separately
(`ORGANIZATION_SUSPENDED`, 403).

**Two behaviours that follow from that code and matter to integrators:**

- The header is optional. With no `X-Organization-Id` the middleware falls back
  to the user's **first active membership** (line 30) and carries on. A member of
  several businesses therefore acts on an arbitrary one unless the client sends
  the header. Clients must send it on every tenant-scoped call.
- Unauthenticated requests pass straight through (`line 22`), leaving
  `TenantContext` empty — which is what the public marketplace routes rely on.

**How data is kept apart.** There are **no** Eloquent global scopes and **no**
policy classes in this repository (`app/Policies` does not exist;
`grep -rn addGlobalScope app/` returns nothing). Isolation is implemented per
query inside the controllers, by filtering on `organization_id` or by resolving
the record through a relationship that is already scoped. 17 of the 23
`app/Http/Controllers/Api/V1/*` controllers reference `organization_id` or
`TenantContext`. The remaining six are, deliberately:

| Controller | Why it has no tenant filter |
| --- | --- |
| `SetupWizardController` | `/status` is an unauthenticated read of `platform.installed`; `/configure` is `role:SuperAdmin` and writes `system_settings`, not tenant rows (`routes/api.php:74-75`) |
| `UserManagementController` | `permission:users.manage`, held only by `SuperAdmin` (`routes/api.php:266`) |
| `CurrencyManagementController` | Platform-admin surface, `role:SuperAdmin` |
| `FeatureFlagController` | `permission:settings.manage`, which no business role holds (`routes/api.php:404`) |
| `NotificationController` | Scoped by `user_id`, not by organization (`NotificationController.php:16,26,34,42`) |

`/ledger/*` needs `ledger.view` + `feature:double_entry_ledger`, and
`ledger.view` is held by `SuperAdmin`, the platform `Auditor` (line 230) and the
organization roles `owner`, `admin`, `finance` and `auditor`
(`config/rbac.php:340,361,386,397`). That is not a cross-tenant leak:
`LedgerController` filters every query by the caller's active
organization (`organization_id`) unless the account carries `role:SuperAdmin`
(`LedgerController.php:29-36,59-62`) — so the books one company sees are its own,
and only `SuperAdmin` reads across organizations; even the platform `Auditor` is
organization-scoped by the same filter. There is no `/reconciliation/audit`:
the route and `ReconciliationController` were removed 2026-10-01 together with the
payment layer, so the table above no longer needs a row for them.

**This is not IDOR immunity — but it is now tested.** Because the boundary is
written per query rather than enforced by the framework, a new endpoint that
reads a tenant-owned table without a filter will not be stopped by anything
underneath it. The control that catches that is the test suite plus route review,
and `Tests\Feature\TenantIsolationAndIdorTest` (10 tests) is the dedicated sweep:
a rival business that holds *the same permissions as the victim* attacks another
organization's record by id across eight doors — offer edit and delete, deal read
and state transition, reservation cancel, purchase-request acceptance, warehouse
update, the audit trail, the seller's own shelf, and a forged
`X-Organization-Id` — and each must be refused while the underlying row stays
untouched. It also pins the case where the refusal is deliberately a 404
(`WarehouseController::update` scopes the lookup, so the rival's row does not
exist for that session).

Still uncovered by that sweep: demand and quotation reads, dispute resolution,
inventory movements, notification marking, and the ledger/report surfaces. Those
are per-query boundaries with no test behind them yet (task #2).

---

## 2. Credentials at Rest

- **Passwords** — `User::$casts` sets `'password' => 'hashed'`, so every write
  through the model goes through the default hasher: bcrypt at cost 12
  (`config('hashing')` resolves to `bcrypt`, `rounds: 12`, verified in this
  install). `HASH_DRIVER`, `BCRYPT_ROUNDS` and `HASH_VERIFY` are read from
  Laravel's shipped hashing config; none is set in `.env`, so the defaults
  apply. Argon2id is available but is not the selected driver. Rehash on login
  is on.
- **API keys** — stored as `key_secret_hash`, `hash('sha256', $secret)`
  (`ApiKeyAuthenticationService.php:19,63`), compared with `hash_equals`, with
  the secret returned exactly once at generation. **No guard accepts an API
  key today**: `authenticate()` has no caller outside `ApiKeyController`, which
  only creates and revokes rows. `config/hassal.php` / `App\Support\FlagCatalog`
  record the same, and the `api_keys` flag therefore grants storage, not access.
- **Webhook signing secrets** — `whsec_…` is returned once on create and is on
  the model's `$hidden` list, so it never appears in a list or detail response.
  It is now also **encrypted at rest** (`Webhook::$casts` →
  `'secret' => 'encrypted'`), because `$hidden` only governs serialization: the
  column previously held the plain key, so any database dump could forge signed
  deliveries to every registered endpoint. `webhooks.secret` was widened from
  128 to 512 characters by
  `database/migrations/2026_09_19_090000_widen_webhooks_secret_for_encryption.php`
  to hold the ciphertext envelope. The table was empty when this shipped; a
  database that already has rows must re-issue each secret (or re-encrypt the
  existing values) because plain-text rows in a column the model now decrypts
  will throw on read. **Consequence to plan for: rotating `APP_KEY` makes
  stored webhook secrets unrecoverable** — partners must re-register, so keep a
  key rotation step in the runbook.
- **Provider assertions (Google sign-in)** — `POST /api/v1/auth/google` takes the
  ID token the browser got from Google Identity Services and decides nothing the
  client asserted. `App\Services\Auth\GoogleIdTokenVerifier` pins `alg` to
  `RS256` from the header *before* any key is fetched (removing the `alg:none`
  and HMAC-with-public-key class), verifies the signature over the exact
  `header.payload` bytes against Google's published JWKS, then requires `iss` to
  be one of Google's two published spellings, `aud` to equal **this deployment's**
  `auth.google_client_id`, `exp`/`iat` to bound the token within a
  `hassal.auth.google.clock_leeway_seconds` leeway, `email_verified` to be true,
  and a stable `sub`. Keys are cached
  (`hassal.auth.google.jwks_cache_seconds`, default 6 hours, cache key salted by
  the JWKS URL); if Google is unreachable with an empty cache, sign-ins **fail**
  rather than pass unverified.
- **Replay across sessions** — the browser mints a random `nonce`
  (`src/lib/googleIdentity.ts`) *before* asking Google for the token and sends it
  with the assertion, so the server can tell a token minted for this attempt from
  one carried in from somewhere else; a refusal rotates it. `credential` and
  `nonce` are both required on the request, and the nonce comparison is the
  token's own `nonce` claim, not a client-supplied identity.
- **No secret, no stored assertion** — there is no OAuth client secret in this
  project: a browser-issued ID token is verified against public keys, and the
  client ID is a public identifier Google documents as such, held in
  `system_settings` so an operator can move the deployment to their own Google
  Cloud project without a deploy. What is persisted in `external_identities` is
  `provider`, `provider_user_id` (the `sub`), the email and the verification
  timestamps — never the JWT itself.
- **An account opened this way has `password = NULL`**, which is the honest
  statement that it does not authenticate here; `login()` reads NULL and refuses
  with the same words a wrong password gets, rather than hashing null. Linking is
  one identity per address (`GOOGLE_ACCOUNT_ALREADY_LINKED`) because merging two
  providers' accounts would hand one person's businesses to another. Neither
  switch is client-side trust: `auth.google_enabled` off answers
  `GOOGLE_SIGNIN_DISABLED`, and enabled-without-a-client-ID answers
  `GOOGLE_NOT_CONFIGURED` — the button and the endpoint read the same two
  settings, so a handcrafted request gets the same door as a hidden UI.
- **Audit payloads** — `AuditLogMiddleware.php:23` records
  `$request->except(['password', 'password_confirmation', 'token', 'secret'])`,
  so credentials are kept out of `audit_logs`.

---

## 3. Outbound Requests (Webhooks) — SSRF Surface

A webhook is the platform making a request on somebody else's instructions, so
it is defended twice:

1. **At registration** — `StoreWebhookRequest` runs the URL through
   `App\Support\OutboundEndpointPolicy`: HTTPS only, fully-qualified host, and
   no loopback / private / link-local answers. `https://169.254.169.254/…` and
   `http://…` are rejected with a validation error and nothing is stored
   (`tests/Feature/WebhookDeliveryTest.php`).
2. **Before every attempt** — `WebhookDispatcherService::attemptDelivery()`
   re-checks `OutboundEndpointPolicy::accepts()` and records the delivery as
   `BLOCKED` without sending. A row created before the policy existed, or a
   hostname whose DNS answer has changed, therefore cannot be posted to.

`Http::assertNothingSent()` proves the blocked path in
`test_a_stored_internal_endpoint_is_never_posted_to_even_when_pinged`.

**Signing.** The header is `X-Hassal-Signature: t={timestamp},v1={hex}` where

```php
hash_hmac('sha256', "t={$timestamp}.{$jsonPayload}", $webhook->secret)
```

(`WebhookDispatcherService.php:47`). The timestamp is inside the signed
material, so a captured body cannot be replayed later: `verify()` rejects
anything outside a 300-second window, and rejects a header with no `t=`. The
comparison uses `hash_equals`. Redirects are disabled
(`'allow_redirects' => false`, `WebhookDispatcherService.php:175`) because a
302 turns "we validated this URL" into "we posted a signed payload somewhere
else"; the test captures the transfer options to prove the option, not just the
response.

**Retries.** `app:process-webhooks` re-sends failed deliveries **on the same
row** up to `HASSAL_WEBHOOK_MAX_ATTEMPTS` (default 5). Retries re-sign with a
fresh timestamp but keep the original `event_id`, so a receiver that
de-duplicates on the id still sees one event. There is no backoff schedule and
no queue: the retry interval is the scheduler interval.

---

## 4. Idempotency and Replay

`App\Http\Middleware\IdempotencyMiddleware` (alias `idempotency`) is attached to
selected mutating routes, not to the API as a whole.

- The `X-Idempotency-Key` header is **optional**. A request without it runs
  normally; nothing is deduplicated.
- On a repeat with a stored response, the cached body and status are replayed
  with `X-Cache-Lookup: HIT`.
- The same key reused with a different request body is refused with 422
  `IDEMPOTENCY_CONFLICT`.
- **What it is not:** a lock. There is no `STARTED`/in-flight state, so two
  concurrent requests carrying the same key can both execute before either
  writes the cache. Duplicate protection is therefore for retries after a
  timeout, not for simultaneous double submits. Concurrency on stock and money
  is protected by row locks instead (section 5).

---

## 5. Money, Stock and Concurrency

- **Row locks**, all inside a transaction:
  `InventoryReservationService.php:47,151,157` (offer and reservation before a
  quantity change), `DealStateMachineService.php:143,231,251` (deal, then its
  offer, on a state transition), `DoubleEntryLedgerService.php:115` (ledger
  account), `InventoryMovementController.php:112` (stock adjustment). A
  reservation checks the locked quantity in the same transaction it writes in,
  which is what stops two accepted requests from spending the same units.
- **Decimal types are per column, not one size.** Money `DECIMAL(18,4)`;
  quantities `DECIMAL(14,2)`; exchange rates `DECIMAL(18,6)`; the tax and
  commission snapshots on a deal `DECIMAL(6,4)`. No float or double is used for
  a stored amount.
- **Ledger immutability is application-shaped, not database-enforced.** Ledger
  lines are written by `DoubleEntryLedgerService` and there is no route,
  controller method or UI that updates or deletes a posted line — that is why
  corrections go in as compensating entries. There is **no** trigger, and a
  person with direct database access can change history. If immutability has to
  survive that, it needs database triggers or an append-only store.
- **No custody is assumed, and no payment code exists to assume it.** The
  `Payment` model, the `payments`/`escrows` tables and the whole
  `app/Services/Payments` adapter layer were deleted on 2026-10-01 (§20 of the
  platform master prompt: Transaction ≠ Payment). Nothing in this repository
  moves money: a deal's amount is a recorded snapshot, the ledger posts only the
  platform fee accrual at `COMPLETED`, behind the default-off
  `double_entry_ledger` flag.

---

## 6. Authorization Surface

Enforcement is in the route layer, never in the client: `permission:*`,
`role:*` and `role_or_permission:*` (Spatie, aliased in `bootstrap/app.php:40`),
plus `feature:*` (`EnsureFeatureEnabled`) which answers 503 `FEATURE_DISABLED`
before a controller runs. A missing capability returns
`PERMISSION_DENIED` with `error.details.required_permission`
(`bootstrap/app.php:72`) so a client can act on it. The React dashboard hides
buttons, but no screen is the boundary: an action that has no route guard is a
defect, and `tests/Feature/PlatformAdminLayerTest.php` exercises the admin
routes directly.

**A second axis: `verified_tenant`.** `EnsureVerifiedOrganization` is not a
permission — no amount of granting fixes it, because it decides whether the
*organization* may perform a commercial act at all. It sits on offer creation
and submission, purchase requests (both entry points), deals, demands and
quotations, disputes, and on publishing community interest and comments. It
deliberately does **not** sit on document filing, membership management,
warehouses, browsing or the profile routes: an applicant that cannot upload its
registration certificate because it is not verified has no route by which to
become verified. It answers 403 `ORGANIZATION_NOT_VERIFIED` with a `checklist`
so the client can show the missing step rather than a dead end, is switched by
the `marketplace.require_verified_to_trade` setting rather than a constant, and
exempts `SuperAdmin` — platform staff act on other businesses' listings as
their job.

**Uploaded artifacts.** Two kinds, treated differently because they carry
different risk:

- **Product images** (`ProductImageController`) go to the `public` disk. They
  are the listing's shopfront, so readable-by-URL is their purpose. Type is
  detected from the file's bytes (`getMimeType()`), never from the client's
  filename; the stored path is generated server-side from a UUID, so no
  client-supplied name reaches the disk; ceilings come from
  `config('hassal.uploads.images')`.
- **Organization documents** (`OrganizationDocumentController`) go to the
  `local` disk, rooted at `storage/app/private`, which no web server maps.
  `OrganizationDocument::url()` no longer returns a storage path — it mints
  `URL::temporarySignedRoute('organization-documents.file', now()+15min, …)`,
  and the file route streams the bytes with the mime the server detected at
  upload. The reason is concrete: a permanent URL for a commercial
  registration scan survives every channel it can leak through — a forwarded
  chat message, browser history, a proxy log, a sync client — and stays valid
  forever. An expiring signature bounds that window, and the link can only have
  been minted by a request that already passed `maySee()`, which admits the
  owning tenant, holders of `organizations.verify`, and `SuperAdmin`. A
  reviewer who may decide a paper can therefore read it; `tests/Feature/
  OrganizationVerificationTest.php` pins both halves.

**Not done:** no malware or antivirus scan runs on either kind of upload — the
mime check is a type test, not a safety test, and a stored PDF is still served
`inline`; no CAPTCHA or per-account upload quota throttles the volume a single
business can file.

---

## 7. Rate Limiting and Transport — what is and is not here

**Implemented:** `throttle:10,1` on each of the three doors that open an account
— `POST /auth/register`, `POST /auth/login` and `POST /auth/google` — and
`throttle:6,1` on `/auth/forgot-password` and `/auth/reset-password`
(`routes/api.php:113-121`). Those five are the credential-guessing surface, and
each is limited separately because Laravel keys a limiter per route: burning out
the Google door does not lock anyone out of the password one.

**Not implemented:** there is **no global API throttle**. Authenticated
read endpoints — marketplace search, offers, deals, reports — have no per-user
or per-token limit, so a leaked bearer token can enumerate at will until the
token expires (`HASSAL_TOKEN_TTL_MINUTES`, default 720). Add a
`throttle:api`-style limiter before exposing the API to third parties.

**Headers:** no middleware in this repository sets CSP, HSTS,
`X-Frame-Options` or `X-Content-Type-Options`. As an API-only backend that is
mostly the reverse proxy's job, but it must be configured there; it is not done
here. `SESSION_SECURE_COOKIE=true` is set in the local `.env`.

**Secrets and Git:** `.env` is ignored by both the root and `backend/.gitignore`
(only `.env.example` is tracked), and no credential is hard-coded in the
application. The frontend address that `routes/web.php` redirects to is now
`config('hassal.frontend.url')` → `HASSAL_FRONTEND_URL` (falling back to
`APP_URL`), replacing a hard-coded `http://localhost:3000`; the emailed reset
link is built from `APP_URL` and redirects onward to that address.

---

## 8. Known Gaps (open, not aspirational)

1. No global rate limiting on authenticated routes (section 7).
2. Tenant isolation is per-controller code, not a framework guarantee. The
   rival-business suite in section 1 covers the marketplace doors, and this
   round added attacks on community rows (`OfferCommunityTest`), another
   product's media (`ProductImageTest`), a depot the caller does not own
   (`ProfileAndWarehouseEditTest`) and a rival's paperwork
   (`OrganizationVerificationTest`). Still untested as attacks: demands and
   quotations, dispute resolution, inventory movements, notification marking
   and the ledger/report surfaces.
3. Ledger immutability has no database enforcement (section 5).
4. Webhook delivery is synchronous inside the request that triggers it: a slow
   partner endpoint adds latency to a user-visible call, and there is no queue
   worker to isolate it.
5. `system_status` now measures whether the database connection opens, but that
   is its entire scope: cache, storage, mail and the outbound-webhook listener
   are not probed, so `OPERATIONAL` is not a whole-stack health signal.
6. The signed document link (section 6) carries its signature in the query
   string, so any access log, referrer or browser-sync path that sees the
   request holds a working link for its fifteen minutes. Far smaller than a
   permanent public path, but not zero. A first-party download view behind the
   session, or a shorter window, remains open.
7. No upload is scanned for malware, and no per-account rate limit bounds how
   much one business can file — the ceilings in `config('hassal.uploads')`
   bound size and count only.
