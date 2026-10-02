<?php

use App\Platform\Controllers\ApiKeyController;
use App\Auth\Controllers\AuthController;
use App\Auth\Controllers\BootstrapController;
use App\Community\Controllers\CommunityModerationController;
use App\Platform\Controllers\CurrencyManagementController;
use App\Trading\Controllers\DealController;
use App\Trading\Controllers\DealHandoverController;
use App\Marketplace\Controllers\DemandController;
use App\Auth\Controllers\DeviceController;
use App\Trading\Controllers\DisputeController;
use App\Platform\Controllers\FeatureFlagController;
use App\Inventory\Controllers\InventoryMovementController;
use App\Trading\Controllers\LedgerController;
use App\Community\Controllers\OfferCommentController;
use App\Marketplace\Controllers\OfferController;
use App\Community\Controllers\OfferLikeController;
use App\Organizations\Controllers\OrganizationController;
use App\Organizations\Controllers\OrganizationDocumentController;
use App\Organizations\Controllers\OrganizationRoleController;
use App\Catalog\Controllers\ProductCatalogController;
use App\Catalog\Controllers\ProductImageController;
use App\Platform\Controllers\ReportExportController;
use App\Trading\Controllers\ReservationController;
use App\Identity\Controllers\RoleGovernanceController;
use App\Platform\Controllers\SetupWizardController;
use App\Platform\Controllers\SystemSettingController;
use App\Identity\Controllers\UserManagementController;
use App\Inventory\Controllers\WarehouseController;
use App\Platform\Controllers\WebhookController;
use App\Notifications\Controllers\NotificationController;
use App\Trading\Controllers\PurchaseRequestController;
use App\Auth\Controllers\PreferenceController;
use App\SupportDesk\Controllers\SupportController;
use App\Core\Http\ApiResponse;
use App\Platform\Controllers\AuditLogController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Health & System status
    //
    // Everything reported here is measured at request time. A health check
    // that prints a hard-coded version and a database it never looked at is
    // worse than no health check, because it passes when the system is down.
    Route::get('/health', function () {
        $database = ['status' => 'unknown', 'driver' => null, 'version' => null];

        try {
            $connection = DB::connection();
            $database = [
                'status' => 'connected',
                'driver' => $connection->getDriverName(),
                'version' => $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION) ?? null,
            ];
        } catch (\Throwable $e) {
            $database['status'] = 'unreachable';
            $database['error'] = $e->getMessage();
        }

        return ApiResponse::success([
            'status' => $database['status'] === 'connected' ? 'healthy' : 'degraded',
            'platform' => config('app.name'),
            'version' => config('hassal.brand.app_version'),
            'database' => $database,
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'environment' => app()->environment(),
            'timestamp' => now()->toIso8601String(),
        ], 'HASSAL health report');
    });

    // Initial Commercial Setup Wizard
    Route::prefix('setup-wizard')->group(function () {
        Route::get('/status', [SetupWizardController::class, 'status']);
        Route::post('/configure', [SetupWizardController::class, 'configure'])->middleware(['auth:sanctum', 'role:SuperAdmin', 'idempotency']);
    });

    // Bootstrap endpoint: usable signed-out for reference data, and
    // `tenant` is what lets it answer for the signed-in organization at all:
    // the middleware resolves a bearer token and refuses an
    // X-Organization-Id the caller is not a member of.
    Route::get('/bootstrap', [BootstrapController::class, 'index'])->middleware('tenant');

    // Public Marketplace exploration endpoints
    Route::prefix('marketplace')->group(function () {
        Route::get('/offers', [OfferController::class, 'index']);
        Route::get('/offers/nearby', [OfferController::class, 'nearby'])->middleware('feature:geo_discovery');
        // The platform's pinned listings, above the market rather than inside it.
        // Registered before `/offers/{id}` for the same reason `nearby` is: a
        // route declared after it would hand the word `featured` to a detail read
        // and answer a strip request with a 404 for an offer that does not exist.
        Route::get('/offers/featured', [OfferController::class, 'featured']);
        Route::get('/offers/{id}', [OfferController::class, 'show']);

        // The published thread under a listing is part of the listing. Reading
        // it asks nothing of the caller, so it sits here rather than behind a
        // token — writing to it is a separate, permission-gated route below.
        Route::get('/offers/{id}/comments', [OfferCommentController::class, 'index'])->middleware('feature:community');

        Route::get('/warehouses', [WarehouseController::class, 'index']);
        Route::get('/categories', [ProductCatalogController::class, 'categories']);
        Route::get('/brands', [ProductCatalogController::class, 'brands']);
    });

    // The filed paper itself, for the expiring link the documents list hands
    // out. Outside `auth:sanctum` because a browser following a link sends no
    // bearer token; the signature is what authorises it, and it was minted by a
    // request that had already passed the same `maySee()` check.
    Route::get('/organizations/{organizationId}/documents/{documentId}/file',
        [OrganizationDocumentController::class, 'file'])
        ->middleware('signed')
        ->name('organization-documents.file');

    // Public Auth endpoints
    // Rate limiting is mandatory here: without it these routes are credential
    // brute-force endpoints.
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware(['throttle:10,1', 'idempotency']);
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
        // The same ceiling as the password form, because it is the same thing: a
        // door that opens an account. It is throttled separately (Laravel keys a
        // limiter per route), so burning this one out does not lock people out of
        // the other, and neither is a way to test passwords at speed.
        Route::post('/google', [AuthController::class, 'google'])->middleware('throttle:10,1');
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:6,1');
        Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:6,1');
    });

    // Protected Routes (Sanctum + Tenant context + Idempotency + Audit)
    Route::middleware(['auth:sanctum', 'tenant', 'audit'])->group(function () {

        // Authenticated user & identity
        Route::prefix('auth')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/switch-organization', [AuthController::class, 'switchOrganization']);

            // Who you are, separate from which business you act for: this
            // touches only the user row, so it needs no tenant and no
            // permission beyond having a token.
            Route::patch('/profile', [AuthController::class, 'updateProfile'])->middleware('idempotency');

            // Revokes every other token on success, because the usual reason a
            // person changes their password is that someone else knew it.
            Route::post('/password', [AuthController::class, 'changePassword'])->middleware('idempotency');

            Route::post('/logout', [AuthController::class, 'logout']);

            // The account's devices, and this handset's own lock.
            //
            // No permission gates these, deliberately: a device row is found
            // through the token that reads it, so the scope is the account itself
            // and a route here that took an id from outside would be an
            // enumeration question waiting to be asked. `biometric_unlock` is a
            // label the account shows and revokes, never a way in — the
            // fingerprint opens the token stored on the phone, at the phone.
            Route::get('/devices', [DeviceController::class, 'index']);
            Route::post('/devices', [DeviceController::class, 'sync'])->middleware('idempotency');
            Route::patch('/devices/{id}', [DeviceController::class, 'update'])->middleware('idempotency');
            Route::delete('/devices/{id}', [DeviceController::class, 'destroy'])->middleware('idempotency');

            // What this person decided about their own screens, carried by the
            // account rather than by one device: the same keys must answer on the
            // phone and in the browser, and a preference nobody can name is a
            // preference no client can draw without guessing.
            Route::get('/preferences', [PreferenceController::class, 'index']);
            Route::put('/preferences', [PreferenceController::class, 'update'])->middleware('idempotency');
            Route::get('/preferences/catalog', [PreferenceController::class, 'catalog']);
        });

        // The inbox is already filtered to the acting user in the controller;
        // this guard is what makes the declared `notifications.view` capability
        // something the backend actually checks rather than merely grants.
        Route::prefix('notifications')->middleware('permission:notifications.view')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
            Route::post('/read-all', [NotificationController::class, 'readAll']);
            Route::post('/{id}/read', [NotificationController::class, 'read']);
        });

        // The support desk: somebody's account misbehaved, and the complaint is
        // about the platform, not about another business (that is §4.13's
        // comment report or §4.8's dispute).
        //
        // Filing carries no permission and no `verified_tenant`, on purpose: the
        // account this door exists for is the one whose paper was rejected, whose
        // screen broke, or whose phone cannot get in — and refusing them the form
        // is silencing the complaint. The queue and the answer are the platform's
        // side of the same desk, and they are gated: `support.manage` is held by
        // no business role, because a counterparty must never read what the
        // other is complaining about.
        Route::prefix('support')->group(function () {
            Route::post('/tickets', [SupportController::class, 'store'])->middleware('throttle:10,1');
            Route::get('/tickets', [SupportController::class, 'index']);

            Route::middleware('permission:support.manage')->group(function () {
                Route::get('/queue', [SupportController::class, 'queue']);
                Route::get('/summary', [SupportController::class, 'summary']);
                Route::put('/tickets/{id}/answer', [SupportController::class, 'answer'])->middleware('idempotency');
            });
        });

        // Marketplace Seller & Buyer Operations
        Route::prefix('marketplace')->middleware('feature:marketplace')->group(function () {
            Route::post('/offers', [OfferController::class, 'store'])->middleware(['permission:offers.create', 'verified_tenant', 'idempotency']);
            Route::put('/offers/{id}', [OfferController::class, 'update'])->middleware(['permission:offers.update', 'idempotency']);
            Route::post('/offers/{id}/submit', [OfferController::class, 'submit'])->middleware(['permission:offers.publish', 'verified_tenant', 'idempotency']);
            Route::delete('/offers/{id}', [OfferController::class, 'destroy'])->middleware(['permission:offers.delete', 'idempotency']);
            Route::put('/offers/{id}/status', [OfferController::class, 'updateStatus'])->middleware(['permission:offers.publish', 'idempotency']);

            // The seller's own shelf, in every status. Registered as a separate
            // path rather than `/offers/mine` because the public
            // `/marketplace/offers/{id}` route is matched first.
            Route::get('/my-offers', [OfferController::class, 'mine'])->middleware('permission:offers.view');

            // The moderation queue: unpublished listings belonging to other
            // businesses, which only a platform reviewer may ask for.
            Route::get('/moderation-queue', [OfferController::class, 'moderationQueue'])->middleware('permission:offers.moderate');
            // Moderating another business's listing is a platform act, so it is
            // a separate route with a separate permission — not the seller's.
            Route::put('/offers/{id}/review', [OfferController::class, 'review'])->middleware(['permission:offers.moderate', 'idempotency']);

            // Promotion, which is not the same decision as publication. A seller
            // holding `offers.moderate` over their own shelf may not move the
            // market's front page, so the capability is its own — `offers.feature`
            // — and it is granted to no business role (see `config/rbac.php`).
            Route::put('/offers/{id}/feature', [OfferController::class, 'feature'])->middleware(['permission:offers.feature', 'idempotency']);

            // Atomic Reservations
            //
            // Read the buyer's own holds, and release one the buyer no longer
            // wants. Creating a hold is not a buyer's act: `reserved_quantity`
            // moves when the seller accepts the request, inside
            // `POST /purchase-requests/{id}/accept`. The route that used to let
            // a buyer lock a seller's shelf directly is gone, because it made
            // that review decorative rather than decisive.
            Route::get('/reservations', [ReservationController::class, 'index']);
            Route::post('/reservations/{id}/cancel', [ReservationController::class, 'cancel'])->middleware(['permission:requests.cancel', 'idempotency']);
        });

        // Offer community: interest, questions, and reports.
        //
        // A like and a comment are attributed rows, not counters a client
        // maintains: they carry the user that produced them, and the
        // organization in context when one exists. A consumer with no company
        // acts in their own name — `offers.like` and `comments.create` are
        // person capabilities, so these doors need no membership. Publishing a
        // trade is a commercial act and its doors stay behind the organization
        // gates; publishing a question is not.
        Route::prefix('marketplace')->middleware('feature:community')->group(function () {
            Route::post('/offers/{id}/like', [OfferLikeController::class, 'store'])->middleware(['permission:offers.like', 'verified_tenant', 'idempotency']);
            Route::delete('/offers/{id}/like', [OfferLikeController::class, 'destroy'])->middleware(['permission:offers.like', 'idempotency']);
            Route::get('/liked-offers', [OfferLikeController::class, 'mine']);

            Route::post('/offers/{id}/comments', [OfferCommentController::class, 'store'])->middleware(['permission:comments.create', 'verified_tenant', 'idempotency']);
            // Removal is by authorship (or the author's own organization, or
            // the platform) and the controller compares those against the row —
            // `comments.create` would let a co-worker delete someone else's
            // comment, and a moderator who never comments would be locked out.
            Route::delete('/comments/{id}', [OfferCommentController::class, 'destroy'])->middleware('idempotency');
            Route::post('/comments/{id}/report', [OfferCommentController::class, 'report'])->middleware(['permission:comments.create', 'idempotency']);
        });

        // Platform-side community moderation: the review queue for comments,
        // and the reports filed against them.
        //
        // `community.moderate` is held by no business role on purpose — reading
        // another organization's reports is a platform act, the same class of
        // act as moderating their listings.
        Route::prefix('community')->middleware(['permission:community.moderate', 'feature:community'])->group(function () {
            Route::get('/summary', [CommunityModerationController::class, 'summary']);
            Route::get('/comments', [CommunityModerationController::class, 'comments']);
            Route::put('/comments/{id}/moderate', [CommunityModerationController::class, 'moderate'])->middleware('idempotency');
            Route::get('/reports', [CommunityModerationController::class, 'reports']);
            Route::post('/reports/{id}/review', [CommunityModerationController::class, 'reviewReport'])->middleware('idempotency');
        });

        // B2B Deals & State Machine
        //
        // The transition endpoint is deliberately gated only on viewing: each
        // target state declares its own permission inside the controller and
        // its own actor inside the state machine, because one route serves the
        // buyer, the seller, and the platform.
        Route::prefix('deals')->middleware(['permission:deals.view', 'feature:marketplace'])->group(function () {
            Route::post('/', [DealController::class, 'store'])->middleware(['permission:deals.create', 'verified_tenant', 'idempotency']);
            Route::post('/{id}/transition', [DealController::class, 'transition'])->middleware('idempotency');

            // The handover code: issued by the seller's acceptance, shown to
            // whichever party opens the order, and spent once by a scan at the
            // gate. No deal reaches COMPLETED without that scan, so this is not a
            // convenience endpoint beside the flow — it is a step in it (§4.7).
            Route::get('/{id}/handover', [DealHandoverController::class, 'show']);
            // The same code as a printed label: an SVG of the symbol itself, for
            // the box that leaves the warehouse before the phone that carries it.
            Route::get('/{id}/handover/label', [DealHandoverController::class, 'label']);
            Route::post('/{id}/handover/verify', [DealHandoverController::class, 'verify'])
                ->middleware(['permission:deals.deliver', 'idempotency']);

            Route::get('/{id}', [DealController::class, 'show']);
            Route::get('/', [DealController::class, 'index']);
        });

        // Double-Entry Ledger & Financial Explorer
        Route::prefix('ledger')->middleware(['permission:ledger.view', 'feature:double_entry_ledger'])->group(function () {
            Route::get('/journal-entries', [LedgerController::class, 'journalEntries']);
            Route::get('/accounts', [LedgerController::class, 'accounts']);
            Route::get('/trial-balance', [LedgerController::class, 'trialBalance']);
        });

        // Inventory Movements Ledger
        Route::prefix('inventory-movements')->group(function () {
            Route::get('/', [InventoryMovementController::class, 'index'])->middleware('permission:warehouses.view');
            Route::post('/adjust', [InventoryMovementController::class, 'recordAdjustment'])->middleware(['permission:warehouses.manage', 'idempotency']);
        });

        // Multi-Currency Administration. Guarded by the permission it declares
        // (`currencies.manage`), not by a role name: today only SuperAdmin holds
        // it, so the reachable set is the same, but the check and the catalog
        // now say the same thing.
        Route::middleware('permission:currencies.manage')->prefix('currencies')->group(function () {
            Route::get('/', [CurrencyManagementController::class, 'index']);
            Route::post('/', [CurrencyManagementController::class, 'store'])->middleware('idempotency');
            Route::put('/{id}', [CurrencyManagementController::class, 'update'])->middleware('idempotency');
            Route::post('/{id}/set-default', [CurrencyManagementController::class, 'setDefault'])->middleware('idempotency');
        });

        // User Administration
        Route::middleware('permission:users.manage')->prefix('users')->group(function () {
            Route::get('/', [UserManagementController::class, 'index']);
            // Before `/{id}`: a literal segment first, or "roles" is read as a
            // user id and the console gets a 404 instead of its vocabulary.
            Route::get('/roles', [UserManagementController::class, 'roles']);
            Route::get('/{id}', [UserManagementController::class, 'show']);
            Route::put('/{id}/status', [UserManagementController::class, 'updateStatus'])->middleware('idempotency');
            Route::post('/{id}/roles', [UserManagementController::class, 'assignRole'])->middleware('idempotency');
            Route::delete('/{id}/roles/{roleName}', [UserManagementController::class, 'revokeRole']);
        });

        // Platform permission matrix — the account door.
        //
        // `roles.manage` and not `users.manage`: editing what a role is worth is a
        // different act from editing an account, and the console that had both
        // under one capability could not say which of them an operator was being
        // given. The scope makes the door one-directional — a business capability
        // can never be attached from here, and no organization route reads this
        // permission — so the platform's own powers and the companies' powers are
        // edited by two doors that cannot reach each other's rows.
        Route::middleware('permission:roles.manage')->prefix('rbac')->group(function () {
            Route::get('/platform', [RoleGovernanceController::class, 'index']);
            Route::put('/platform/roles/{roleName}/permissions', [RoleGovernanceController::class, 'updateRolePermissions'])->middleware('idempotency');
            Route::get('/platform/users/{id}/permissions', [RoleGovernanceController::class, 'showUserPermissions']);
            Route::put('/platform/users/permissions', [RoleGovernanceController::class, 'updateUserPermissions'])->middleware('idempotency');
        });

        // Reports & Data Export
        Route::prefix('reports')->middleware('permission:reports.view')->group(function () {
            Route::get('/sales-summary', [ReportExportController::class, 'salesSummary']);
            Route::get('/export/deals-csv', [ReportExportController::class, 'exportDealsCsv']);
        });

        // B2B Demands RFQ & Quotations
        //
        // The gate is on starting commercial conversations, not on seeing them:
        // an unverified business can still read demands and answer for work
        // already in motion, so a pending review never strands an open deal.
        Route::prefix('demands')->middleware(['permission:demands.view', 'feature:demand_rfq'])->group(function () {
            Route::post('/', [DemandController::class, 'store'])->middleware(['permission:demands.create', 'verified_tenant', 'idempotency']);
            Route::post('/{id}/quotations', [DemandController::class, 'submitQuotation'])->middleware(['permission:demands.quote', 'verified_tenant', 'idempotency']);
            Route::get('/{id}/matches', [DemandController::class, 'matches'])->middleware('feature:matching_engine');
            Route::get('/', [DemandController::class, 'index']);
        });

        Route::prefix('purchase-requests')->middleware('permission:requests.view')->group(function () {
            // The marketplace fast path: an offer the buyer found becomes an ask
            // the seller must answer. No demand and no match stand behind it,
            // and no quantity moves until the seller accepts.
            Route::post('/', [PurchaseRequestController::class, 'storeFromOffer'])->middleware(['permission:requests.create', 'verified_tenant', 'idempotency']);
            Route::post('/matches/{matchId}', [PurchaseRequestController::class, 'storeFromMatch'])->middleware(['permission:requests.create', 'verified_tenant', 'idempotency']);
            Route::post('/{id}/accept', [PurchaseRequestController::class, 'accept'])->middleware(['permission:requests.approve', 'idempotency']);
            Route::post('/{id}/reject', [PurchaseRequestController::class, 'reject'])->middleware(['permission:requests.reject', 'idempotency']);
            Route::post('/{id}/propose', [PurchaseRequestController::class, 'propose'])->middleware(['permission:requests.update', 'idempotency']);
            Route::post('/{id}/accept-proposal', [PurchaseRequestController::class, 'acceptProposal'])->middleware(['permission:requests.update', 'idempotency']);
            Route::post('/{id}/cancel', [PurchaseRequestController::class, 'cancel'])->middleware(['permission:requests.cancel', 'idempotency']);
            Route::get('/{id}', [PurchaseRequestController::class, 'show']);
            Route::get('/', [PurchaseRequestController::class, 'index']);
        });

        // Logistics & Warehouses
        Route::prefix('warehouses')->group(function () {
            Route::get('/', [WarehouseController::class, 'index'])->middleware('permission:warehouses.view');
            Route::post('/', [WarehouseController::class, 'store'])->middleware(['permission:warehouses.manage', 'idempotency']);
            // A pin dropped by the browser is a reading, and readings get
            // corrected. The controller keeps the row inside the caller's
            // tenant, so the id in the path cannot name somebody else's depot.
            Route::put('/{id}', [WarehouseController::class, 'update'])->middleware(['permission:warehouses.manage', 'idempotency']);
        });

        // Catalog Management
        //
        // Images ride on `catalog.manage` rather than a new permission: whoever
        // may edit the product may show it. The size, mime and count ceilings
        // come from `config('hassal.uploads.images')` and the stored path is
        // generated here, so no client-supplied filename reaches the disk.
        Route::prefix('catalog')->group(function () {
            Route::get('/products', [ProductCatalogController::class, 'products'])->middleware('permission:offers.view');
            Route::get('/products/{id}/variants', [ProductCatalogController::class, 'variants']);
            Route::post('/products', [ProductCatalogController::class, 'storeProduct'])->middleware(['permission:catalog.manage', 'idempotency']);
            Route::put('/products/{id}', [ProductCatalogController::class, 'updateProduct'])->middleware(['permission:catalog.manage', 'idempotency']);

            Route::get('/products/{productId}/images', [ProductImageController::class, 'index'])->middleware('permission:catalog.manage');
            Route::post('/products/{productId}/images', [ProductImageController::class, 'store'])->middleware(['permission:catalog.manage', 'idempotency']);
            Route::put('/products/{productId}/images/{imageId}', [ProductImageController::class, 'update'])->middleware(['permission:catalog.manage', 'idempotency']);
            Route::delete('/products/{productId}/images/{imageId}', [ProductImageController::class, 'destroy'])->middleware(['permission:catalog.manage', 'idempotency']);
        });

        // Disputes & Claims
        //
        // The group gates nothing: "open" is what a party to a deal may do and
        // "resolve" is what the platform may do, so each route carries its own.
        Route::prefix('disputes')->group(function () {
            Route::post('/{id}/resolve', [DisputeController::class, 'resolve'])->middleware(['permission:disputes.resolve', 'idempotency']);
            Route::post('/', [DisputeController::class, 'store'])->middleware(['permission:disputes.open', 'verified_tenant', 'idempotency']);
            Route::get('/', [DisputeController::class, 'index'])->middleware('permission:disputes.open');
        });

        // Organizations & Memberships
        Route::prefix('organizations')->group(function () {
            Route::post('/', [OrganizationController::class, 'store'])->middleware('idempotency');

            // The staffing vocabulary a member screen should offer. Read by any
            // signed-in account because it discloses no business data — only
            // which words the platform accepts — and because a dropdown built
            // from a copy of the list goes stale against the validator.
            Route::get('/membership-roles', [OrganizationController::class, 'membershipRoles']);

            Route::put('/{id}', [OrganizationController::class, 'update'])->middleware(['permission:organizations.manage', 'idempotency']);

            // Deciding whether a business may trade is a platform act, so it is
            // its own permission and not `organizations.manage` — which every
            // SellerAdmin holds for their own company.
            Route::put('/{id}/verification', [OrganizationController::class, 'verification'])->middleware(['permission:organizations.verify', 'idempotency']);

            // The paperwork that decision is made from.
            //
            // Filing uses `organizations.manage` because that is already the
            // capability "change what this company's record says"; reviewing
            // uses `organizations.verify`, the same capability that flips the
            // status, so a business can never accept its own documents.
            //
            // Deliberately outside `verified_tenant`: this is the one group an
            // unverified business must be able to reach, or the gate would
            // remove the exit it demands. The controller still scopes every
            // route to the caller's own organization (or the platform).
            // The platform's own desk, across every business (`queue()`,
            // `summary()`). Registered before the `/{organizationId}` and
            // `/{id}` routes below so these literal segments are matched as
            // paths and never swallowed as an organization id.
            Route::get('/document-queue', [OrganizationDocumentController::class, 'queue'])->middleware('permission:organizations.verify');
            Route::get('/document-summary', [OrganizationDocumentController::class, 'summary'])->middleware('permission:organizations.verify');

            Route::get('/{organizationId}/documents', [OrganizationDocumentController::class, 'index']);
            Route::post('/{organizationId}/documents', [OrganizationDocumentController::class, 'store'])->middleware(['permission:organizations.manage', 'idempotency']);
            Route::delete('/{organizationId}/documents/{documentId}', [OrganizationDocumentController::class, 'destroy'])->middleware(['permission:organizations.manage', 'idempotency']);
            Route::put('/{organizationId}/documents/{documentId}/review', [OrganizationDocumentController::class, 'review'])->middleware(['permission:organizations.verify', 'idempotency']);

            Route::put('/{id}/members/{userId}', [OrganizationController::class, 'updateMember'])->middleware(['permission:members.manage', 'idempotency']);
            Route::delete('/{id}/members/{userId}', [OrganizationController::class, 'removeMember'])->middleware('permission:members.manage');
            Route::post('/{id}/members', [OrganizationController::class, 'addMember'])->middleware(['permission:members.manage', 'idempotency']);

            // The company permission matrix — the organization door.
            //
            // Same capability that staffs the company (`members.manage`), because
            // it is the same act: saying what a person here may do. Every route
            // takes the organization id from the path and the controller asks the
            // caller's membership *in that company*, so the tenant header is never
            // the authority for editing another business's matrix.
            //
            // `/{id}/members/permissions/bulk` is registered after the individual
            // path only because the two cannot collide — the segment the individual
            // route expects at the end is `permissions`, and here it is `bulk`.
            Route::get('/{id}/roles', [OrganizationRoleController::class, 'index'])->middleware('permission:members.manage');
            Route::post('/{id}/roles', [OrganizationRoleController::class, 'store'])->middleware(['permission:members.manage', 'idempotency']);
            Route::put('/{id}/roles/{key}', [OrganizationRoleController::class, 'update'])->middleware(['permission:members.manage', 'idempotency']);
            Route::delete('/{id}/roles/{key}', [OrganizationRoleController::class, 'destroy'])->middleware(['permission:members.manage', 'idempotency']);
            Route::get('/{id}/members/{userId}/permissions', [OrganizationRoleController::class, 'memberPermissions'])->middleware('permission:members.manage');
            Route::put('/{id}/members/{userId}/permissions', [OrganizationRoleController::class, 'updateMemberPermissions'])->middleware(['permission:members.manage', 'idempotency']);
            Route::put('/{id}/members/permissions/bulk', [OrganizationRoleController::class, 'bulkMemberPermissions'])->middleware(['permission:members.manage', 'idempotency']);

            Route::get('/{id}/members', [OrganizationController::class, 'members']);
            Route::get('/{id}', [OrganizationController::class, 'show']);
            Route::get('/', [OrganizationController::class, 'index']);
        });

        // System Settings & Engine Configuration
        Route::middleware('role:SuperAdmin')->prefix('settings')->group(function () {
            Route::get('/', [SystemSettingController::class, 'index']);
            Route::post('/update', [SystemSettingController::class, 'update'])->middleware('idempotency');
        });

        // Feature Flags
        Route::middleware('permission:settings.manage')->prefix('feature-flags')->group(function () {
            Route::get('/', [FeatureFlagController::class, 'index']);
            Route::post('/{key}/toggle', [FeatureFlagController::class, 'toggle'])->middleware('idempotency');
        });

        // Developer & B2B Integration (API Keys & Webhooks)
        //
        // `feature:api_keys` sits on issuing alone. The capability is off because
        // no endpoint in this application authenticates a key yet, and a screen
        // that hands a business a credential which opens nothing is a false
        // integration. Listing and revoking stay reachable: an install that
        // issued keys while the flag was on has to be able to see and delete
        // them, and a management surface you cannot enter is not a management
        // surface.
        Route::prefix('api-keys')->middleware(['permission:api.manage'])->group(function () {
            Route::get('/', [ApiKeyController::class, 'index']);
            Route::post('/', [ApiKeyController::class, 'store'])->middleware(['feature:api_keys', 'idempotency']);
            Route::delete('/{id}', [ApiKeyController::class, 'revoke']);
        });

        Route::prefix('webhooks')->middleware(['permission:api.manage', 'feature:webhooks'])->group(function () {
            Route::get('/', [WebhookController::class, 'index']);
            Route::post('/', [WebhookController::class, 'store'])->middleware('idempotency');
            Route::post('/{id}/ping', [WebhookController::class, 'ping']);
            Route::get('/{id}/deliveries', [WebhookController::class, 'deliveries']);
        });

        // Audit Trail — SuperAdmin sees everything; everyone else is hard-scoped
        // to their own organization and is rejected if no tenant resolved. The
        // scoping rule itself is `AuditTrailQueries`'s, in the Platform module
        // beside the record it reads.
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view');
    });
});
