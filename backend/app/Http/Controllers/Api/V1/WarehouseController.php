<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Warehouse;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WarehouseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orgId = TenantContext::id();
        $query = Warehouse::query();

        if ($orgId && !$request->user()->hasRole('SuperAdmin')) {
            $query->where('organization_id', $orgId);
        }

        $warehouses = $query->where('is_active', true)->orderBy('name')->get();

        return ApiResponse::success($warehouses, 'Warehouses retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $orgId = TenantContext::id();
        if (!$orgId) {
            return ApiResponse::error('Active organization required', 'TENANT_REQUIRED', Response::HTTP_FORBIDDEN);
        }

        $warehouse = Warehouse::create(array_merge($this->rules($request), ['organization_id' => $orgId]));

        return ApiResponse::success($warehouse, 'Warehouse created successfully', Response::HTTP_CREATED);
    }

    /**
     * Correct a location the business entered or captured wrongly.
     *
     * Scoped by the resolved tenant rather than by the id alone: the id in the
     * path names a row, the tenant decides whether this caller may touch it.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $warehouse = $this->ownedWarehouse($request, $id);
        if ($warehouse instanceof JsonResponse) {
            return $warehouse;
        }

        $warehouse->fill($this->rules($request))->save();

        return ApiResponse::success($warehouse->fresh(), 'Warehouse updated successfully');
    }

    /**
     * The row, if this tenant owns it.
     *
     * A non-owner gets the same answer as a missing row, on purpose: confirming
     * that another business has a warehouse with this id is itself a leak.
     */
    private function ownedWarehouse(Request $request, int $id): Warehouse|JsonResponse
    {
        $orgId = TenantContext::id();
        if (!$orgId) {
            return ApiResponse::error('Active organization required', 'TENANT_REQUIRED', Response::HTTP_FORBIDDEN);
        }

        $query = Warehouse::query()->where('organization_id', $orgId);
        if ($request->user()->hasRole('SuperAdmin')) {
            $query = Warehouse::query();
        }

        $warehouse = $query->find($id);

        return $warehouse
            ?: ApiResponse::error('Warehouse not found', 'WAREHOUSE_NOT_FOUND', Response::HTTP_NOT_FOUND);
    }

    /**
     * Shared rules, so a warehouse cannot be editable in one endpoint and
     * rejected by the other.
     *
     * The coordinate ranges are the WGS84 ones, not a country box: this
     * platform is not tied to a single state's boundaries, and an out-of-range
     * reading is what a browser geolocation failure looks like — the failure
     * case is exactly what the check has to catch.
     */
    private function rules(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'city' => ['required', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'address_line' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
