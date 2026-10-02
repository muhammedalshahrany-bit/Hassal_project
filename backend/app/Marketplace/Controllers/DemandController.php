<?php

namespace App\Marketplace\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Http\ApiResponse;
use App\Core\Exceptions\ApiRefused;
use App\Marketplace\Models\Demand;
use App\Marketplace\Services\DemandMatchingService;
use App\Marketplace\Services\DemandPublishingService;
use App\Marketplace\Services\QuotationSubmissionService;
use App\Core\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The buyer request board: reading it, publishing onto it, and answering what
 * is on it.
 *
 * Three doors do real work here and none of them decides anything. Publishing an
 * RFQ — which currency it is priced in, when it stops being open, whether the
 * matching engine has been told — belongs to `DemandPublishingService`; whether a
 * demand can still be quoted, and what a quote costs, belongs to
 * `QuotationSubmissionService`. What stays in this file is the part that only a
 * transport knows: which tenant the caller is speaking for, which fields the form
 * sent, and which envelope the answer goes out in.
 *
 * Reading matches is the exception that stays: `matchDemand()` is a query, not a
 * rule, and re-running it per request is a decision about freshness that belongs
 * to the route that is asked to be fresh.
 */
class DemandController extends Controller
{
    public function __construct(
        protected DemandMatchingService $matchingService,
        protected DemandPublishingService $publisher,
        protected QuotationSubmissionService $quotations,
    ) {}

    /**
     * Lists open B2B RFQ demands with filters.
     * Public view: only OPEN demands (unless my_demands=1 for authenticated buyer).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Demand::with(['buyerOrganization', 'category', 'quotations.sellerOrganization']);

        // Authenticated buyer sees own demands in any status
        if ($request->filled('my_demands') && TenantContext::id()) {
            $query->where('buyer_organization_id', TenantContext::id());
        } else {
            $query->where('status', Demand::STATUS_OPEN)
                  ->where(function ($q) {
                      $q->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                  });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        if ($request->filled('city')) {
            $query->where('delivery_city', 'like', '%' . $request->input('city') . '%');
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title_ar', 'like', "%{$search}%")
                  ->orWhere('title_en', 'like', "%{$search}%");
            });
        }

        $demands = $query->orderBy('created_at', 'desc')
                         ->paginate((int) $request->input('per_page', 20));

        return ApiResponse::paginated($demands, 'تم استرجاع طلبات الشراء بنجاح');
    }

    /**
     * Submits a new RFQ demand (Buyer organization).
     *
     * Clean, explicit validation — no field aliasing or multiple names
     * for the same concept.
     */
    public function store(Request $request): JsonResponse
    {
        $buyerOrgId = TenantContext::id();
        if (!$buyerOrgId) {
            return ApiResponse::error(
                'مطلوب منشأة مشتري نشطة',
                'TENANT_REQUIRED',
                Response::HTTP_FORBIDDEN
            );
        }

        $validated = $request->validate([
            'title_ar'              => ['required', 'string', 'max:255'],
            'title_en'              => ['nullable', 'string', 'max:255'],
            'description'           => ['nullable', 'string', 'max:2000'],
            'category_id'           => ['nullable', 'exists:categories,id'],
            'target_quantity'       => ['required', 'numeric', 'min:1'],
            'unit'                  => ['required', 'string', 'max:50'],
            'target_unit_price'     => ['nullable', 'numeric', 'min:0.0001'],
            'currency_code'         => ['nullable', 'string', 'size:3'],
            'target_delivery_date'  => ['nullable', 'date', 'after:today'],
            'delivery_city'         => ['required', 'string', 'max:100'],
            'delivery_latitude'     => ['nullable', 'numeric', 'between:-90,90'],
            'delivery_longitude'    => ['nullable', 'numeric', 'between:-180,180'],
            'special_requirements'  => ['nullable', 'string', 'max:1000'],
            'expires_at'            => ['nullable', 'date', 'after:now'],
        ]);

        try {
            $demand = $this->publisher->publish($validated, $buyerOrgId, $request->user()->id);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success(
            $demand->load('buyerOrganization'),
            'تم نشر طلب الشراء بنجاح',
            Response::HTTP_CREATED
        );
    }

    /**
     * Returns matching offers for a specific demand.
     * Re-runs the matching engine on each request to reflect latest inventory.
     */
    public function matches(int $id): JsonResponse
    {
        $demand      = Demand::with(['buyerOrganization', 'category'])->findOrFail($id);
        $buyerOrgId  = TenantContext::id();

        if (!$buyerOrgId || (int) $demand->buyer_organization_id !== (int) $buyerOrgId) {
            return ApiResponse::error(
                'ليس لديك صلاحية الوصول لهذا الطلب',
                'DEMAND_ACCESS_DENIED',
                Response::HTTP_FORBIDDEN
            );
        }

        $matches = $this->matchingService->matchDemand($demand);

        return ApiResponse::success([
            'demand'  => $demand,
            'matches' => $matches,
            'total'   => $matches->count(),
        ], 'تم استرجاع مطابقات الطلب بنجاح');
    }

    /**
     * Submits a quotation in response to an RFQ demand (Seller organization).
     *
     * The demand gate runs before the form is read, and inside the same
     * boundary: a closed RFQ has nothing for a valid price to attach to, so the
     * caller is told which of the two is missing. A bad field on a live demand
     * still answers as a validation error, because the exception that refuses a
     * quote and the exception that refuses a form are different types and only
     * one of them is caught here.
     */
    public function submitQuotation(Request $request, int $demandId): JsonResponse
    {
        $sellerOrgId = TenantContext::id();
        if (!$sellerOrgId) {
            return ApiResponse::error('مطلوب منشأة بائع نشطة', 'TENANT_REQUIRED', Response::HTTP_FORBIDDEN);
        }

        $demand = Demand::findOrFail($demandId);

        try {
            $this->quotations->assertQuotable($demand, $sellerOrgId);

            $validated = $request->validate([
                'quoted_quantity' => ['required', 'numeric', 'min:0.01'],
                'unit_price'      => ['required', 'numeric', 'min:0.0001'],
                'currency_code'   => ['nullable', 'string', 'size:3'],
                'validity_days'   => ['nullable', 'integer', 'min:1', 'max:60'],
                'notes'           => ['nullable', 'string', 'max:1000'],
            ]);

            $quotation = $this->quotations->submit($demand, $validated, $sellerOrgId, $request->user()->id);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success(
            $quotation->load(['sellerOrganization', 'demand']),
            'تم تقديم عرض السعر بنجاح',
            Response::HTTP_CREATED
        );
    }
}
