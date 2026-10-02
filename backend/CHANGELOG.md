# HASSAL Engineering Changelog (سجل التغييرات والتحديثات)

All notable changes to the HASSAL B2B Marketplace codebase are documented in this file.

---

## [Unreleased] - the consumer stands in their own name (2026-10-01)

§1/§14 of the full rebuild command makes the Consumer a first-class context: browse, search, nearby,
view, and *interact* — with no unnecessary organization requirement. The backend refused exactly that:
liking, commenting and reporting all demanded a tenant (`TENANT_REQUIRED`), the engagement tables
stored `organization_id NOT NULL`, and `User::hasPermissionTo()` read business capabilities only from
a membership row. Trading (purchase requests, reservations, deals) keeps that requirement on purpose —
a counterparty signs with a company; a question signs with a person.

### Added
- **Person capabilities**: `rbac.person_capabilities` (`offers.like`, `comments.create`,
  `notifications.view` — the inbox read in `NotificationController` is already strictly
  `where('user_id', …)`, the rows address the account not a company) +
  `RbacScope::personCapabilities()/isPersonCapability()` + a third branch in `User::hasPermissionTo()`
  and `getAllPermissions()`, bounded three ways: no organization in context, account status `active`,
  and only `business`/`both`-scoped names. `RbacMatrixTest` guards the list.
- **Migration `2026_10_01_210000`**: `organization_id` nullable on `offer_likes`, `offer_comments`,
  `offer_comment_reports`; uniqueness rebuilt per person (`offer_likes_unique_per_person`,
  `comment_reports_unique_per_person`) with a keep-the-latest dedupe, and index order chosen so the
  foreign keys never lose their backing index (MySQL 1553). Applied to `hassal_production` and proven
  on a disposable probe database.
- **Sandbox consumer engagement**: seeded consumers now like two listings and ask a published question
  the selling company answers (`SandboxSeeder::buildConsumers()`), verified on the probe: null-org
  like rows, null-org question, org-attributed `is_seller_reply` answer.

### Changed
- `OfferLikeController`/`OfferCommentController`: `TENANT_REQUIRED` guards removed; the like is keyed on
  `(offer, user)` with the context organization recorded at creation only; a consumer comment has
  `is_seller_reply === false` by construction; withdrawal is by authorship (person, org officer, or
  platform); reports key on `(comment, user)`.
- `OfferCommunityTest`: the three tests that encoded company-only semantics rewritten (one signal per
  person across two companies; unaffiliated like succeeds with `organization_id NULL`; liked list is
  per person), plus consumer tests for like/comment/report/withdraw, trading-still-refused, and a
  suspended account holding no person capability. 17/17 green.

### Verified
- Migration + community + RBAC + seller-visibility suites green; live schema checked from
  `information_schema`; probe DB rebuilt end to end and dropped.

---


§20 of the platform master prompt draws one line: Transaction ≠ Payment, and no Wallet/Payment is in scope.
The repository had grown on the wrong side of that line — a `Payment` model, an escrow record, four deal
states that named money movements (`PENDING_PAYMENT`, `ESCROW_FUNDED`, `SETTLED`, `REFUNDED`), a
`ReconciliationController` route the contract had never shipped, and an `escrow` flag gating transitions
nobody could reach. None of it was ever wired to a gateway, and the live database never held a `payments`
or `escrows` table. This entry records the removal, what replaced it, and the documentation pass that
followed.

### Removed
- **Payment layer**: `App\Models\Payment`, `App\Models\Escrow` and the whole `app/Services/Payments`
  adapter directory (`PaymentProviderInterface`, `PaymentGatewayManager`, `BankTransferPaymentProvider`,
  `CashOnDeliveryPaymentProvider`). No migration is added to drop `payments`/`escrows` because no migration
  ever created them — the tables existed only in the docs.
- **Four payment states** from `DealStateMachineService` (states, `ALLOWED_TRANSITIONS`,
  `TRANSITION_RULES` and the `deals.status` CHECK): the machine is now twelve states, and settlement is
  `COMPLETED` — a handover-gated status, not a money event.
- **`ReconciliationController`** and `GET /api/v1/reconciliation/audit`; the ledger surface is the three
  `/ledger/*` reads only.
- **The `escrow` flag** from `FlagCatalog`. There is no payment or escrow capability in the flag list,
  on purpose (`FlagCatalog.php:19`).
- **`LedgerEntry` import** in `ReportExportController` (the class does not exist).

### Added
- **Consumer context in the sandbox** (`SandboxSeeder::buildConsumers()`): two active, verified
  accounts with no `organization_memberships` row (`salma.consumer@hassal.test`,
  `tariq.consumer@hassal.test`) and two support tickets — one filed with `organization_id` NULL by a
  consumer, one answered by the platform operator — so the third context of §6 (Consumer ≠ Seller ≠
  Business Buyer) is now demonstrable end to end against a seeded database, and the clients'
  organization-less paths have rows to exercise them.
