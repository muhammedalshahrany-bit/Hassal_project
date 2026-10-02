<?php

namespace App\Inventory\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Http\ApiResponse;
use App\Core\Exceptions\ApiRefused;
use App\Inventory\Models\InventoryMovement;
use App\Inventory\Services\StockAdjustmentService;
use App\Core\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The stock ledger: the book of movements, and the one door that writes a
 * correction into it.
 *
 * Reading is this file's job — a listing scoped to the caller's business, with
 * the filters they asked for. Writing is not: an adjustment moves an offer's
 * quantities and the ledger row that explains them in one breath, under a lock
 * and an invariant, and that is `StockAdjustmentService`'s decision to make.
 * This door hands over what the request contained and turns the service's
 * refusal into the API's answer without changing either one.
 */
class InventoryMovementController extends Controller
{
    public function __construct(
        protected StockAdjustmentService $adjustments
    ) {}

    public function index(Request $request): JsonResponse
    {
        $orgId = TenantContext::id();

        // The selects must name real columns. `warehouses` has name/city and
        // `product_variants` has sku/name_ar, so asking for name_ar/city_ar/
        // title_ar here made the whole listing a SQL error rather than a page.
        $query = InventoryMovement::with([
            'warehouse:id,name,code,city,country_code',
            'productVariant:id,sku,name_ar,name_en',
            'user:id,name',
        ]);

        if (!$request->user()->isPlatformOperator() && $orgId) {
            $query->where('organization_id', $orgId);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }

        if ($request->filled('offer_id')) {
            $query->where('offer_id', $request->integer('offer_id'));
        }

        $movements = $query->orderBy('id', 'desc')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return ApiResponse::paginated($movements, 'Inventory movements retrieved');
    }

    /**
     * Record a stock movement against one of the seller's own offers.
     *
     * The service refuses in exceptions and the route answers in envelopes; this
     * method is where one becomes the other, unchanged.
     */
    public function recordAdjustment(Request $request): JsonResponse
    {
        $orgId = TenantContext::id();
        if (!$orgId) {
            return ApiResponse::error(
                'مطلوب منشأة نشطة',
                'TENANT_REQUIRED',
                Response::HTTP_FORBIDDEN
            );
        }

        $validated = $request->validate([
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
            'type'     => ['required', Rule::in([
                InventoryMovement::TYPE_IN,
                InventoryMovement::TYPE_ADJUSTED,
                InventoryMovement::TYPE_RETURNED,
            ])],
            'quantity' => ['required', 'numeric'],
            'reason'   => ['required', 'string', 'max:500'],
        ]);

        try {
            $movement = $this->adjustments->adjust($validated, $orgId, $request->user()->id);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success(
            $movement->load([
                'warehouse:id,name,code,city,country_code',
                'productVariant:id,sku,name_ar,name_en',
            ]),
            'تم تسجيل حركة المخزون وتحديث كميات العرض',
            Response::HTTP_CREATED
        );
    }
}
