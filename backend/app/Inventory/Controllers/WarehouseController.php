<?php

namespace App\Inventory\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Inventory\Resources\WarehouseResource;
use App\Core\Http\ApiResponse;
use App\Inventory\Models\Warehouse;
use App\Core\Tenant\TenantContext;
use App\Core\Support\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WarehouseController extends Controller
{
    /**
     * The board of locations, and — when the caller says where they are — how
     * far each one is from them.
     *
     * Two doors reach this one method, and they are meant to: the market's map
     * (`/marketplace/warehouses`, no token, every active shed on the platform)
     * and a business's own list (`/warehouses`, `warehouses.view`, its tenant's
     * rows). What a caller *sees* is decided by `WarehouseResource`; what a
     * caller *filters by* is decided here.
     *
     * `latitude`/`longitude` arrive as a pair or not at all. A half position is
     * refused rather than guessed at, because a distance measured from a
     * longitude with no latitude is a number that looks exactly like a real one.
     * `radius_km` narrows the board and means nothing without a position, so it
     * is refused on its own too — silently ignoring a filter is how a client
     * ends up showing a buyer thirty sheds when it asked for five.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'min:0.1', 'max:20000'],
        ]);

        $orgId = TenantContext::id();
        $isPlatform = $request->user()?->isPlatformOperator() ?? false;
        $query = Warehouse::query();
        if ($orgId && !$isPlatform) {
            $query->where('organization_id', $orgId);
        }

        $warehouses = $query->where('is_active', true)->orderBy('name')->get();

        $from = isset($validated['latitude']) && isset($validated['longitude'])
            ? [(float) $validated['latitude'], (float) $validated['longitude']]
            : null;

        // A radius with no centre is not a loose filter, it is a question that
        // cannot be asked. Written as a refusal rather than a silent skip,
        // because a client that sent `radius_km` and got the whole platform back
        // would show a buyer thirty sheds they had just excluded — and the
        // response would look like a successful search.
        if ($from === null && array_key_exists('radius_km', $validated)) {
            return ApiResponse::error(
                'radius_km needs a latitude and a longitude to measure from.',
                'VALIDATION_FAILED',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['position' => ['A radius asks how far from somewhere.']]
            );
        }

        if ($from !== null) {
            // Computed in PHP over rows already in hand, which is the right side
            // of the line for this board: a platform has tens of warehouses per
            // tenant, not the thousands a nearby-offers query sorts through, and
            // `App\Core\Support\Geo` is the same formula the SQL path uses, so the
            // distance on a shed's own card matches the distance on the listing
            // that names that shed.
            $warehouses->each(function (Warehouse $warehouse) use ($from): void {
                $warehouse->setAttribute('distance_km', Geo::kilometers(
                    $from[0],
                    $from[1],
                    $warehouse->latitude === null ? null : (float) $warehouse->latitude,
                    $warehouse->longitude === null ? null : (float) $warehouse->longitude,
                ));
            });

            if (isset($validated['radius_km'])) {
                $radius = (float) $validated['radius_km'];
                $warehouses = $warehouses->filter(
                    fn (Warehouse $w) => $w->getAttribute('distance_km') !== null
                        && (float) $w->getAttribute('distance_km') <= $radius
                )->values();
            }

            // Nearest first, with the unlocated ones last rather than dropped:
            // a shed with no coordinates is still a shed the buyer may need to
            // find by name, and silently vanishing it would read as the platform
            // having none.
            $warehouses = $warehouses->sortBy(
                fn (Warehouse $w) => [
                    $w->getAttribute('distance_km') === null ? 1 : 0,
                    (float) ($w->getAttribute('distance_km') ?? 0),
                ]
            )->values();
        }

        return ApiResponse::success(
            WarehouseResource::collection($warehouses)->resolve($request),
            'Warehouses retrieved successfully'
        );
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
        if ($request->user()->isPlatformOperator()) {
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
