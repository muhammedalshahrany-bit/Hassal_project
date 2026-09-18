<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\InventoryMovement;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryMovementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orgId = TenantContext::id();
        $query = InventoryMovement::with(['warehouse:id,name_ar,name_en,city_ar', 'productVariant:id,sku,title_ar', 'user:id,name']);

        if (!$request->user()->hasRole('SuperAdmin') && $orgId) {
            $query->where('organization_id', $orgId);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }

        $movements = $query->orderBy('id', 'desc')->paginate($request->query('per_page', 25));
        return ApiResponse::paginated($movements, 'Inventory movements retrieved');
    }

    public function recordAdjustment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'product_variant_id' => 'required|exists:product_variants,id',
            'type' => 'required|in:IN,ADJUSTED,RETURNED',
            'quantity' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:255',
        ]);

        $movement = InventoryMovement::create([
            'organization_id' => TenantContext::id() ?? $request->user()->organizations()->first()?->id,
            'warehouse_id' => $validated['warehouse_id'],
            'product_variant_id' => $validated['product_variant_id'],
            'user_id' => $request->user()->id,
            'type' => $validated['type'],
            'quantity' => $validated['quantity'],
            'reason' => $validated['reason'],
            'reference_type' => 'MANUAL_ADJUSTMENT',
            'reference_id' => 'ADJ-' . strtoupper(uniqid()),
        ]);

        return ApiResponse::success($movement, 'Inventory adjustment movement recorded successfully', 201);
    }
}
