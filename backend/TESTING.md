# HASSAL Automated Testing Suite (دليل الاختبارات وجودة الكود)

Last verified: 2026-09-19, against this working tree. The numbers below were produced by
`vendor/bin/phpunit` on this machine (PHP 8.3.33, PHPUnit 11.5.56, MySQL 8.4.11), not copied from an
earlier report.

---

## 1. What the suite is

```
OK (213 tests, 1354 assertions)
Time: 01:38.028
```

(The timing is this machine's wall clock and moves run to run — 01:38 to 01:46 were both observed on
2026-09-19. Only the test and assertion counts are meant to be stable.)

Every test file below uses `RefreshDatabase` and builds its own world through
`tests/Support/HassalFixtures.php`. That combination is the two guarantees this suite is designed around:
each run starts from a migrated, empty schema, and no test depends on a row somebody else seeded.

The fixtures trait replaces an older pattern where tests looked up demo users by email
(`seller@alwataniya.sa`) in a seeded database. That described a Saudi pilot that is not this product,
broke whenever the demo data changed, and could not express a scenario the seed did not contain.
Fixture passwords are generated per call, so no credential is written into the repository — including
into `HassalFixtures.php` itself.

| Test file | Domain | What it proves |
| :--- | :--- | :--- |
| `Feature/AuthTest.php` | Registration & session | Register with an organization, Sanctum login, wrong-password rejection, `/auth/me`, organization switching, token revocation. |
| `Feature/OfferLifecycleTest.php` | Seller listings | Draft → submit → review → active, the status transitions the seller may not make, edit only in `DRAFT`/`PAUSED`, quantity lock once stock is committed. |
| `Feature/PurchaseRequestFlowTest.php` | The buyer's ask | An ask records price and quantity but moves nothing on the shelf, min-order and self-purchase refusals, counter-offer and adoption, cancel rules. |
| `Feature/InventoryReservationTest.php` | The hold itself | `POST /purchase-requests/{id}/accept` is the only creator of a reservation; the re-check inside `lockForUpdate`; overselling rejection; the MySQL row-lock race; expiry sweep and grace window; an expired hold cannot become a deal; replayed ask honours idempotency. |
| `Feature/DealFlowTest.php` | Deal conversion | A deal forms against a held reservation, its pricing/tax snapshots, the state machine's legal and illegal jumps, per-state permissions. |
| `Feature/DemandMatchingTest.php` | RFQ path | Demand creation, matching, quotation rules, self-quotation refusal, converting a match into a purchase request. |
| `Feature/CatalogScopeTest.php` | Shared catalog | Which catalog rows are platform-wide and which belong to an organization, and who may write them. |
| `Feature/OrganizationTest.php` | Identity & tenant | Organization creation with admin-configured legal fields, membership management, last-owner protection, context header switching. |
| `Feature/PlatformAdminLayerTest.php` | Admin layer | Moderation queue, verification decisions, user roles, settings and flag writes, the inventory-movement adjust rules. |
| `Feature/SettingsManagementTest.php` | Settings | The settings catalog, types, editability, and that a rate nothing reads cannot be edited. |
| `Feature/ReportsAndExportTest.php` | Reporting | Sales summary and CSV export scoped to the caller's organization. |
| `Feature/WebhookDeliveryTest.php` | Integrations | The signing secret is returned once at registration; a delivery is signed over the exact bytes sent and verifies; a failed receiver and a non-followed redirect are recorded honestly; internal endpoints are never posted to; tenant isolation of endpoints and delivery logs; the capability flag and the permission each close their surface. |
| `Feature/RbacMatrixTest.php` | Enforcement of the RBAC catalog | Every permission `config/rbac.php` declares is named by real middleware or a real `can()` check; no route checks a capability the catalog never declared; the seeded matrix matches the catalog; business roles hold no platform-only capability. |
| `Feature/PricingAndCurrencyTest.php` | Pricing & currency | The `exchange_rate_to_base` direction (100 USD at `0.004000` is 25,000 base units, not 0.4), conversion refusals for an unavailable/inactive/zero-rate currency and a missing base, the default zero tax and fee, and derived totals under `tax.enabled` with a discount and delivery fee, including a discount clamped to the line and a negative fee floored to zero. |
| `Feature/TenantIsolationAndIdorTest.php` | Cross-tenant attacks | A rival business holding the same permissions cannot read or write another's offer, deal, reservation, purchase request, warehouse or audit trail, cannot see a foreign tenant's rows through a shared user account, and cannot act on a forged `X-Organization-Id`. |
| `Feature/GoldenUserJourneyTest.php` | End to end | The whole path in one pass — seller listing → demand → match → purchase request (the shelf untouched) → seller acceptance (40 units held) → deal in `PENDING_SELLER_APPROVAL` — plus a test that the platform reports no payment it never observed. |
| `Feature/OfferCommunityTest.php` | Interest, questions, reports | One attributed like row per actor per organization and a second press adding nothing; the same person acting for two businesses producing two signals; withdrawing interest reaching only the actor's own row; interest refused for a company the caller is not a member of; a listing nobody can buy not likable; the liked list scoped per organization; seller vs buyer labelling of a thread; a reply that cannot join another listing's thread; pre-moderation holding a comment out of the public thread; withdrawal hiding a row without deleting it; a stranger unable to withdraw another business's comment; a report that asks for review and decides nothing; the moderation queues closed to business roles; and a card publishing only counts it can prove. |
| `Feature/OrganizationVerificationTest.php` | The verification gate | An unreviewed business browses but cannot publish; the refusal names the missing step instead of saying no; the gate leaves its own exit open (filing papers, members, warehouse); verification is an operator switch not a constant; a reviewed business trades and the gate does not reopen for work it already did; staff are not brought before the gate; a rival cannot read another's paperwork; filing → accepting records a named reviewer; a rejection carries an actionable reason; a business cannot accept its own documents; a reviewer that may decide a paper may read it; and a filed copy living on the private disk, readable only through its expiring signed link. |
| `Feature/ProductImageTest.php` | Product media | Upload and first-image-becomes-cover; a file accepted for its bytes and stored under a server-generated name; a rival unable to attach or read another product's media; the cover moving and deletion promoting another photograph; the count ceiling enforced as a deployment limit; and a listing showing what it holds. |
| `Feature/ProfileAndWarehouseEditTest.php` | Identity & depot correction | A person correcting their own details; the login address not being a profile field; a phone that already verifies someone else refused; a password change closing the sessions the old one opened and demanding the current password; the owner moving a depot's pin; an out-of-world coordinate refused rather than stored; and a business unable to edit a depot it does not own. |
| `Unit/OutboundEndpointPolicyTest.php` | SSRF policy | Webhook receiver rules: public HTTPS accepted, private/inside-network targets, plain HTTP outside development, bare and internal hostnames, integer host forms, embedded credentials and non-http(s) schemes refused, and a development deployment opting in. |