- **Deal handover proof** (`deal_handovers`, `DealHandoverController`, `DealHandoverService`,
  `App\Support\QrCode`): a code issued at `ACCEPTED`, shown to both parties (`GET /deals/{id}/handover`),
  printable as an SVG label (`…/handover/label`), and spent once by the party holding `deals.deliver`
  (`POST …/handover/verify`, idempotent). `INSPECTION` and `COMPLETED` refuse with
  `HANDOVER_NOT_VERIFIED` until it is spent; `CANCELLED` invalidates an unspent code. This is the delivery
  proof §4.7 asked for — it replaces the escrow step in the flow.
- **One ledger posting**: `DoubleEntryLedgerService::recordDealFee()` accrues the platform fee
  (`1010-FEE-RECEIVABLE` / `4010-COMMISSION-REVENUE`) at `COMPLETED`, behind the default-off
  `double_entry_ledger` flag. Escrow and settlement accounts were never seeded and are not planned.

### Documentation alignment
- Root `DATABASE.md` (rebuilt from `information_schema` on 2026-10-01: 58 tables, payment-era sections
  deleted, the nine missing tables generated in), `DOMAIN_MODEL.md` (§3.4 twelve states + handover gate,
  §3.5 five dispute states), `ARCHITECTURE.md` (§5 became the handover-proof architecture),
  `API_CONTRACT.md` (§4.7–4.9 rewritten to the real permission map and endpoints), `PROJECT_SCOPE.md` and
  `IMPLEMENTATION_PLAN.md` (Phases 4–5 corrected with dated notes).
- Backend companions: `API.md` (handover rows in, reconciliation row out), `API_CONTRACT.md` (webhook
  example uses real states), `CONFIGURATION.md` (flag table now matches `FlagCatalog`, live values
  re-measured 2026-10-01), `SECURITY.md` (ledger scoping rewritten against `LedgerController`, payment
  gap closed by removal), `docs/openapi.yaml` (description no longer names `ESCROW_FUNDED`).

### Verified
- Backend suite: **392 passed / 3162 assertions**. Live schema checked through `information_schema`:
  58 tables, no `payments`/`escrows`, `deals.status` CHECK holds exactly the twelve states, `disputes`
  CHECK holds the five rulings, ledger holds exactly the two fee accounts.
- `scripts/check-api-contract.mjs` against a fresh `route:list --json` dump: every path in the web
  client's `src/lib/api.ts` is a route on the server. Flutter `analyze`: no issues. Web `tsc --noEmit`:
  clean.

---

## [Unreleased] - the write sits beside the people it acts on - per-account assignment in both clients (2026-09-25)

No route moved. `PUT /api/v1/rbac/platform/users/permissions` already took `user_ids[]` with a `mode`,
and `PUT /api/v1/organizations/{id}/members/permissions/bulk` already took a list of overrides —
`routes/api.php` is untouched in this phase. What was missing was a place an operator could reach those
writes from, and the place that existed was wrong: the account-grants editor sat on a screen about the
role matrix, asking for numeric ids pasted into a text box.

### Added
- **Web**: `src/components/AccountPermissionsEditor.tsx`, opened from a row of «حسابات المنصة» in
  `AdminConsoleView.tsx`. For one account it reads `GET …/users/{id}/permissions` and shows `grants`
  beside `effective − grants`, so the operator sees what the person holds by their own name and what
  reaches them through a platform role — otherwise the first move is to tick a capability the account
  already has. The offered names are `snapshot.permissions` minus `account_forbidden`, so a name the door
  would refuse is never rendered as a box; `mode ∈ replace|add|remove` is a visible choice rather than an
  inference from which boxes happen to be ticked; and `reason` must be written before the button works.
- **Web**: the same decision for many accounts at once — a checkbox per row, a bar saying how many are
  selected out of how many, and one save over the selection. The report separates the two numbers on
  purpose: «طُبِّق القرار على N حسابًا (M تغيّر فعلاً)», because a call that pointed at six accounts and
  changed nobody is a correct outcome that a conflated message would read as a failure.
  `UsersPanel` skips this UI entirely without `roles.manage`, and shows an `ErrorNote` when the
  capability is held but `GET /rbac/platform` failed — the alternative is a column of buttons that are
  simply not there, which an operator cannot distinguish from a permission they do not have.
