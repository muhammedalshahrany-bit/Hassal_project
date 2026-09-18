<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\v1\BootstrapController;
use App\Http\Controllers\Api\v1\AuthController;
use App\Http\Controllers\Api\v1\OfferController;
use App\Http\Controllers\Api\v1\PurchaseRequestController;
use App\Http\Controllers\Api\v1\DealController;
use App\Http\Controllers\Api\v1\PaymentController;
use App\Http\Controllers\Api\v1\LedgerController;
use App\Http\Controllers\Api\v1\DisputeController;
use App\Http\Controllers\Api\v1\DemandController;
use App\Http\Controllers\Api\v1\AdminController;

/*
|--------------------------------------------------------------------------
| HASSAL Marketplace API v1 Routes
|--------------------------------------------------------------------------
| All routes are versioned, stateless, secured via Laravel Sanctum,
| multi-tenant scoped, and fully accessible to Flutter mobile & web clients.
*/

Route::prefix('v1')->group(function () {

    // 1. Foundation & Health Endpoints
    Route::get('/health', [BootstrapController::class, 'health']);
    Route::get('/bootstrap', [BootstrapController::class, 'bootstrap']);

    // 2. Authentication
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    // 3. Catalog & Public Marketplace
    Route::get('/catalog/categories', [OfferController::class, 'categories']);
    Route::get('/catalog/brands', [OfferController::class, 'brands']);
    Route::get('/catalog/products', [OfferController::class, 'products']);
    Route::get('/catalog/products/{id}/variants', [OfferController::class, 'productVariants']);

    Route::get('/offers', [OfferController::class, 'index']);
    Route::get('/offers/nearby', [OfferController::class, 'nearby']);
    Route::get('/offers/{id}', [OfferController::class, 'show']);

    // 4. B2B Demands / RFQ Board (Public view)
    Route::get('/demands', [DemandController::class, 'index']);

    // -------------------------------------------------------------
    // Protected Routes (Sanctum Token + Tenant Header Required)
    // -------------------------------------------------------------
    Route::middleware(['auth:sanctum'])->group(function () {

        // User & Organization Switch
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/switch-organization', [AuthController::class, 'switchOrganization']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Seller Inventory & Warehouses
        Route::get('/warehouses', [OfferController::class, 'warehouses']);
        Route::post('/warehouses', [OfferController::class, 'storeWarehouse']);
        Route::post('/offers', [OfferController::class, 'store']);
        Route::post('/offers/{id}/publish', [OfferController::class, 'publish']);
        Route::post('/offers/{id}/pause', [OfferController::class, 'pause']);

        // Atomic Purchase Requests (X-Idempotency-Key enforced)
        Route::post('/purchase-requests', [PurchaseRequestController::class, 'store']);
        Route::get('/purchase-requests', [PurchaseRequestController::class, 'index']);
        Route::post('/purchase-requests/{id}/accept', [PurchaseRequestController::class, 'accept']);
        Route::post('/purchase-requests/{id}/reject', [PurchaseRequestController::class, 'reject']);

        // Deals & Order Pipeline
        Route::get('/deals', [DealController::class, 'index']);
        Route::get('/deals/{id}', [DealController::class, 'show']);
        Route::post('/deals/{id}/transition', [DealController::class, 'transition']);
        Route::post('/deals/{id}/confirm-received', [DealController::class, 'confirmReceived']);

        // Payments & Escrow
        Route::post('/deals/{id}/payments', [PaymentController::class, 'submit']);
        Route::post('/payments/{id}/verify', [PaymentController::class, 'verify']);

        // Double-Entry Ledger
        Route::get('/ledger/statement', [LedgerController::class, 'statement']);

        // Disputes & Arbitration
        Route::post('/deals/{id}/disputes', [DisputeController::class, 'store']);
        Route::post('/disputes/{id}/arbitrate', [DisputeController::class, 'arbitrate']);

        // Demands & RFQ
        Route::post('/demands', [DemandController::class, 'store']);

        // Admin Management Console
        Route::middleware(['role:SuperAdmin'])->group(function () {
            Route::get('/admin/settings', [AdminController::class, 'settings']);
            Route::put('/admin/settings', [AdminController::class, 'updateSettings']);
            Route::get('/admin/audit-logs', [AdminController::class, 'auditLogs']);
            Route::get('/organizations', [AdminController::class, 'organizations']);
        });
    });
});