`tests/Feature/ExampleTest.php` and `tests/Unit/ExampleTest.php` were removed: they asserted a string in
the default Laravel skeleton and added two passing tests to a number that meant nothing.

## 2. What the suite does not cover

Stated so nobody reads 1354 assertions as a coverage claim.

- `RbacMatrixTest` proves each catalog permission is enforced *somewhere*; it does not walk every
  role × permission × route pair, so a route that grants more than its role should is not caught.
- Cross-tenant attacks now cover the marketplace doors plus, from this round, community rows
  (`OfferCommunityTest`), another product's media (`ProductImageTest`), an unowned depot
  (`ProfileAndWarehouseEditTest`) and a rival's paperwork (`OrganizationVerificationTest`). Demands
  and quotations, dispute resolution, inventory movements, notification marking and the
  ledger/report surfaces are still untested as cross-tenant attacks.
- No `FeatureFlagEnforcementTest`: each flag is closed where the suite happens to touch it, not swept
  across the routes it gates.
- The double-entry ledger's balancing rules are checked where a deal writes entries, not as an
  independent ledger test.
- No dispute `status` filter test.
- No browser/E2E layer, no performance or load harness. Concurrency is proven at the database level
  (two processes, one row lock) rather than through HTTP.

## 3. Running the tests

```bash
cd backend
C:/php83/php.exe vendor/bin/phpunit --colors=always      # whole suite, ~1.5 min
C:/php83/php.exe vendor/bin/phpunit tests/Feature/InventoryReservationTest.php
```

`php artisan test` works too; `php artisan tinker` is not available in this environment.

Prerequisites and the safety rails:

- `phpunit.xml` sets `APP_ENV=testing`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync` and
  `DB_CONNECTION=mysql` with `DB_DATABASE=hassal_test`, so a dedicated `hassal_test` schema must exist
  and the `.env` the run resolves must not override it to a live database.
- `RefreshDatabase` issues a real `migrate:fresh`. `tests/TestCase.php::guardAgainstNonTestDatabase()`
  therefore aborts the run unless the resolved database name ends in `_test` or `_testing`, refuses a
  null database, and always refuses `hassal_production`, `production` and `laravel` — so a misconfigured
  run fails loudly instead of truncating live commercial data.
- Fixtures seed the RBAC matrix, the settings catalog, the feature-flag catalog and the base currency
  inside each test (`seedPlatformDefaults()`), mirroring what `app:install-system` produces. A test that
  forgets to enable a flag gets the same `FEATURE_DISABLED` refusal a real client would.

## 4. Conventions for new tests

- `Sanctum::actingAs($user)` before every request. `accountWithOrganization()` returns a user and an
  organization but does **not** log anyone in; without the call the suite fills with 401s that look like
  permission bugs.
- Send the tenant header with `$this->withHeaders($this->withTenant($org))`: `withTenant()` returns a
  headers array (`['X-Organization-Id' => …]`), so chaining `->postJson()` onto it directly fails with
  `Call to a member function putJson() on array`. Add an `X-Idempotency-Key` on writes that the route
  gates on it.
- Assert `$res->status() !== 201` / `assertCreated()`. `TestResponse` has no `successful()` in this
  version, and calling it raises `BadMethodCallException` inside a `hold()` helper rather than a clean
  failure.
- Read the envelope as the code writes it: `{success, message, data, meta}` for success,
  `{success:false, error_code, error:{code,message,details}, meta}` for errors, and `pagination` at the
  top level for lists. `fieldError($result, 'field')` pulls `error.details.field[0]`.
- A suspended organization is refused by the `tenant` middleware with `403 ORGANIZATION_SUSPENDED`
  before any controller runs. Expect that code, not a controller-level `422`.
- Fake the disk the code writes to, not the obvious one: filed organization documents land on the
  private `local` disk (`storage/app/private`), while product images stay on `public`. Asserting
  `Storage::disk('public')->assertExists(…)` against a document path fails for the wrong reason.
- A `Role::create()` in a test must carry `'guard_name' => 'web'`. `RolesAndPermissionsSeeder` writes
  every permission under `web`, while the application's default guard is `sanctum` — a role left to
  the default finds no permissions and `givePermissionTo('organizations.verify')` throws
  `PermissionDoesNotExist` for a permission that plainly exists.