- **Mobile**: `lib/features/admin/account_permissions_sheet.dart` (account door — one account or a
  selection from `accounts_tab.dart`) and `lib/features/org/member_overrides_sheet.dart` (company door —
  one member or several from `members_screen.dart`), both drawn from the shared
  `lib/widgets/permission_picker.dart` (tri-state rows where the company door needs منح / لا شيء / سحب,
  with names a standing already carries marked as carried rather than unticked) and
  `lib/widgets/selection_bar.dart`. A refusal answers in the client's own words through
  `memberOverrideRefusal()` and `refusalMessage()`, keyed on the server's code, not on its sentence.

### Removed
- **Web**: the «صلاحيات حسابات مباشرة (بلا منشأة)» block inside `PlatformPermissionsPanel.tsx` — the
  free-text account-id box, its mode options, the inspect and save handlers and the read-back facts
  (≈160 of that file's 350 lines). `PlatformPermissionsPanel` is now the platform role matrix and nothing
  else, which is what its name says.
- **Mobile**: a private `_SelectionBar` written twice (`accounts_tab.dart` and `members_screen.dart`),
  and a second copy of the member-refusal `switch` that `member_overrides_sheet.dart` had already
  written — the screen now calls that one function for a failed write and the sheet calls it for a failed
  read.
- **Mobile**: the cross-feature import that let an organization screen reach into `features/admin/` for
  `FactLine`. `test/contract_surface_test.dart` — the guard that no org-facing source calls `/admin/` —
  caught it; the line moved to `lib/widgets/common.dart` and the import went away. The test was right, so
  the test did not change.

### Verified
- `flutter analyze --no-pub`: no issues. Flutter suite: **155 passed, 9 skipped** (the skips are the
  plugin-backed offline cases). Web `tsc --noEmit` clean, `vite build` OK. Backend
  `--filter="PermissionIsolationTest|MembershipPermissionsTest|RbacMatrixTest"`: **22 passed /
  208 assertions**.
- Not covered: the new sheets and modal were not driven against a running server from a device or a
  browser, so the claim is types + analyzer + unit/contract tests + the server's own RBAC suites. The
  refusal paths (`ACCOUNT_PERMISSION_REFUSED`, `USER_NOT_FOUND`, `ROLE_IMMUTABLE`,
  `OWNER_STANDING_PROTECTED`, `MEMBER_PERMISSION_CONTRADICTION`, `MEMBERSHIP_NOT_FOUND`) are mapped from
  the codes the backend tests prove, not from observed responses. Four unreferenced `src/lib/api.ts`
  getters (`getHealth`, `getOffer`, `getPurchaseRequest`, `getUser`) were found in the sweep and left in
  place: they are contract-surface wrappers, not duplicated permission editors, and removing them is a
  different decision from the one asked for here.

---

## [Unreleased] - the name decides, not the row - permission scopes and two doors (2026-09-25)

### Added
- **A scope on every capability.** `config/rbac.php` gained `permission_scopes`, a written partition of the
  45-name catalogue: `platform` (10), `business` (25), `both` (10). `App\Support\RbacScope` is the arbiter,
  and `RolesAndPermissionsSeeder::assertScopesCover()` fails the seed when a name is missing from all three
  lists or appears in two — a capability added to the catalogue can no longer become grantable inside a
  company by not being mentioned. `2026_09_25_180000_add_scope_to_permissions_table` mirrors the value into
  `permissions.scope` for queries and reports; the decision reads config, not the column, so the two cannot
  drift into contradicting each other at the only moment it matters.
- **Standings a company owns.** `2026_09_25_190000_create_organization_roles_tables` adds
  `organization_roles` (one owner, key unique per organization and compared with `utf8mb4_bin`),
  `organization_role_permissions` (what a standing is worth, by permission id), and
  `organization_membership_permissions` (one member, one permission, `effect ∈ grant|deny`). The five
  configured standings are seeded per company as `is_system` with the config values, so a company that never
  edits anything reads exactly as it did before; beside them an owner may name أمين مستودع who moves stock
  and never prices it.
- **The account door**: `GET /api/v1/rbac/platform`,
  `PUT /api/v1/rbac/platform/roles/{roleName}/permissions`,
  `GET/PUT /api/v1/rbac/platform/users/{id}*/permissions` (`RoleGovernanceController`), gated on
  `roles.manage` — a capability no organization route reads, and one business name can never be attached
  through it (`RbacScope::unacceptableOnAccount()` → `ACCOUNT_PERMISSION_REFUSED`).
- **The organization door**: `GET|POST /api/v1/organizations/{id}/roles`,
  `PUT|DELETE /{id}/roles/{key}`, `GET|PUT /{id}/members/{userId}/permissions` and
  `PUT /{id}/members/permissions/bulk` (`OrganizationRoleController`), gated on `members.manage`, refusing
  platform-scope names as `ROLE_PERMISSION_REFUSED` / `MEMBER_PERMISSION_REFUSED`, a grant and a denial of
  the same name as `MEMBER_PERMISSION_CONTRADICTION`, and any removal of the owner's
  `members.manage` / `organizations.manage` as `OWNER_STANDING_PROTECTED`. Every write takes a `reason` and
  is audited.
- **Web**: `PermissionPicker` (one list, two modes, built from the server's own read response so a refused
  name is never rendered), `PlatformPermissionsPanel` (platform matrix — it also carried the account
  grants until the phase below moved that write beside the accounts it acts on),
  `OrganizationRolesPanel` (custom standings and individual overrides, single member or bulk), a
  `permissions` tab in the admin console gated on `roles.manage`, and a per-row «تجاوزات فردية» entry in
  `MembersView`.
- **Mobile**: `MembershipRoleOption.isSystem`, parsed as tri-state (`null` when the server did not say), and
  the member sheet labels a company-invented standing as خاص بهذه المنشأة.

### Changed
- **A membership row's `role` is a key into its own company's roles.** The
  `organization_memberships_role_is_canonical` CHECK is dropped by the migration: MySQL cannot express a
  constraint that references another table, so the boundary moved to the write doors
  (`AddMemberRequest`, `OrganizationRoleController::update`), where it is checked against that
  organization's rows. What stays in the schema is the foreign keys and `RESTRICT` on a standing still
  holding members.
- **The effective set is computed once, in one place.**
  `OrganizationMembership::effectivePermissions()` = standing rows (config fallback while a standing is
  unedited) + grants − denials, filtered by `RbacScope::exercisableInsideOrganization()` and the catalogue,
  memoised per instance against a generation counter that `flushResolutions()` bumps. `User::hasPermissionTo()`
  remains the single funnel for `permission:` middleware, `can()`, and `/auth/me`, so a button, a route, and
  a service cannot disagree about what a name means.

### Fixed
- `OrganizationRoleController::validateOverrides()` declared `grants` and `denials` as `required`, which in
  Laravel rejects `[]` — the one value that carries meaning here. A screen that only takes a capability
  away, and a form that states "no individual additions", were both answered with `VALIDATION_FAILED`
  instead of the controller's own guard codes. Both keys are now `present|array`, and three
  `PermissionIsolationTest` cases went green with the code change rather than with adjusted assertions.

### Verified
- `tests/Feature/PermissionIsolationTest.php` 10 tests / 83 assertions, `MembershipPermissionsTest` 8 / 95,
  and the **full backend suite 361 passed / 2283 assertions / 0 failed** (up from 351 before this phase).
- Dev database migrated and converged: 40 system standings across 8 companies, 904 role permissions, 0
  member overrides. Web `tsc --noEmit` and `vite build` clean; `flutter analyze` clean.
- Docs carried the change: root `API_CONTRACT.md` (§3 error codes, §4.2 scope-is-the-decision, §4.3 both
  doors, §4.12 platform matrix), `DATABASE.md` (three table layouts, the dropped CHECK, `permissions.scope`
  with the 10/25/10 tally, and the `role` default corrected to `staff`), `DOMAIN_MODEL.md` §4 rewritten as
  the two-door model.

---

## [Unreleased] - third normal form, and what it took back (2026-09-25)

### Removed
- **Three stored duplicates** (contract §4.5/§4.7, `DATABASE.md` §3, spec:
  `tests/Feature/DatabaseNormalizationTest.php`): `deals.transaction_currency` was a second copy of
  `deals.currency_code` written by the same service call, `deals.exchange_rate` a second copy of the
  `deals.exchange_rate_snapshot` that row exists to freeze, and `offers.category_id` a *derived* value
  stored — `product_variant_id → product_variants.product_id → products.category_id` decides it, and
  listings existed whose category had already been left by their product. The columns are gone from the
  create migrations at source, not only dropped afterwards, so a fresh database cannot be born with them.
- **Eleven loose currency strings became references**: every `*.currency_code` is now a foreign key to
  `currencies.iso_code`, so a listing cannot be published in a currency the platform has never declared.

### Changed
- **`offers.category_id` answers, filters, and is never written.** `OfferResource` and the model's
  `$appends` still emit the key — clients that read it read it today — and `?category_id=` still
  narrows the market, now through `whereHas('variant.product')`. `POST`/`PUT` refuse the field and take
  the category from the named product; a disagreement between the two is `CATEGORY_PRODUCT_MISMATCH`.
- **Deal creation writes one currency and one frozen rate.** The controller had been reading array keys
  `PricingAndCurrencyService` stopped returning and columns that no longer exist, which is a guaranteed
  failure on the happy path rather than an edge case.

### Fixed — a rollback and its repair
- On 2026-09-25 a `git checkout` of the pre-correction baseline (`b600c73`) was issued over fourteen
  backend and web files, silently replacing ~3,400 lines of completed corrections with 2026-09-18 code.
  Nothing announced it: the files were simply older. The visible damage was a truncated
  `config/rbac.php` (every migrating test aborted on `rbac.membership_roles is empty`), `Offer` reading
  the dropped `category_id` column (500 on every offer read), `DealController` on the removed keys, and
  404s for the device, preference, support and featured surfaces.
- Recovery was done against `HEAD` file by file, with the untracked tests and contract sections §4.5,
  §4.17, §4.18, §4.19 as the specification: routes restored (`/auth/devices`, `/auth/preferences`,
  `/support/*`, `/marketplace/offers/featured`, `/marketplace/offers/{id}/feature`), `OfferController`
  back to its contract shape plus `featured()`/`feature()`, `DeviceRegistry::record()` wired back into
  `sessionPayload()`, `RegisterRequest` carrying `CarriesDevice` again, and the two new capabilities
  catalogued in `config/rbac.php` — `offers.feature` and `support.manage`, held by no business role.
- **`issueToken()` was declaring the wrong type.** Sanctum 4's `createToken()` answers with
  `NewAccessToken` (the persisted row *plus* the printable half), not `PersonalAccessToken`; the declared
  return type made every sign-in a 500, and the device registry needs the row's id because the printable
  token is stored nowhere and cannot be revoked.
- **`/auth/me` carries `preferences` again**, compiled through `PreferenceCatalog` like the preferences
  endpoint itself. A header that obeys `hide_organization_name` cannot wait for a second request the
  client was never told to make.
- **An expired listing is refused where the ask is filed.** Nothing rewrites `offers.status` when a clock
  reaches `expires_at`, so a row can read `ACTIVE` while being over; the public list, the nearby search
  and the promoted strip all bound the date, and `POST /purchase-requests` now refuses the stale tab or
  cached card with `OFFER_NOT_TRADABLE` and `error.details.expired` rather than after three screens of
  promise.
- Verified: `php artisan test` — **351 passed, 2200 assertions**; `npm run lint` (`tsc --noEmit`) clean on
  the web client, whose promoted strip and support desk are wired back into `MarketplaceView`,
  `AdminConsoleView` and `ProfileView`.

### Known gaps
- The promoted strip, the geocoded warehouse capture and the offline biometric offer card have not been
  checked on a handset: no device was attached and the local gateway on `:3000` was not running.
- Offers 2 and 3 were pinned directly by SQL in `hassal_production` during an earlier device test.

---

## [Unreleased] - production hardening, account preferences and the phone client (2026-09-24)

### Added
- **Account preferences** (`app/Support/PreferenceCatalog.php`, `PreferenceController`, contract §4.18):
  ten keys a person decides for themselves — hide the organization name on screen, hide seller names in
  lists, compact lists, theme, language, the four notification switches, and offline browsing. Stored on
  the user row as JSON and served by `GET /auth/preferences` *with the catalog beside the values*, so a
  client builds its form from the server's list rather than from a hardcoded mirror of it. `PUT` takes a
  patch and writes the whole merged map, so a settings sheet with two visible rows cannot blank the other
  seven; an unknown key is dropped and named in `ignored` rather than stored, which is what keeps the
  column a preference map instead of a junk drawer. No permission on any of the three routes: scope is
  `$request->user()`, never an id in the path.
- **`total_quantity` is now an accepted write on `PUT /marketplace/offers/{id}`**, and the ledger check
  that guards it runs against the row as it will be stored, not as it was.

### Changed
- **Auth routes are throttled per route**: `throttle:10,1` on login, register and the Google exchange,
  `6,1` on the two password-reset doors, `4,1` on the biometric device registration. Laravel keys a
  limiter by route, so the door that opens an account is not metered by the same bucket as the door that
  reads a profile. `throttleApi()` is also enabled for the `api` group in `bootstrap/app.php`.
- **`SecurityHeaders` now sends HSTS and a Content-Security-Policy.** HSTS is emitted only over TLS —
  a preloaded header on a plain-HTTP dev gateway would teach a browser to fail on the next local run —
  and the CSP is written for what this API actually returns: JSON, no script, no frame, `default-src 'none'`
  plus `frame-ancestors 'none'`. `nosniff` and `Referrer-Policy: same-origin` stay, because a signed
  document URL carries its signature token in the path.
- **Uploads are typed by their bytes, not by their name** (`app/Support/UploadProbe.php`): a file is
  accepted as `image/jpeg` when the JPEG marker is where a JPEG marker should be. The client-declared
  mime and the extension are both inputs to the decision, neither is the decision. `UploadedFile::fake()`
  fixtures that wrote zero-byte files now write real header bytes (`OrganizationVerificationTest::pdf()`),
  because a probe that passes on an empty file is not testing anything.
- **Google's JWKS is cached and locked** (`GoogleIdTokenVerifier`): keys are read from the cache with a
  TTL from `hassal.auth.google.jwks_cache_seconds`, and the refetch runs under a `Cache::lock` so ten
  concurrent sign-ins do not become ten round trips to Google. Separate cache and lock keys per issuer, so
  two configurations cannot land in one store entry.
- **Lock ordering is one rule, applied in every service** (`app/Support/LockOrder.php`): offers before
  reservations before deals, everywhere a transaction takes more than one named lock. Two services that
  each picked their own order is a deadlock with a nice log line.
- **The audit log sanitises before it stores** (`app/Support/SensitivePayload.php`): password, token,
  secret, authorization-header and file-content keys are reduced to a presence marker. An audit trail
  that records the request that changed a password records the password.
- **`status`, `available_quantity`, `reserved_quantity` and `sold_quantity` left `Offer::$fillable`.**
  Those four are the ledger's own facts, and a mass-assignment path that can write them is a client that
  decides its own stock has been sold. Laravel drops non-fillable keys silently, so every legitimate
  writer moved to `forceFill()->save()` or `Offer::unguarded()` (`HassalFixtures::offer()`), and one test
  that expected a `422 OFFER_CLOSED` had been passing on a write that never happened — it now asserts on
  a row that really moved.

### Fixed
- **`OfferController::update()` wrote a listing's quantities in two statements.** The guarded `update()`
  filled `total_quantity`, then a second `forceFill()->save()` moved `available_quantity` to match. The
  database checks `reserved + sold + available <= total` on every UPDATE, so the intermediate row — new
  total, old available — was illegal, and a seller lowering a quantity got an HTTP 500 where the platform
  should have answered `422`. The declared total and the available figure are now assigned on the model
  and written once: one statement, one legal row.
- **`AccountPreferencesTest` calls `$this->app['auth']->forgetGuards()` between bearer tokens.** Within one
  PHPUnit method Laravel's request guard caches the resolved user across HTTP calls, so a second token
  read back as the first account and the test saw a leak that does not exist in production or under
  Octane, where every request gets fresh guards. The completeness assertion also compares **sorted** key
  sets now: MySQL's `json` column normalises object key order by key length then bytes, so stored order is
  the storage engine's and was never a platform claim to assert.

### Tests
- `tests/Feature/AccountPreferencesTest.php` — eight cases over the catalog, the merge, the patch-not-
  replace rule, unknown keys, the two switches a client cannot invent, and per-account scoping.
- Full suite green: 324 passed, 2026 assertions.

### Live check (dev deployment, 2026-09-24)
- `2026_09_24_190000_store_account_preferences_on_users` was **pending** on `hassal_production` while green
  in the suite: the tests build a fresh schema, the long-lived dev database does not. Applied with
  `php artisan migrate --force`. Symptom worth remembering — the phone's settings screen rendered every
  switch correctly (the catalog answers without touching the column) and then refused the first write,
  because `Unknown column 'preferences'` is a 500 and a 500 is one message to a client.
- `GET`/`PUT /auth/preferences` re-checked over the gateway on a real token: the catalog answers, a valid
  enum writes and reads back (`theme => dark`), an invalid enum and an unknown key come back under
  `ignored` without failing the request, and a patch leaves the untouched keys alone.

### Client
- The Flutter phone client (`D:\hassal_app_flutter`) is now a build target of this contract rather than a
  consumer of guesswork: every route it calls is listed here, the ones §4.16 says do not exist are
  asserted absent by a test in that project, and its offline cache replays only reads it actually made.

---

## [Unreleased] - community, media and verification round (2026-09-19)

### Added
- **Offer community, as attributed rows rather than client-side counters.** `OfferLikeController` and
  `OfferCommentController` record interest, question threads and reports with the `user_id` and
  `organization_id` that produced them, which is what makes removing them something the backend can
  check. A like is one row per actor per organization on an `ACTIVE` listing only; a reply to a reply
  flattens onto the question; `is_seller_reply` is decided by comparing the writer's organization to the
  seller's, never accepted from the client; withdrawal keeps the row at `status: removed` because a
  thread that silently rewrites itself is what a later dispute turns on. No star ratings and no seller
  score exist anywhere in this round — a single number would summarise a business nobody has read.
- **Platform moderation of that surface** (`CommunityModerationController`): a comment queue
  (`pending|published|removed|reported`), a report queue, and `GET /community/summary` whose six counters
  are read from the same tables the queues read. Upholding a report and removing the comment are two
  separate switches, so a reviewer's decision cannot be quietly half-applied.
- **Product media** (`ProductImageController`): upload, cover, ordering and deletion behind
  `catalog.manage`, with type detected from the file's bytes and the stored path generated server-side.
  Unusable files are reported per file while the rest are stored, rather than failing a whole batch.
- **Organization paperwork and the verification gate** (`OrganizationDocumentController`,
  `EnsureVerifiedOrganization`): a business files `commercial_registration|tax_certificate|trade_license|
  founding_statute|id_proof|other`, staff accept or reject each copy by name, and until the organization
  itself is `verified` the commercial acts refuse with `ORGANIZATION_NOT_VERIFIED` plus a checklist of
  what is missing. Filing, members, warehouses and browsing stay open — a gate that blocked the exit it
  demands would enforce onboarding by preventing onboarding. `accepted` means a staff member read this
  copy; no official register is consulted anywhere in this codebase.
- **Profile and depot correction**: `PATCH /auth/profile` (name, phone, national id — not the login
  address, not the company, not roles), `POST /auth/password` revoking the account's other tokens, and
  `PUT /warehouses/{id}` so a pin read from the browser can be corrected.
- Permissions added to `config/rbac.php` and the seeder: `offers.like`, `comments.create`,
  `community.moderate`. The first two are held by business roles; `community.moderate` is held by no
  business role, as reading another organization's reports is a platform act.
- Client screens for all of the above: the marketplace thread and interest controls, a favourites filter,
  `CommunityModerationPanel`, `StaffDocumentReview`, product images and product correction, browser
  geolocation offered (never assumed) when creating or correcting a warehouse, and a profile screen.
- Four feature files — `OfferCommunityTest`, `OrganizationVerificationTest`, `ProductImageTest`,
  `ProfileAndWarehouseEditTest`. Measured on 2026-09-19: **OK (213 tests, 1354 assertions)** over
  **120 `/api/v1` routes** (`route:list --json`).

### Security
- **Filed paperwork no longer sits on a public disk.** `OrganizationDocument` stores to the private
  `local` disk (`storage/app/private`), and `url()` mints an expiring signed route to the new
  `…/documents/{id}/file` action instead of returning a `/storage/...` path. A permanent URL for a
  registration scan survives every channel it can leak through and stays valid forever; a fifteen-minute
  signature bounds the window, and the link can only be minted by a request that already passed the same
  `maySee()` check. That check was also widened to match `review()`'s guard: a reviewer who may decide a
  paper had not been able to read it.

### Documentation
- `API.md`, `API_CONTRACT.md`, `SECURITY.md`, `TESTING.md`, `CONFIGURATION.md` and the root
  `API_CONTRACT.md` were brought back to the code: route and suite counts re-measured, the new endpoint
  groups and error codes written down, and the settings table given the two gate switches it was missing
  (`marketplace.require_verified_to_trade`, `community.moderate_before_publishing`).
- **Client↔route coverage re-measured, both directions.** Normalising `` `${id}` `` and `{param}` to the
  same placeholder, `src/lib/api.ts` issues 98 distinct paths and `routes/api.php` exposes 99 distinct
  paths across its 120 method-routes. Zero client calls point at a route that does not exist; the single
  route with no by-path call is `…/organizations/{id}/documents/{id}/file`, which is reached through the
  expiring signed URL the documents screen receives rather than a literal it could construct. The root
  `API_CONTRACT.md` count was corrected from 119 to 120 and `README.md`'s from 95 to 120. `READINESS_REPORT.md`
  at the repository root is a dated Arabic snapshot of what is provably working and what is not (2026-09-19),
  not a maintained document.

---

## [Unreleased] - corrections after 2.0.0-production (2026-09-19)

### Changed
- **Webhook signing secrets encrypted at rest**: `App\Models\Webhook` casts `secret` to `encrypted`, the
  plaintext is returned once at registration and never again, and
  `2026_09_19_090000_widen_webhooks_secret_for_encryption.php` widened the column for the ciphertext.
- **Frontend redirect is configuration**, not a string: `routes/web.php` reads `config('hassal.frontend.url')`
  ← `HASSAL_FRONTEND_URL`, replacing a hard-coded `http://localhost:3000`.
- **`system_status` is measured**: `BootstrapService` reports `OPERATIONAL`/`DEGRADED` from whether the
  database connection opens, instead of a constant.
- **CI runs again**: `.github/workflows/production.yml` triggered only on `main`/`production` while the only
  branch is `master`, so it had never executed. `master` was added to both triggers and a `web` job
  (`npm ci` → `tsc --noEmit` → `vite build`) gates the image build.

### Added
- `tests/Feature/RbacMatrixTest.php`, `tests/Feature/PricingAndCurrencyTest.php` and
  `tests/Feature/TenantIsolationAndIdorTest.php` (suite then 173 tests / 1092 assertions; 213 / 1354 after
  the round above).
- Client screens wired to real endpoints for the platform-admin layer (moderation, verification, users),
  settings/flags/currencies/audit/reports/webhooks, memberships, catalog, and the remaining marketplace,
  demand, dispute, ledger and seller actions. At that point the client's `/api/v1` calls and the route
  paths matched one-to-one at 79 each; the round above re-measured that pair.

### Documentation
- `README.md`, `INSTALLATION.md`, `CONFIGURATION.md`, `LOCAL_SETUP.md`, `MIGRATION_GUIDE.md`,
  `PRODUCTION_CHECKLIST.md`, `DEPLOYMENT.md`, `API_CONTRACT.md`, `SECURITY.md` and `TESTING.md` were
  corrected against the code: Saudi-pilot residue, a "Flutter client" that does not exist, an inert
  `QUEUE_CONNECTION=database`, `exchange_rate_to_base` read as a multiply instead of a divide, ledger
  immutability described as enforced, and a `queue:work --tries=3` instruction for jobs that do not exist.

---

## [2.0.0-production] - 2026-09-15

### Added (إضافات جوهرية)
- **Productization Audit Complete**: Comprehensive audit and hardening of all backend systems.
- **Payment Abstraction (written, not wired)**: Introduced `PaymentProviderInterface`, `PaymentGatewayManager`,
  `BankTransferPaymentProvider`, and `CashOnDeliveryPaymentProvider`. No controller, route or job references them,
  so no request path can move money through this layer yet.
- **Notification Abstraction**: Introduced `NotificationChannelInterface`, `NotificationManager`, `DatabaseNotificationChannel`, and `EmailNotificationChannel`.
- **Localization Engine**: Introduced `LocalizationMiddleware` supporting `Accept-Language` header resolution (`ar`, `en`) and language fallback.
- **Feature Test Suite**: 16 feature files plus `tests/Unit/OutboundEndpointPolicyTest.php`, all on
  `RefreshDatabase` against a `*_test` schema. Measured result on 2026-09-19: **OK (173 tests, 1092 assertions)**.
  Covers tenant isolation as rival-business attacks (`TenantIsolationAndIdorTest`), enforcement of the RBAC
  catalog (`RbacMatrixTest`), pricing and currency direction (`PricingAndCurrencyTest`), atomic reservation and
  its failure modes, deal state transitions, ledger balancing, webhook signing, expiry sweeps, auth and
  organization idempotency. The earlier `PricingAndCurrencyTest.php` was **rewritten**, not revived: the old
  file called three service methods that do not exist and asserted a SAR base with 15% VAT, so it now drives
  `calculateDealPricing()` and the conversion path that actually ships. There is no HTTP-level concurrency test.
- **Scheduled Background Tasks**: Configured cron schedules in `routes/console.php` for automatic reservation sweeps and webhook retries.
- **Currency Support**: `YER` is installed as the active default/base currency; `USD`, `AED` and `SAR` are seeded
  **inactive** with a neutral `1.000000` placeholder rate, because an unapproved rate is not a market price.
- **Complete Documentation Suite**: Delivered 13 formal documentation manuals (`README.md`, `INSTALLATION.md`, `CONFIGURATION.md`, `ARCHITECTURE.md`, `API.md`, `API_CONTRACT.md`, `OPENAPI.yaml`, `SECURITY.md`, `DEPLOYMENT.md`, `TESTING.md`, `TROUBLESHOOTING.md`, `PRODUCTION_CHECKLIST.md`, `CHANGELOG.md`).

### Fixed & Hardened (إصلاحات وأمان)
- **No Floating-Point Financials**: Monetary amounts use `DECIMAL(18,4)`, quantities `DECIMAL(14,2)`, exchange
  rates `DECIMAL(18,6)`, tax/commission snapshots `DECIMAL(6,4)`/`DECIMAL(10,4)` — per `information_schema`, not one blanket precision.
- **Pessimistic Concurrency**: `lockForUpdate()` inside `DB::transaction` on the offer row for every reservation and release
  (`InventoryReservationService`), and on the deal row for transitions. Verified in-process by tests; not by concurrent HTTP clients.
- **Append-only Ledger via API**: No route updates or deletes `journal_entries` or `ledger_lines`; corrections are modelled as
  reversal entries (`is_reversal`, `reversal_of_entry_id`) and `JournalEntry::isBalanced()` gates posting. There is no
  database trigger or row-level policy enforcing that — direct SQL can still mutate a posting.
- **Idempotency Protection**: `IdempotencyMiddleware` on mutating routes, keyed by the optional `X-Idempotency-Key` header;
  a replayed key returns the stored response, a reused key with a different payload is refused with `IDEMPOTENCY_CONFLICT`.
  Requests without the header are not deduplicated.
- **Separation of Concerns**: Enforced domain separation across `User`, `Organization`, `Product`, `ProductVariant`, `Offer`, `Deal`, `Payment`, and `Dispute`.
