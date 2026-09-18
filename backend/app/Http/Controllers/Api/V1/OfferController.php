<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\Currency;
use App\Models\ProductVariant;
use App\Services\DemandMatchingService;
use App\Services\GeoDiscoveryService;
use App\Services\SystemSettingsService;
use App\Services\TenantContext;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class OfferController extends Controller
{
    public function __construct(
        protected GeoDiscoveryService    $geoService,
        protected SystemSettingsService  $settingsService,
        protected DemandMatchingService  $matchingService,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC / MARKETPLACE ENDPOINTS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Lists marketplace offers with rich filtering, searching, and pagination.
     * Public endpoint — only ACTIVE offers are visible unless authenticated
     * and requesting own offers (my_offers=1).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Offer::with(['seller', 'warehouse', 'variant.product', 'category']);

        // Authenticated seller can view their own offers in any status
        if ($request->filled('my_offers') && TenantContext::id()) {
            $query->where('seller_organization_id', TenantContext::id());
        } else {
            $query->where('status', Offer::STATUS_ACTIVE)
                  ->where('available_quantity', '>', 0)
                  ->where(function ($q) {
                      $q->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                  });
        }

        // Category filter
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        // Opportunity reason filter
        if ($request->filled('opportunity_reason')) {
            $query->where('opportunity_reason', $request->input('opportunity_reason'));
        }

        // City filter via warehouse
        if ($request->filled('city')) {
            $city = $request->input('city');
            $query->whereHas('warehouse', fn($w) => $w->where('city', 'like', "%{$city}%"));
        }

        // Price range
        if ($request->filled('min_price')) {
            $query->where('unit_price', '>=', (float) $request->input('min_price'));
        }
        if ($request->filled('max_price')) {
            $query->where('unit_price', '<=', (float) $request->input('max_price'));
        }

        // MOQ filter
        if ($request->filled('max_moq')) {
            $query->where('min_order_quantity', '<=', (float) $request->input('max_moq'));
        }

        // Currency filter
        if ($request->filled('currency_code')) {
            $query->where('currency_code', strtoupper($request->input('currency_code')));
        }

        // Delivery terms
        if ($request->filled('delivery_terms')) {
            $query->where(function ($q) use ($request) {
                $q->where('delivery_terms', $request->input('delivery_terms'))
                  ->orWhere('delivery_terms', 'BOTH');
            });
        }

        // Full-text search (title + product name + SKU)
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title_ar', 'like', "%{$search}%")
                  ->orWhere('title_en', 'like', "%{$search}%")
                  ->orWhereHas('variant.product', fn($p) =>
                      $p->where('name_ar', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                  );
            });
        }

        // Sorting
        $sortBy  = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc') === 'asc' ? 'asc' : 'desc';

        if (in_array($sortBy, ['unit_price', 'discount_percentage', 'created_at', 'expires_at', 'available_quantity'], true)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $perPage = min(50, max(1, (int) $request->input('per_page', 15)));
        $offers  = $query->paginate($perPage);

        return ApiResponse::paginated($offers, 'تم استرجاع العروض بنجاح');
    }

    /**
     * Geographical discovery: active offers within a radius of given coordinates.
     *
     * Supports all GeoDiscoveryService filters:
     *   radius_km, category_id, opportunity_reason, min_price, max_price,
     *   min_quantity, max_moq, currency_code, delivery_terms, sort_by, per_page
     */
    public function nearby(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'latitude'           => ['required', 'numeric', 'between:-90,90'],
            'longitude'          => ['required', 'numeric', 'between:-180,180'],
            'radius_km'          => ['nullable', 'numeric', 'min:1', 'max:500'],
            'category_id'        => ['nullable', 'integer', 'exists:categories,id'],
            'opportunity_reason' => ['nullable', 'string'],
            'min_price'          => ['nullable', 'numeric', 'min:0'],
            'max_price'          => ['nullable', 'numeric', 'min:0'],
            'min_quantity'       => ['nullable', 'numeric', 'min:0'],
            'max_moq'            => ['nullable', 'numeric', 'min:0'],
            'currency_code'      => ['nullable', 'string', 'size:3'],
            'delivery_terms'     => ['nullable', 'in:BUYER_PICKUP,SELLER_DELIVERY,BOTH'],
            'sort_by'            => ['nullable', 'in:distance,price,freshness'],
            'per_page'           => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $lat      = (float) $validated['latitude'];
        $lng      = (float) $validated['longitude'];
        $radius   = (float) ($validated['radius_km'] ?? $this->settingsService->get('matching.geo_radius_km', 100));
        $maxRadius = (float) $this->settingsService->get('geo.max_radius_km', 500);

        if ($radius > $maxRadius) {
            $radius = $maxRadius;
        }

        $filters = array_filter(array_intersect_key($validated, array_flip([
            'category_id', 'opportunity_reason',
            'min_price', 'max_price',
            'min_quantity', 'max_moq',
            'currency_code', 'delivery_terms',
            'sort_by', 'per_page',
        ])), fn($v) => $v !== null && $v !== '');

        $nearbyOffers = $this->geoService->findOffersNearby($lat, $lng, $radius, $filters);

        return ApiResponse::success([
            'offers'         => $nearbyOffers,
            'search_center'  => ['latitude' => $lat, 'longitude' => $lng],
            'radius_km'      => $radius,
            'total_found'    => count($nearbyOffers),
        ], 'تم استرجاع العروض القريبة بنجاح');
    }

    /**
     * Retrieves full offer details including product, seller, warehouse, and items.
     */
    public function show(int $id): JsonResponse
    {
        $offer = Offer::with([
            'seller',
            'warehouse',
            'variant.product.brand',
            'category',
            'items',
        ])->findOrFail($id);

        return ApiResponse::success($offer, 'تم استرجاع تفاصيل العرض بنجاح');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AUTHENTICATED / SELLER ENDPOINTS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Creates a new B2B opportunity offer (Seller organization).
     *
     * Lifecycle:
     *   - If platform setting `offer.requires_review` is true → PENDING_REVIEW
     *   - Otherwise → ACTIVE immediately
     *
     * After creation, the Matching Engine runs in the background to notify
     * buyers with matching open Demands.
     */
    public function store(Request $request): JsonResponse
    {
        $sellerOrgId = TenantContext::id();
        if (!$sellerOrgId) {
            return ApiResponse::error(
                'مطلوب منشأة بائع نشطة',
                'TENANT_REQUIRED',
                Response::HTTP_FORBIDDEN
            );
        }

        $validated = $request->validate([
            'warehouse_id'             => ['required', 'exists:warehouses,id'],
            'product_variant_id'       => ['required', 'exists:product_variants,id'],
            'category_id'              => ['nullable', 'exists:categories,id'],
            'title_ar'                 => ['required', 'string', 'max:255'],
            'title_en'                 => ['nullable', 'string', 'max:255'],
            'description_ar'           => ['nullable', 'string', 'max:2000'],
            'description_en'           => ['nullable', 'string', 'max:2000'],
            'opportunity_reason'       => ['required', 'string', Rule::in([
                Offer::REASON_SURPLUS_STOCK,
                Offer::REASON_SLOW_MOVING,
                Offer::REASON_SEASONAL_STOCK,
                Offer::REASON_EXCESS_INVENTORY,
                Offer::REASON_LIQUIDATION,
                Offer::REASON_BRANCH_CLOSURE,
                Offer::REASON_ASSORTMENT_CHANGE,
                Offer::REASON_LOW_LOCAL_DEMAND,
            ])],
            'opportunity_reason_notes' => ['nullable', 'string', 'max:500'],
            'min_order_quantity'       => ['required', 'numeric', 'min:1'],
            'max_order_quantity'       => ['nullable', 'numeric', 'gte:min_order_quantity'],
            'total_quantity'           => ['required', 'numeric', 'min:1', 'gte:min_order_quantity'],
            'unit'                     => ['required', 'string', 'max:50'],
            'unit_price'               => ['required', 'numeric', 'min:0.0001'],
            'market_reference_price'   => ['nullable', 'numeric', 'min:0.0001'],
            'currency_code'            => ['nullable', 'string', 'size:3'],
            'production_date'          => ['nullable', 'date'],
            'expiry_date'              => ['nullable', 'date'],
            'storage_condition'        => ['nullable', 'string', 'max:50'],
            'delivery_terms'           => ['nullable', Rule::in(['BUYER_PICKUP', 'SELLER_DELIVERY', 'BOTH'])],
            'inspection_window_hours'  => ['nullable', 'integer', 'min:1', 'max:168'],
            'expires_at'               => ['nullable', 'date', 'after:now'],
        ]);

        $variant = ProductVariant::with('product')->findOrFail($validated['product_variant_id']);

        $currencyCode = strtoupper($validated['currency_code'] ?? (string) $this->settingsService->get('platform.default_currency', 'YER'));
        $currencyIsActive = Currency::query()
            ->where('iso_code', $currencyCode)
            ->where('is_active', true)
            ->exists();

        if (!$currencyIsActive) {
            return ApiResponse::error(
                'العملة المحددة غير موجودة أو غير مفعلة',
                'CURRENCY_NOT_ACTIVE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($validated['category_id'] ?? null) {
            if ((int) $validated['category_id'] !== (int) $variant->product->category_id) {
                return ApiResponse::error(
                    'تصنيف العرض لا يطابق تصنيف المنتج',
                    'CATEGORY_PRODUCT_MISMATCH',
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        // Verify warehouse belongs to seller's organization
        $warehouseBelongsToSeller = DB::table('warehouses')
            ->where('id', $validated['warehouse_id'])
            ->where('organization_id', $sellerOrgId)
            ->exists();

        if (!$warehouseBelongsToSeller) {
            return ApiResponse::error(
                'المستودع المحدد لا ينتمي لمنشأتك',
                'WAREHOUSE_NOT_OWNED',
                Response::HTTP_FORBIDDEN
            );
        }

        $unitPrice         = (float) $validated['unit_price'];
        $refPrice          = !empty($validated['market_reference_price']) ? (float) $validated['market_reference_price'] : null;
        $discountPct       = null;

        if ($refPrice && $refPrice > $unitPrice) {
            $discountPct = round((($refPrice - $unitPrice) / $refPrice) * 100, 2);
        }

        // Determine initial status based on platform config
        $requiresReview = (bool) $this->settingsService->get('offer.requires_review', false);
        $initialStatus  = $requiresReview ? Offer::STATUS_PENDING_REVIEW : Offer::STATUS_ACTIVE;
        $publishedAt    = $requiresReview ? null : Carbon::now();

        $offer = DB::transaction(function () use (
            $validated, $sellerOrgId, $variant,
            $unitPrice, $refPrice, $discountPct,
            $initialStatus, $publishedAt, $currencyCode
        ) {
            $newOffer = Offer::create([
                'seller_organization_id'  => $sellerOrgId,
                'warehouse_id'            => $validated['warehouse_id'],
                'product_variant_id'      => $variant->id,
                'category_id'             => $validated['category_id'] ?? $variant->product->category_id,
                'title_ar'                => $validated['title_ar'],
                'title_en'                => $validated['title_en'] ?? null,
                'description_ar'          => $validated['description_ar'] ?? null,
                'description_en'          => $validated['description_en'] ?? null,
                'opportunity_reason'      => $validated['opportunity_reason'],
                'opportunity_reason_notes'=> $validated['opportunity_reason_notes'] ?? null,
                'status'                  => $initialStatus,
                'min_order_quantity'      => $validated['min_order_quantity'],
                'max_order_quantity'      => $validated['max_order_quantity'] ?? null,
                'total_quantity'          => $validated['total_quantity'],
                'available_quantity'      => $validated['total_quantity'],
                'reserved_quantity'       => 0.00,
                'sold_quantity'           => 0.00,
                'unit'                    => $validated['unit'],
                'unit_price'              => $unitPrice,
                'market_reference_price'  => $refPrice,
                'discount_percentage'     => $discountPct,
                'currency_code'           => $currencyCode,
                'production_date'         => $validated['production_date'] ?? null,
                'expiry_date'             => $validated['expiry_date'] ?? null,
                'storage_condition'       => $validated['storage_condition'] ?? 'ambient',
                'delivery_terms'          => $validated['delivery_terms'] ?? 'BOTH',
                'inspection_window_hours' => $validated['inspection_window_hours'] ?? $this->settingsService->get('inspection.window', 48),
                'published_at'            => $publishedAt,
                'expires_at'              => $validated['expires_at'] ?? null,
            ]);

            OfferItem::create([
                'offer_id'          => $newOffer->id,
                'product_id'        => $variant->product_id,
                'product_variant_id'=> $variant->id,
                'quantity'          => $validated['total_quantity'],
                'unit_price'        => $unitPrice,
                'currency_code'     => $newOffer->currency_code,
            ]);

            return $newOffer;
        });

        // Proactively run matching against open Demands (non-blocking best-effort)
        if ($offer->status === Offer::STATUS_ACTIVE) {
            try {
                $this->matchingService->matchOffer($offer);
            } catch (Exception $e) {
                Log::error('Offer matching failed after offer creation', [
                    'offer_id' => $offer->id,
                    'exception' => $e,
                ]);
            }
        }

        $message = $requiresReview
            ? 'تم تقديم العرض وهو الآن قيد المراجعة'
            : 'تم نشر العرض بنجاح';

        return ApiResponse::success(
            $offer->load(['seller', 'warehouse', 'variant.product']),
            $message,
            Response::HTTP_CREATED
        );
    }

    /**
     * Updates the status of an existing offer (pause, activate, cancel, archive).
     * Only the owning seller organization may change status.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $sellerOrgId = TenantContext::id();
        if (!$sellerOrgId) {
            return ApiResponse::error('مطلوب منشأة بائع نشطة', 'TENANT_REQUIRED', Response::HTTP_FORBIDDEN);
        }

        $offer = Offer::find($id);
        if (!$offer || (int) $offer->seller_organization_id !== (int) $sellerOrgId) {
            return ApiResponse::error(
                'غير مصرح لك بتعديل هذا العرض',
                'UNAUTHORIZED_OFFER_ACTION',
                Response::HTTP_FORBIDDEN
            );
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['ACTIVE', 'PAUSED', 'CANCELLED', 'ARCHIVED'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        // Enforce valid transitions
        $allowedTransitions = [
            Offer::STATUS_ACTIVE          => ['PAUSED', 'CANCELLED'],
            Offer::STATUS_PAUSED          => ['ACTIVE', 'CANCELLED'],
            Offer::STATUS_PENDING_REVIEW  => ['CANCELLED'],
            Offer::STATUS_DRAFT           => ['ACTIVE', 'CANCELLED'],
            Offer::STATUS_FULLY_RESERVED  => ['PAUSED', 'CANCELLED'],
        ];

        $currentStatus = $offer->status;
        $toStatus      = $validated['status'];

        if (!in_array($toStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
            return ApiResponse::error(
                "لا يمكن تغيير الحالة من '{$currentStatus}' إلى '{$toStatus}'",
                'INVALID_OFFER_TRANSITION',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $offer->update([
            'status' => $toStatus,
            // Restore published_at if reactivating
            'published_at' => ($toStatus === 'ACTIVE' && !$offer->published_at) ? Carbon::now() : $offer->published_at,
        ]);

        return ApiResponse::success($offer->fresh(), 'تم تحديث حالة العرض بنجاح');
    }
}
