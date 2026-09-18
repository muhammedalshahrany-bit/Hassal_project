<?php

use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BootstrapController;
use App\Http\Controllers\Api\V1\CurrencyManagementController;
use App\Http\Controllers\Api\V1\DealController;
use App\Http\Controllers\Api\V1\DemandController;
use App\Http\Controllers\Api\V1\DisputeController;
use App\Http\Controllers\Api\V1\FeatureFlagController;
use App\Http\Controllers\Api\V1\InventoryMovementController;
use App\Http\Controllers\Api\V1\LedgerController;
use App\Http\Controllers\Api\V1\OfferController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\ProductCatalogController;
use App\Http\Controllers\Api\V1\ReconciliationController;
use App\Http\Controllers\Api\V1\ReportExportController;
use App\Http\Controllers\Api\V1\ReservationController;
use App\Http\Controllers\Api\V1\SetupWizardController;
use App\Http\Controllers\Api\V1\SystemSettingController;
use App\Http\Controllers\Api\V1\UserManagementController;
use App\Http\Controllers\Api\V1\WarehouseController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PurchaseRequestController;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Health & System status
    Route::get('/health', function () {
        return ApiResponse::success([
            'status' => 'healthy',
            'platform' => 'HASSAL B2B Marketplace API',
            'version' => '2.0.0-production',
            'target_db' => 'MySQL 8.4 (InnoDB)',
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'timestamp' => now()->toIso8601String(),
        ], 'HASSAL API Engine is running smoothly');
    });

    // Initial Commercial Setup Wizard
    Route::prefix('setup-wizard')->group(function () {
        Route::get('/status', [SetupWizardController::class, 'status']);
        Route::post('/configure', [SetupWizardController::class, 'configure'])->middleware(['auth:sanctum', 'role:SuperAdmin', 'idempotency']);
    });

    // Public / Hybrid Bootstrap endpoint for Flutter Mobile & Web Client
    Route::get('/bootstrap', [BootstrapController::class, 'index']);

    // Public Marketplace exploration endpoints
    Route::prefix('marketplace')->group(function () {
        Route::get('/offers', [OfferController::class, 'index']);
        Route::get('/offers/nearby', [OfferController::class, 'nearby']);
        Route::get('/offers/{id}', [OfferController::class, 'show']);
        Route::get('/warehouses', [WarehouseController::class, 'index']);
        Route::get('/categories', [ProductCatalogController::class, 'categories']);
        Route::get('/brands', [ProductCatalogController::class, 'brands']);
    });

    // Public Auth endpoints
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware('idempotency');
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:6,1');
        Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    });

    // Protected Routes (Sanctum + Tenant context + Idempotency + Audit)
    Route::middleware(['auth:sanctum', 'tenant', 'audit'])->group(function () {

        // Authenticated user & identity
        Route::prefix('auth')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/switch-organization', [AuthController::class, 'switchOrganization']);
            Route::post('/logout', [AuthController::class, 'logout']);
        });

        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
            Route::post('/read-all', [NotificationController::class, 'readAll']);
            Route::post('/{id}/read', [NotificationController::class, 'read']);
        });

        // Marketplace Seller & Buyer Operations
        Route::prefix('marketplace')->group(function () {
            Route::post('/offers', [OfferController::class, 'store'])->middleware(['permission:offers.create', 'idempotency']);
            Route::put('/offers/{id}/status', [OfferController::class, 'updateStatus'])->middleware('idempotency');

            // Atomic Reservations
            Route::get('/reservations', [ReservationController::class, 'index']);
            Route::post('/reservations', [ReservationController::class, 'store'])->middleware(['permission:requests.create', 'idempotency']);
            Route::post('/reservations/{id}/cancel', [ReservationController::class, 'cancel'])->middleware(['permission:requests.cancel', 'idempotency']);
        });

        // B2B Deals & State Machine
        Route::prefix('deals')->group(function () {
              Route::get('/', [DealController::class, 'index'])->middleware('permission:deals.view');
            Route::post('/', [DealController::class, 'store'])->middleware('idempotency');
            Route::get('/{id}', [DealController::class, 'show']);
            Route::post('/{id}/transition', [DealController::class, 'transition'])->middleware('idempotency');
        });

        // Double-Entry Ledger & Financial Explorer
        Route::prefix('ledger')->group(function () {
            Route::get('/journal-entries', [LedgerController::class, 'journalEntries']);
            Route::get('/accounts', [LedgerController::class, 'accounts']);
            Route::get('/trial-balance', [LedgerController::class, 'trialBalance']);
        });

        // Financial Reconciliation Audit
        Route::prefix('reconciliation')->group(function () {
            Route::get('/audit', [ReconciliationController::class, 'audit']);
        });

        // Inventory Movements Ledger
        Route::prefix('inventory-movements')->group(function () {
            Route::get('/', [InventoryMovementController::class, 'index']);
            Route::post('/adjust', [InventoryMovementController::class, 'recordAdjustment'])->middleware('idempotency');
        });

        // Multi-Currency Administration
        Route::middleware('role:SuperAdmin')->prefix('currencies')->group(function () {
            Route::get('/', [CurrencyManagementController::class, 'index']);
            Route::post('/', [CurrencyManagementController::class, 'store'])->middleware('idempotency');
            Route::put('/{id}', [CurrencyManagementController::class, 'update'])->middleware('idempotency');
            Route::post('/{id}/set-default', [CurrencyManagementController::class, 'setDefault'])->middleware('idempotency');
        });

        // User Administration
        Route::prefix('users')->group(function () {
            Route::get('/', [UserManagementController::class, 'index']);
            Route::get('/{id}', [UserManagementController::class, 'show']);
            Route::put('/{id}/status', [UserManagementController::class, 'updateStatus'])->middleware('idempotency');
            Route::post('/{id}/roles', [UserManagementController::class, 'assignRole'])->middleware('idempotency');
            Route::delete('/{id}/roles/{roleName}', [UserManagementController::class, 'revokeRole']);
        });

        // Reports & Data Export
        Route::prefix('reports')->group(function () {
            Route::get('/sales-summary', [ReportExportController::class, 'salesSummary']);
            Route::get('/export/deals-csv', [ReportExportController::class, 'exportDealsCsv']);
        });

        // B2B Demands RFQ & Quotations
        Route::prefix('demands')->group(function () {
            Route::get('/', [DemandController::class, 'index']);
            Route::post('/', [DemandController::class, 'store'])->middleware('idempotency');
            Route::get('/{id}/matches', [DemandController::class, 'matches']);
            Route::post('/{id}/quotations', [DemandController::class, 'submitQuotation'])->middleware('idempotency');
        });

        Route::prefix('purchase-requests')->group(function () {
            Route::get('/', [PurchaseRequestController::class, 'index'])->middleware('permission:requests.view');
            Route::get('/{id}', [PurchaseRequestController::class, 'show']);
            Route::post('/matches/{matchId}', [PurchaseRequestController::class, 'storeFromMatch'])->middleware('idempotency');
            Route::post('/{id}/accept', [PurchaseRequestController::class, 'accept'])->middleware('idempotency');
            Route::post('/{id}/reject', [PurchaseRequestController::class, 'reject'])->middleware('idempotency');
            Route::post('/{id}/propose', [PurchaseRequestController::class, 'propose'])->middleware('idempotency');
        });

        // Logistics & Warehouses
        Route::prefix('warehouses')->group(function () {
            Route::get('/', [WarehouseController::class, 'index'])->middleware('permission:warehouses.view');
            Route::post('/', [WarehouseController::class, 'store'])->middleware(['permission:warehouses.manage', 'idempotency']);
        });

        // Catalog Management
        Route::prefix('catalog')->group(function () {
            Route::get('/products', [ProductCatalogController::class, 'products'])->middleware('permission:offers.view');
            Route::get('/products/{id}/variants', [ProductCatalogController::class, 'variants']);
            Route::post('/products', [ProductCatalogController::class, 'storeProduct'])->middleware(['permission:catalog.manage', 'idempotency']);
        });

        // Disputes & Claims
        Route::prefix('disputes')->group(function () {
            Route::get('/', [DisputeController::class, 'index']);
            Route::post('/', [DisputeController::class, 'store'])->middleware('idempotency');
            Route::post('/{id}/resolve', [DisputeController::class, 'resolve'])->middleware('idempotency');
        });

        // Organizations & Memberships
        Route::prefix('organizations')->group(function () {
            Route::get('/', [OrganizationController::class, 'index']);
            Route::post('/', [OrganizationController::class, 'store'])->middleware('idempotency');
            Route::get('/{id}', [OrganizationController::class, 'show']);
            Route::put('/{id}', [OrganizationController::class, 'update'])->middleware('idempotency');

            Route::get('/{id}/members', [OrganizationController::class, 'members']);
            Route::post('/{id}/members', [OrganizationController::class, 'addMember'])->middleware('idempotency');
            Route::put('/{id}/members/{userId}', [OrganizationController::class, 'updateMember'])->middleware('idempotency');
            Route::delete('/{id}/members/{userId}', [OrganizationController::class, 'removeMember']);
        });

        // System Settings & Engine Configuration
        Route::middleware('role:SuperAdmin')->prefix('settings')->group(function () {
            Route::get('/', [SystemSettingController::class, 'index']);
            Route::post('/update', [SystemSettingController::class, 'update'])->middleware('idempotency');
        });

        // Feature Flags
        Route::middleware('role:SuperAdmin')->prefix('feature-flags')->group(function () {
            Route::get('/', [FeatureFlagController::class, 'index']);
            Route::post('/{key}/toggle', [FeatureFlagController::class, 'toggle'])->middleware('idempotency');
        });

        // Developer & B2B Integration (API Keys & Webhooks)
        Route::prefix('api-keys')->group(function () {
            Route::get('/', [ApiKeyController::class, 'index']);
            Route::post('/', [ApiKeyController::class, 'store'])->middleware('idempotency');
            Route::delete('/{id}', [ApiKeyController::class, 'revoke']);
        });

        Route::prefix('webhooks')->group(function () {
            Route::get('/', [WebhookController::class, 'index']);
            Route::post('/', [WebhookController::class, 'store'])->middleware('idempotency');
            Route::post('/{id}/ping', [WebhookController::class, 'ping']);
            Route::get('/{id}/deliveries', [WebhookController::class, 'deliveries']);
        });

        // Audit Trail
        Route::get('/audit-logs', function (Request $request) {
            $user = $request->user();
            $query = AuditLog::with(['user:id,name,email', 'organization:id,name_ar,name_en']);

            if (!$user->hasRole('SuperAdmin')) {
                $currentOrgId = TenantContext::id();
                $query->where('organization_id', $currentOrgId);
            }

            $logs = $query->orderBy('id', 'desc')->paginate($request->query('per_page', 20));
            return ApiResponse::paginated($logs, 'Audit logs retrieved');
        });
    });
});
