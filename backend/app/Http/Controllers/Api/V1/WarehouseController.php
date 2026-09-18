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

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'city' => ['required', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'address_line' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric'],
            'longitude' => ['required', 'numeric'],
            'contact_name' => ['nullable', 'string'],
            'contact_phone' => ['nullable', 'string'],
        ]);

        $warehouse = Warehouse::create(array_merge($validated, ['organization_id' => $orgId]));

        return ApiResponse::success($warehouse, 'Warehouse created successfully', Response::HTTP_CREATED);
    }
}
