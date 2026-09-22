<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OfferResource;
use App\Http\Responses\ApiResponse;
use App\Models\Offer;
use App\Models\Deal;
use App\Models\OfferComment;
use App\Models\OfferItem;
use App\Models\Currency;
use App\Models\ProductVariant;
use App\Services\DemandMatchingService;
use App\Services\GeoDiscoveryService;
use App\Services\SystemSettingsService;
use App\Services\TenantContext;
use App\Support\WaitingTime;
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
    /**
     * Memo for the moderation queue's seller-history counts.
     *
     * A controller instance is built per request, so this is request-scoped
     * caching — a page of listings from one seller counts that seller once —
     * and nothing survives the response.
     */
    private array $moderationHistoryCache = [];

    public function __construct(
        protected GeoDiscoveryService    $geoService,
        protected SystemSettingsService  $settingsService,
        protected DemandMatchingService  $matchingService,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC / MARKETPLACE ENDPOINTS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Public marketplace browsing.
     *
     * Registered outside the authenticated group, so nothing here may depend on
     * who is asking: a `my_offers` or moderation branch added to this method
     * would silently never run, and a caller would be shown the published list
     * while believing they were shown their own. Sellers and moderators get
     * `mine()` and `moderationQueue()` below, which are authenticated and
     * permission-gated by route middleware.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->baseQuery();

        // Published, in stock, not past its own expiry date, and offered by a
        // business the platform has not withdrawn. No caller may widen this
        // from the request.
        $query->tradable()
              ->where('status', Offer::STATUS_ACTIVE)
              ->where('available_quantity', '>', 0)
              ->where(function ($q) {
                  $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
              });

        $this->applyFilters($query, $request);

        return $this->paginateOffers($query, $request);
    }

    /**
     * The seller's own shelf: their organization's offers in every status.
     *
     * Scoped by the resolved tenant, not by a parameter, so a request cannot ask
     * for another business's drafts.
     */
    public function mine(Request $request): JsonResponse
    {
        $orgId = TenantContext::id();
        if (!$orgId) {
            return ApiResponse::error(
                'مطلوب منشأة نشطة',
                'TENANT_REQUIRED',
                Response::HTTP_FORBIDDEN
            );
        }

        $query = $this->baseQuery()->where('seller_organization_id', $orgId);

        if ($request->filled('status')) {
            $validated = $request->validate(['status' => ['required', Rule::in(Offer::STATUSES)]]);
            $query->where('status', $validated['status']);
        }

        $this->applyFilters($query, $request);

        return $this->paginateOffers($query, $request);
    }

    /**
     * The moderation queue.
     *
     * Reaching another business's unpublished offer is a platform act, so this
     * lives behind `offers.moderate` at the route, not behind a parameter here.
     * Defaults to what is actually waiting on a reviewer.
     *
     * A reviewer deciding on a listing is deciding on the business behind it, so
     * each row carries that business: the legal numbers it filed, the papers
     * filed against them and what staff concluded, its record on the platform so
     * far, and the photographs attached to the goods. Before this, the queue
     * showed a title and a price, and the reviewer had to open three other
     * screens — if they knew to — to see anything else.
     */
    public function moderationQueue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(Offer::STATUSES)],
        ]);

        $status = $validated['status'] ?? Offer::STATUS_PENDING_REVIEW;

        $query = $this->baseQuery()
            ->with(['seller.documents.reviewer:id,name', 'seller.members:id,name', 'variant.product.images'])
            ->where('status', $status);

        $this->applyFilters($query, $request);

        return $this->paginateOffers($query, $request, fn (Offer $offer) => [
            'moderation' => $this->moderationContext($offer),
        ]);
    }

    /**
     * What a reviewer needs that is not on the offer itself.
     *
     * @return array<string, mixed>
     */
    private function moderationContext(Offer $offer): array
    {
        $seller = $offer->seller;
        $documents = $seller?->documents ?? collect();

        return [
            'seller_legal_record' => $seller ? [
                'organization_id'  => $seller->id,
                'name_ar'          => $seller->name_ar,
                'type'             => $seller->type,
                'country_code'     => $seller->country_code,
                'city'             => $seller->city,
                'platform_status'  => $seller->status,
                'registration_no'  => $seller->commercial_registration_number,
                'tax_no'           => $seller->vat_number,
                // When this platform's own staff accepted the business's papers.
                // No government register is consulted by this codebase, so this
                // field is a record of an internal decision and nothing more.
                'reviewed_at'      => $seller->kyc_verified_at,
                'website'          => $seller->website,
                'phone'            => $seller->phone,
                'member_count'     => $seller->members?->count() ?? 0,
            ] : null,

            'documents' => $documents->map(fn ($document) => [
                'id'            => $document->id,
                'type'          => $document->type,
                'status'        => $document->status,
                'reference_number' => $document->reference_number,
                'original_name' => $document->original_name,
                'url'           => $document->url(),
                'uploaded_at'   => $document->created_at,
                'reviewed_at'   => $document->reviewed_at,
                'reviewed_by'   => $document->reviewer?->name,
                'review_reason' => $document->review_reason,
            ])->values()->all(),

            'seller_history' => $seller ? $this->sellerHistory((int) $seller->id) : null,

            // The offers table has no `submitted_at`, so this is the row's last
            // write — an upper bound on how long it has waited, and labelled as
            // one rather than presented as a submission timestamp.
            'waiting_since'     => $offer->updated_at,
            'waiting_hours'     => WaitingTime::hoursSince($offer->updated_at),
            'image_count'       => $offer->variant?->product?->images?->count() ?? 0,
            'warehouse'         => $offer->warehouse ? [
                'id'    => $offer->warehouse->id,
                'name'  => $offer->warehouse->name,
                'city'  => $offer->warehouse->city,
                'code'  => $offer->warehouse->code,
                'lat'   => $offer->warehouse->latitude,
                'lon'   => $offer->warehouse->longitude,
            ] : null,
        ];
    }

    /**
     * The business's record on the platform, counted from the rows that exist.
     *
     * Cached per request and per organization because a page of listings from
     * the same seller must not run the same four counts once per row.
     *
     * @return array<string, int>
     */
    private function sellerHistory(int $organizationId): array
    {
        if (isset($this->moderationHistoryCache[$organizationId])) {
            return $this->moderationHistoryCache[$organizationId];
        }

        return $this->moderationHistoryCache[$organizationId] = [
            'offers_total'   => Offer::query()->where('seller_organization_id', $organizationId)->count(),
            'offers_active'  => Offer::query()->where('seller_organization_id', $organizationId)->where('status', Offer::STATUS_ACTIVE)->count(),
            'offers_expired' => Offer::query()->where('seller_organization_id', $organizationId)->where('status', Offer::STATUS_EXPIRED)->count(),
            'deals_total'    => Deal::query()->where('seller_organization_id', $organizationId)->count(),
            'deals_settled'  => Deal::query()->where('seller_organization_id', $organizationId)->where('status', Deal::STATUS_SETTLED)->count(),
            'deals_disputed' => Deal::query()->where('seller_organization_id', $organizationId)->where('status', Deal::STATUS_DISPUTED)->count(),
        ];
    }

    protected function baseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Offer::with(['seller', 'warehouse', 'variant.product.brand', 'variant.product.images', 'category'])
            // Both figures are counted from their rows on every read. Nothing in
            // this schema stores an engagement total, so a listing cannot
            // display a number that outlived the evidence behind it.
            ->withCount('likes')
            // …and the comment count is only ever the published thread: a
            // question held for review is not public information, and counting
            // it would advertise that something exists without showing it.
            ->withCount(['comments as published_comments_count' => fn ($q) => $q->where('status', OfferComment::STATUS_PUBLISHED)]);
    }

    /**
     * Shared browsing filters. Deliberately contains no visibility rule —
     * which rows a caller may see is decided by the calling method.
     */
    protected function applyFilters($query, Request $request): void
    {
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
    }

    /**
     * Sorting and paging, shared so the three listings order identically.
     *
     * `$decorate` lets one listing (the moderation queue) attach reviewer
     * context without forking a second copy of the ordering and paging rules
     * that would drift from this one.
     *
     * @param  (callable(Offer): array<string, mixed>)|null  $decorate
     */
    protected function paginateOffers($query, Request $request, ?callable $decorate = null): JsonResponse
    {
        // Sorting. The client names an intent (`newest`, `cheapest`); the
        // column it means lives here, in one place, so a UI rename cannot
        // quietly turn ordering into "unrecognised, use created_at".
        $intentColumns = [
            'newest'     => ['created_at', 'desc'],
            'cheapest'   => ['unit_price', 'asc'],
            'priciest'   => ['unit_price', 'desc'],
            'expiring'   => ['expires_at', 'asc'],
            'quantity'   => ['available_quantity', 'desc'],
            'nearest_to_expiry' => ['expires_at', 'asc'],
        ];
        $sortableColumns = ['unit_price', 'created_at', 'expires_at', 'available_quantity'];

        $sortBy  = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc') === 'asc' ? 'asc' : 'desc';

        if (isset($intentColumns[$sortBy])) {
            [$column, $direction] = $intentColumns[$sortBy];
            $query->orderBy($column, $direction);
        } elseif (in_array($sortBy, $sortableColumns, true)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $perPage = min(50, max(1, (int) $request->input('per_page', 15)));
        $offers  = $query->paginate($perPage)->through(function (Offer $offer) use ($decorate, $request) {
            // Resolved here rather than handed over as a resource with
            // `additional()`: this envelope encodes the items itself
            // (`ApiResponse::paginated`), and a resource's additional data is
            // only merged when that resource builds its own response — so a
            // decorated key would reach the client as nothing at all. The
            // moderation queue is exactly that case, and the reviewer's dossier
            // is the whole reason it exists.
            $data = OfferResource::make($offer)->resolve($request);

            return $decorate ? array_merge($data, $decorate($offer)) : $data;
        });

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
    public function show(Request $request, int $id): JsonResponse
    {
        $offer = Offer::with([
            'seller',
            'warehouse',
            'variant.product.brand',
            'variant.product.images',
            'category',
            'items',
        ])
            ->withCount('likes')
            ->withCount(['comments as published_comments_count' => fn ($q) => $q->where('status', OfferComment::STATUS_PUBLISHED)])
            ->findOrFail($id);

        // A draft, a listing under review, or one the seller paused is the
        // seller's own business: its price and quantity are not HASSAL's to
        // publish. 404 rather than 403, because "this offer exists but you
        // cannot see it" is itself a leak when ids are sequential.
        //
        // This route is public, so the auth and tenant middleware have not run
        // and `$request->user()` would be null even for a caller holding a
        // valid token — the owner would be refused their own listing. The
        // bearer identity is resolved explicitly instead.
        $viewer = $request->user('sanctum');
        $isOwner = $viewer
            ? $viewer->memberships()
                ->where('organization_id', $offer->seller_organization_id)
                ->where('is_active', true)
                ->exists()
            : false;

        if (!$isOwner && (!$offer->isPubliclyVisible() || !$offer->sellerMayTrade())) {
            return ApiResponse::error(
                'العرض غير متوفر',
                'NOT_FOUND',
                Response::HTTP_NOT_FOUND
            );
        }

        return ApiResponse::success(
            OfferResource::make($offer)->resolve($request),
            'تم استرجاع تفاصيل العرض بنجاح'
        );
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
            'opportunity_reason'       => ['required', Rule::in(self::opportunityReasons())],
            'as_draft'                 => ['sometimes', 'boolean'],
            'opportunity_reason_notes' => ['nullable', 'string', 'max:500'],
            'min_order_quantity'       => ['required', 'numeric', 'min:1'],
            'max_order_quantity'       => ['nullable', 'numeric', 'gte:min_order_quantity'],
            'total_quantity'           => ['required', 'numeric', 'min:1', 'gte:min_order_quantity'],
            'unit'                     => ['required', 'string', 'max:50'],
            'unit_price'               => ['required', 'numeric', 'min:0.0001'],
            'currency_code'            => ['nullable', 'string', 'size:3'],
            'production_date'          => ['nullable', 'date'],
            'expiry_date'              => ['nullable', 'date'],
            'storage_condition'        => ['nullable', 'string', 'max:50'],
            'delivery_terms'           => ['nullable', Rule::in(['BUYER_PICKUP', 'SELLER_DELIVERY', 'BOTH'])],
            'inspection_window_hours'  => ['nullable', 'integer', 'min:1', 'max:168'],
            'expires_at'               => ['nullable', 'date', 'after:now'],
            // Absent means "show", which is how every listing behaved before the
            // column existed; an explicit false is the only way to hide.
            'show_seller_identity'     => ['nullable', 'boolean'],
        ]);

        $variant = ProductVariant::with('product')->findOrFail($validated['product_variant_id']);

        $currencyCode = strtoupper($validated['currency_code'] ?? (string) $this->settingsService->get('platform.default_currency'));
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

        // The same question the warehouse asks, asked of the goods: an offer
        // declares what this business is selling, so it cannot be allowed to
        // trade under another business' catalog entry. An unowned product is a
        // platform reference entry and is open to everyone.
        $variantOwnerId = $variant->product?->organization_id;

        if ($variantOwnerId !== null && (int) $variantOwnerId !== (int) $sellerOrgId) {
            return ApiResponse::error(
                'الصنف المحدد لا ينتمي لمنشأتك',
                'VARIANT_NOT_OWNED',
                Response::HTTP_FORBIDDEN
            );
        }

        $unitPrice = (float) $validated['unit_price'];

        // Determine initial status based on platform config
        $requiresReview = (bool) $this->settingsService->get('offer.requires_review', false);
        $asDraft        = (bool) ($validated['as_draft'] ?? false);

        // A draft is the seller's own workspace: it is not awaiting review and it
        // is not published, because it has not been offered yet.
        $initialStatus = $asDraft
            ? Offer::STATUS_DRAFT
            : ($requiresReview ? Offer::STATUS_PENDING_REVIEW : Offer::STATUS_ACTIVE);
        $publishedAt = ($asDraft || $requiresReview) ? null : Carbon::now();

        $offer = DB::transaction(function () use (
            $validated, $sellerOrgId, $variant,
            $unitPrice, $initialStatus, $publishedAt, $currencyCode
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
                'currency_code'           => $currencyCode,
                'production_date'         => $validated['production_date'] ?? null,
                'expiry_date'             => $validated['expiry_date'] ?? null,
                'storage_condition'       => $validated['storage_condition'] ?? 'ambient',
                'delivery_terms'          => $validated['delivery_terms'] ?? 'BOTH',
                'inspection_window_hours' => $validated['inspection_window_hours'] ?? $this->settingsService->get('inspection.window', 48),
                'published_at'            => $publishedAt,
                'expires_at'              => $validated['expires_at'] ?? null,
                'show_seller_identity'    => (bool) ($validated['show_seller_identity'] ?? true),
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

        $message = match ($initialStatus) {
            Offer::STATUS_DRAFT => 'تم حفظ العرض كمسودة',
            Offer::STATUS_PENDING_REVIEW => 'تم تقديم العرض وهو الآن قيد المراجعة',
            default => 'تم نشر العرض بنجاح',
        };

        return ApiResponse::success(
            $offer->load(['seller', 'warehouse', 'variant.product']),
            $message,
            Response::HTTP_CREATED
        );
    }

    /**
     * Edit a listing the seller still controls.
     *
     * Deliberately narrow:
     *   * Only DRAFT and PAUSED offers are editable. Rewriting the price of an
     *     offer buyers are looking at is how a marketplace earns disputes, so
     *     the seller pauses first — which also puts the withdrawal in the audit
     *     trail instead of leaving it invisible.
     *   * The product variant and warehouse are not editable. They are what the
     *     offer *is*: matching, location search and the deal snapshot all key off
     *     them. A new product or a different warehouse is a new offer.
     *   * `total_quantity` may only change while nothing has been reserved or
     *     sold, so the row cannot end up claiming more stock than it has
     *     already committed — the invariant the CHECK constraint enforces.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $offer = $this->ownedOffer($id);
        if ($offer instanceof JsonResponse) {
            return $offer;
        }

        if (!in_array($offer->status, [Offer::STATUS_DRAFT, Offer::STATUS_PAUSED], true)) {
            return ApiResponse::error(
                'لا يمكن تعديل العرض إلا وهو مسودة أو متوقف; أوقف النشر أولاً',
                'OFFER_NOT_EDITABLE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $validated = $request->validate([
            'title_ar'                 => ['sometimes', 'required', 'string', 'max:255'],
            'title_en'                 => ['sometimes', 'nullable', 'string', 'max:255'],
            'description_ar'           => ['sometimes', 'nullable', 'string', 'max:2000'],
            'description_en'           => ['sometimes', 'nullable', 'string', 'max:2000'],
            'opportunity_reason'       => ['sometimes', 'required', Rule::in(self::opportunityReasons())],
            'opportunity_reason_notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'min_order_quantity'       => ['sometimes', 'required', 'numeric', 'min:1'],
            'max_order_quantity'       => ['sometimes', 'nullable', 'numeric', 'min:1'],
            'total_quantity'           => ['sometimes', 'required', 'numeric', 'min:1'],
            'unit'                     => ['sometimes', 'required', 'string', 'max:50'],
            'unit_price'               => ['sometimes', 'required', 'numeric', 'min:0.0001'],
            'production_date'          => ['sometimes', 'nullable', 'date'],
            'expiry_date'              => ['sometimes', 'nullable', 'date'],
            'storage_condition'        => ['sometimes', 'nullable', 'string', 'max:50'],
            'delivery_terms'           => ['sometimes', Rule::in(['BUYER_PICKUP', 'SELLER_DELIVERY', 'BOTH'])],
            'inspection_window_hours'  => ['sometimes', 'nullable', 'integer', 'min:1', 'max:168'],
            'expires_at'               => ['sometimes', 'nullable', 'date', 'after:now'],
            'show_seller_identity'     => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['total_quantity'])) {
            $committed = (float) $offer->reserved_quantity + (float) $offer->sold_quantity;

            if ($committed > 0.0001) {
                return ApiResponse::error(
                    'الكمية المعلن عنها لا يمكن تعديلها بعد حجز أو بيع جزء من العرض',
                    'OFFER_QUANTITY_COMMITTED',
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        // The quantity rules are about the offer as a whole, and a client may
        // legitimately send only one of these fields, so they are compared
        // against the stored values for whatever was not sent. A cross-field
        // validator rule would have demanded all three on every edit.
        $minOrder  = (float) ($validated['min_order_quantity'] ?? $offer->min_order_quantity);
        $maxOrder  = array_key_exists('max_order_quantity', $validated)
            ? ($validated['max_order_quantity'] === null ? null : (float) $validated['max_order_quantity'])
            : ($offer->max_order_quantity === null ? null : (float) $offer->max_order_quantity);
        $total     = (float) ($validated['total_quantity'] ?? $offer->total_quantity);
        $available = (float) $offer->available_quantity;

        $fieldErrors = [];

        if ($total < $minOrder) {
            $fieldErrors['total_quantity'][] = 'الكمية الإجمالية أقل من الحد الأدنى للطلب';
        }

        if ($maxOrder !== null && $maxOrder < $minOrder) {
            $fieldErrors['max_order_quantity'][] = 'الحد الأقصى للطلب أقل من الحد الأدنى';
        }

        if ($minOrder > $available) {
            $fieldErrors['min_order_quantity'][] = 'الحد الأدنى للطلب أكبر من الكمية المتاحة';
        }

        if ($fieldErrors !== []) {
            return ApiResponse::error('Validation failed', 'VALIDATION_FAILED', Response::HTTP_UNPROCESSABLE_ENTITY, $fieldErrors);
        }

        DB::transaction(function () use ($offer, $validated) {
            if (array_key_exists('total_quantity', $validated)) {
                $validated['available_quantity'] = (float) $validated['total_quantity'];
            }

            $offer->update($validated);

            // The line items describe what is inside this offer, so they follow
            // the offer's own price and quantity rather than drifting from them.
            OfferItem::updateOrCreate(
                ['offer_id' => $offer->id, 'product_variant_id' => $offer->product_variant_id],
                [
                    'product_id'    => $offer->variant->product_id,
                    'quantity'      => $offer->total_quantity,
                    'unit_price'    => $offer->unit_price,
                    'currency_code' => $offer->currency_code,
                ]
            );
        });

        return ApiResponse::success($offer->fresh(), 'تم تحديث العرض');
    }

    /**
     * Put a draft in front of the platform (or straight on the shelf).
     *
     * This is the step that makes a returned offer recoverable. Moderation can
     * only work if RETURN sends the listing back to a state the seller can edit
     * and resubmit; without it, `review()` would be able to approve but never
     * to reject without destroying the seller's work.
     */
    public function submit(Request $request, int $id): JsonResponse
    {
        $offer = $this->ownedOffer($id);
        if ($offer instanceof JsonResponse) {
            return $offer;
        }

        if ($offer->status !== Offer::STATUS_DRAFT) {
            return ApiResponse::error(
                'المسودات فقط يمكن تقديمها للنشر',
                'OFFER_NOT_DRAFT',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ((float) $offer->available_quantity <= 0.0001) {
            return ApiResponse::error(
                'لا يمكن عرض كمية غير متاحة للبيع',
                'OFFER_HAS_NO_STOCK',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $requiresReview = (bool) $this->settingsService->get('offer.requires_review', false);

        $offer->update([
            'status'       => $requiresReview ? Offer::STATUS_PENDING_REVIEW : Offer::STATUS_ACTIVE,
            'published_at' => $requiresReview ? $offer->published_at : ($offer->published_at ?? Carbon::now()),
        ]);

        if (!$requiresReview) {
            try {
                $this->matchingService->matchOffer($offer);
            } catch (Exception $e) {
                Log::error('Offer matching failed after submission', [
                    'offer_id' => $offer->id,
                    'exception' => $e,
                ]);
            }
        }

        return ApiResponse::success(
            $offer->fresh(),
            $requiresReview ? 'تم تقديم العرض للمراجعة' : 'تم نشر العرض بنجاح'
        );
    }

    /**
     * Discard a draft.
     *
     * Only a DRAFT can be deleted, and only by its seller. Once an offer has
     * been seen by the market it is history, not a scratch note: withdrawing a
     * published listing is CANCELLED through `updateStatus()`, which keeps the
     * row and the audit trail that buyers and disputes may still refer to.
     */
    public function destroy(int $id): JsonResponse
    {
        $offer = $this->ownedOffer($id);
        if ($offer instanceof JsonResponse) {
            return $offer;
        }

        if ($offer->status !== Offer::STATUS_DRAFT) {
            return ApiResponse::error(
                'المسودات فقط يمكن حذفها; اسحب العرض المنشور بدل حذفه',
                'OFFER_NOT_DELETABLE',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $offer->delete();

        return ApiResponse::success(['id' => $offer->id], 'تم حذف المسودة');
    }

    /**
     * Load an offer and confirm the organization in context owns it.
     *
     * Ownership is answered here rather than per action because every seller
     * write must ask it, and the first version of this controller asked it
     * differently in each method — which is how one of them ended up not asking.
     */
    protected function ownedOffer(int $id): Offer|JsonResponse
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

        return $offer;
    }

    /**
     * @return array<int, string>
     */
    protected static function opportunityReasons(): array
    {
        return [
            Offer::REASON_SURPLUS_STOCK,
            Offer::REASON_SLOW_MOVING,
            Offer::REASON_SEASONAL_STOCK,
            Offer::REASON_EXCESS_INVENTORY,
            Offer::REASON_LIQUIDATION,
            Offer::REASON_BRANCH_CLOSURE,
            Offer::REASON_ASSORTMENT_CHANGE,
            Offer::REASON_LOW_LOCAL_DEMAND,
        ];
    }

    /**
     * The seller's own control over an offer they published.
     *
     * Deliberately absent: `ACTIVE` from `PENDING_REVIEW`. A seller cannot
     * approve their own moderation queue — that decision is `review()` below.
     *
     * The route gates this on `offers.publish`, which covers pausing and
     * resuming. Cancelling is withdrawn-only under `offers.cancel`: a staff
     * account that may tidy the shelf must not be able to erase a listing the
     * business is still trading under.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $offer = $this->ownedOffer($id);
        if ($offer instanceof JsonResponse) {
            return $offer;
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in([Offer::STATUS_ACTIVE, Offer::STATUS_PAUSED, Offer::STATUS_CANCELLED])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validated['status'] === Offer::STATUS_CANCELLED && !$request->user()->can('offers.cancel')) {
            return ApiResponse::error(
                'سحب العرض المنشور يتطلب صلاحية إلغاء العروض',
                'PERMISSION_DENIED',
                Response::HTTP_FORBIDDEN,
                ['required_permission' => 'offers.cancel']
            );
        }

        $allowedTransitions = [
            Offer::STATUS_DRAFT          => [Offer::STATUS_CANCELLED],
            Offer::STATUS_PENDING_REVIEW => [Offer::STATUS_CANCELLED],
            Offer::STATUS_ACTIVE         => [Offer::STATUS_PAUSED, Offer::STATUS_CANCELLED],
            Offer::STATUS_PAUSED         => [Offer::STATUS_ACTIVE, Offer::STATUS_CANCELLED],
            // Sold out is the shelf telling the buyer the truth. The seller can
            // still withdraw it, and restocking is a new offer, not a status.
            Offer::STATUS_SOLD_OUT       => [Offer::STATUS_CANCELLED],
            Offer::STATUS_EXPIRED        => [],
            Offer::STATUS_CANCELLED      => [],
        ];

        $currentStatus = $offer->status;
        $toStatus      = $validated['status'];

        if (!in_array($toStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
            return ApiResponse::error(
                "لا يمكن تغيير حالة العرض من '{$currentStatus}' إلى '{$toStatus}'",
                'INVALID_OFFER_TRANSITION',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $offer->update([
            'status' => $toStatus,
            // Restore published_at if reactivating
            'published_at' => ($toStatus === Offer::STATUS_ACTIVE && !$offer->published_at) ? Carbon::now() : $offer->published_at,
        ]);

        return ApiResponse::success($offer->fresh(), 'تم تحديث حالة العرض بنجاح');
    }

    /**
     * The platform's moderation decision on a submitted offer.
     *
     * Review is meaningless if the only way out of PENDING_REVIEW is for the
     * seller to publish themselves, so this is a separate action with a
     * separate gate: it approves, which publishes the offer, or returns it to
     * DRAFT with a reason the seller can act on. It never edits the offer's
     * commercial terms — the platform moderates, it does not sell.
     */
    public function review(Request $request, int $id): JsonResponse
    {
        $offer = Offer::findOrFail($id);

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['APPROVE', 'RETURN'])],
            'reason' => ['required_if:decision,RETURN', 'nullable', 'string', 'max:500'],
        ]);

        if ($offer->status !== Offer::STATUS_PENDING_REVIEW) {
            return ApiResponse::error(
                "Only an offer awaiting review can be moderated (current: {$offer->status}).",
                'OFFER_NOT_AWAITING_REVIEW',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($validated['decision'] === 'APPROVE') {
            $offer->update([
                'status' => Offer::STATUS_ACTIVE,
                'published_at' => $offer->published_at ?? Carbon::now(),
            ]);

            // Publishing is the moment this offer becomes a commercial fact:
            // only now does it enter the matching engine.
            try {
                $this->matchingService->matchOffer($offer);
            } catch (Exception $e) {
                Log::error('Offer matching failed after approval', [
                    'offer_id' => $offer->id,
                    'exception' => $e,
                ]);
            }

            return ApiResponse::success($offer->fresh(), 'Offer approved and published');
        }

        $offer->update([
            'status' => Offer::STATUS_DRAFT,
            'metadata' => array_merge($offer->metadata ?? [], [
                'returned_reason' => $validated['reason'],
                'returned_at' => Carbon::now()->toIso8601String(),
            ]),
        ]);

        return ApiResponse::success($offer->fresh(), 'Offer returned to the seller for correction');
    }
}
