<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Demand;
use App\Models\Quotation;
use App\Models\Currency;
use App\Services\DemandMatchingService;
use App\Services\SystemSettingsService;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DemandController extends Controller
{
    public function __construct(
        protected DemandMatchingService $matchingService,
        protected SystemSettingsService $settingsService,
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

        $expiryDays      = (int) $this->settingsService->get('demand.expiry_days', 14);
        $defaultCurrency = strtoupper((string) $this->settingsService->get('platform.default_currency'));
        $currencyCode = strtoupper($validated['currency_code'] ?? $defaultCurrency);

        if (!Currency::query()->where('iso_code', $currencyCode)->where('is_active', true)->exists()) {
            return ApiResponse::error('العملة المحددة غير موجودة أو غير مفعلة', 'CURRENCY_NOT_ACTIVE', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $demand = Demand::create([
            'demand_number'         => 'RFQ-' . date('ymd') . '-' . strtoupper(Str::random(6)),
            'buyer_organization_id' => $buyerOrgId,
            'buyer_user_id'         => $request->user()->id,
            'category_id'           => $validated['category_id'] ?? null,
            'title_ar'              => trim($validated['title_ar']),
            'title_en'              => isset($validated['title_en']) ? trim($validated['title_en']) : null,
            'description'           => $validated['description'] ?? null,
            'target_quantity'       => (float) $validated['target_quantity'],
            'unit'                  => trim($validated['unit']),
            'target_unit_price'     => isset($validated['target_unit_price']) ? (float) $validated['target_unit_price'] : null,
            'currency_code'         => $currencyCode,
            'target_delivery_date'  => $validated['target_delivery_date'] ?? null,
            'delivery_city'         => trim($validated['delivery_city']),
            'delivery_latitude'     => $validated['delivery_latitude'] ?? null,
            'delivery_longitude'    => $validated['delivery_longitude'] ?? null,
            'special_requirements'  => $validated['special_requirements'] ?? null,
            'status'                => Demand::STATUS_OPEN,
            'expires_at'            => $validated['expires_at'] ?? Carbon::now()->addDays($expiryDays),
        ]);

        // Run matching against existing active offers immediately
        try {
            $this->matchingService->matchDemand($demand);
        } catch (\Exception $e) {
            Log::error('Demand matching failed after demand creation', ['demand_id' => $demand->id, 'exception' => $e]);
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
     */
    public function submitQuotation(Request $request, int $demandId): JsonResponse
    {
        $sellerOrgId = TenantContext::id();
        if (!$sellerOrgId) {
            return ApiResponse::error('مطلوب منشأة بائع نشطة', 'TENANT_REQUIRED', Response::HTTP_FORBIDDEN);
        }

        $demand = Demand::findOrFail($demandId);

        if ($demand->status !== Demand::STATUS_OPEN) {
            return ApiResponse::error(
                'الطلب مغلق ولا يقبل عروض أسعار جديدة',
                'DEMAND_CLOSED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // Seller cannot quote their own demand
        if ((int) $demand->buyer_organization_id === (int) $sellerOrgId) {
            return ApiResponse::error(
                'لا يمكنك تقديم عرض سعر لطلب منشأتك',
                'SELF_QUOTATION_DENIED',
                Response::HTTP_FORBIDDEN
            );
        }

        $validated = $request->validate([
            'quoted_quantity' => ['required', 'numeric', 'min:0.01'],
            'unit_price'      => ['required', 'numeric', 'min:0.0001'],
            'currency_code'   => ['nullable', 'string', 'size:3'],
            'validity_days'   => ['nullable', 'integer', 'min:1', 'max:60'],
            'notes'           => ['nullable', 'string', 'max:1000'],
        ]);

        $quotationCurrency = strtoupper($validated['currency_code'] ?? $demand->currency_code);
        if (!Currency::query()->where('iso_code', $quotationCurrency)->where('is_active', true)->exists()) {
            return ApiResponse::error('عملة عرض السعر غير موجودة أو غير مفعلة', 'CURRENCY_NOT_ACTIVE', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $qty         = (float) $validated['quoted_quantity'];
        $unitPrice   = (float) $validated['unit_price'];
        $totalAmount = round($qty * $unitPrice, 4);

        $quotation = Quotation::create([
            'quotation_number'      => 'QUO-' . date('ymd') . '-' . strtoupper(Str::random(6)),
            'demand_id'             => $demand->id,
            'seller_organization_id'=> $sellerOrgId,
            'seller_user_id'        => $request->user()->id,
            'quoted_quantity'       => $qty,
            'unit_price'            => $unitPrice,
            'currency_code'         => $quotationCurrency,
            'total_amount'          => $totalAmount,
            'validity_days'         => $validated['validity_days'] ?? 7,
            'notes'                 => $validated['notes'] ?? null,
            'status'                => Quotation::STATUS_SUBMITTED,
        ]);

        return ApiResponse::success(
            $quotation->load(['sellerOrganization', 'demand']),
            'تم تقديم عرض السعر بنجاح',
            Response::HTTP_CREATED
        );
    }
}
