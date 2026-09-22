<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Deal;
use App\Models\DealItem;
use App\Models\Offer;
use App\Models\PurchaseRequest;
use App\Models\Reservation;
use App\Services\DealStateMachineService;
use App\Services\PricingAndCurrencyService;
use App\Services\TenantContext;
use App\Services\SystemSettingsService;
use App\Services\Notifications\NotificationManager;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class DealController extends Controller
{
    public function __construct(
        protected DealStateMachineService $stateMachine,
        protected PricingAndCurrencyService $pricingService,
        protected SystemSettingsService $settingsService,
        protected NotificationManager $notificationManager
    ) {}

    /**
     * Lists deals for the active organization (as buyer or seller).
     */
    public function index(Request $request): JsonResponse
    {
        $orgId = TenantContext::id();
        $user = $request->user();

        $query = Deal::with(['buyerOrganization', 'sellerOrganization', 'offer.variant.product', 'warehouse', 'escrow']);

        if (!$user->hasRole('SuperAdmin')) {
            if (!$orgId) {
                return ApiResponse::error('Active organization required', 'TENANT_REQUIRED', Response::HTTP_FORBIDDEN);
            }

            $type = $request->input('perspective', 'all'); // buyer, seller, all
            if ($type === 'buyer') {
                $query->where('buyer_organization_id', $orgId);
            } elseif ($type === 'seller') {
                $query->where('seller_organization_id', $orgId);
            } else {
                $query->where(function ($q) use ($orgId) {
                    $q->where('buyer_organization_id', $orgId)
                      ->orWhere('seller_organization_id', $orgId);
                });
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('deal_number', 'like', "%{$search}%")
                  ->orWhereHas('offer', function ($o) use ($search) {
                      $o->where('title_ar', 'like', "%{$search}%")
                        ->orWhere('title_en', 'like', "%{$search}%");
                  });
            });
        }

        $deals = $query->orderBy('created_at', 'desc')->paginate(20);

        return ApiResponse::paginated($deals, 'Deals retrieved successfully');
    }

    /**
     * Creates a new deal directly from an active reservation.
     */
    public function store(Request $request): JsonResponse
    {
        $buyerOrgId = TenantContext::id();
        if (!$buyerOrgId) {
            return ApiResponse::error('Active buyer organization required', 'TENANT_REQUIRED', Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'reservation_id' => ['required', 'exists:reservations,id'],
            'delivery_method' => ['required', 'in:PICKUP,SELLER_DELIVERY,THIRD_PARTY_CARRIER'],
            'delivery_address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $reservation = Reservation::where('id', $validated['reservation_id'])
            ->where('buyer_organization_id', $buyerOrgId)
            ->where('status', Reservation::STATUS_ACTIVE)
            ->with(['offer.variant.product'])
            ->firstOrFail();

        if ($reservation->isExpired()) {
            return ApiResponse::error('Reservation has expired. Please reserve the inventory again.', 'RESERVATION_EXPIRED', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $offer = $reservation->offer;
        $qty = (float)$reservation->quantity;
        $unitPrice = (float)$reservation->unit_price;

        // The seller's acceptance is what put this stock on hold, so the ask
        // behind the reservation — when there was one — is the deal's origin.
        $purchaseRequest = PurchaseRequest::where('reservation_id', $reservation->id)->first();

        // Pricing snapshots
        $pricing = $this->pricingService->calculateDealPricing($qty, $unitPrice, $offer->currency_code);

        try {
            $deal = DB::transaction(function () use ($reservation, $offer, $buyerOrgId, $request, $validated, $pricing, $qty, $unitPrice, $purchaseRequest) {
                $dealNumber = 'DEAL-' . date('ymd') . '-' . strtoupper(Str::random(4));

                $newDeal = Deal::create([
                    'deal_number' => $dealNumber,
                    'offer_id' => $offer->id,
                    'reservation_id' => $reservation->id,
                    'purchase_request_id' => $purchaseRequest?->id,
                    'buyer_organization_id' => $buyerOrgId,
                    'seller_organization_id' => $offer->seller_organization_id,
                    'buyer_user_id' => $request->user()->id,
                    'warehouse_id' => $offer->warehouse_id,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'currency_code' => $offer->currency_code,
                    'transaction_currency' => $pricing['transaction_currency'],
                    'base_currency' => $pricing['base_currency'],
                    'exchange_rate' => $pricing['exchange_rate'],
                    'exchange_rate_snapshot' => $pricing['exchange_rate_snapshot'],
                    'rate_source' => $pricing['rate_source'],
                    'rate_timestamp' => $pricing['rate_timestamp'],
                    'base_amount' => $pricing['base_amount'],
                    'discount_amount' => $pricing['discount_amount'],
                    'tax_rate_snapshot' => $pricing['tax_rate_snapshot'],
                    'tax_amount' => $pricing['tax_amount'],
                    'platform_fee_rate_snapshot' => $pricing['platform_fee_rate_snapshot'],
                    'platform_fee_amount' => $pricing['platform_fee_amount'],
                    'delivery_fee_amount' => $pricing['delivery_fee_amount'],
                    'buyer_total_amount' => $pricing['buyer_total_amount'],
                    'seller_net_amount' => $pricing['seller_net_amount'],
                    'status' => Deal::STATUS_PENDING_SELLER_APPROVAL,
                    'delivery_method' => $validated['delivery_method'],
                    'delivery_address' => $validated['delivery_address'] ?? null,
                    'inspection_window_hours' => $offer->inspection_window_hours ?? $this->settingsService->get('inspection.window', 48),
                ]);

                // Create Deal Item Snapshot
                DealItem::create([
                    'deal_id' => $newDeal->id,
                    'product_variant_id' => $offer->product_variant_id,
                    'snapshot_product_name' => $offer->variant->product->name_ar,
                    'snapshot_sku' => $offer->variant->sku,
                    'snapshot_unit_name' => $offer->unit,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'subtotal_amount' => $pricing['base_amount'],
                ]);

                // Mark reservation as converted to deal
                $reservation->update([
                    'status' => Reservation::STATUS_CONVERTED_TO_DEAL,
                    'converted_at' => Carbon::now(),
                ]);

                return $newDeal;
            });

            $deal->load(['buyerOrganization', 'sellerOrganization', 'offer.seller']);
            if ($deal->offer?->seller) {
                $this->notificationManager->notifyOrganization(
                    $deal->offer->seller,
                    'صفقة جديدة بانتظار المراجعة',
                    "تم إنشاء الصفقة {$deal->deal_number} وتنتظر موافقة البائع.",
                    ['type' => 'DEAL', 'deal_id' => $deal->id, 'organization_id' => $deal->seller_organization_id]
                );
            }

            $this->notificationManager->notify(
                $request->user(),
                'تم إنشاء الصفقة',
                "تم إنشاء الصفقة {$deal->deal_number} بنجاح.",
                ['type' => 'DEAL', 'deal_id' => $deal->id, 'organization_id' => $deal->buyer_organization_id]
            );

            return ApiResponse::success(
                $deal->load(['buyerOrganization', 'sellerOrganization', 'offer.variant.product', 'warehouse', 'items']),
                'Deal created successfully. Waiting for seller confirmation.',
                Response::HTTP_CREATED
            );
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), 'DEAL_CREATION_FAILED', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * Retrieves full deal details, item snapshot, status timeline, and escrow status.
     */
    public function show(int $id): JsonResponse
    {
        $orgId = TenantContext::id();
        $user = request()->user();

        $query = Deal::with([
            'buyerOrganization',
            'sellerOrganization',
            'buyerUser',
            'sellerUser',
            'warehouse',
            'offer.variant.product.brand',
            'items',
            'statusHistories.changedByUser',
            'escrow',
            'payments',
            'dispute',
        ])->whereKey($id);

        if (!$user->hasRole('SuperAdmin')) {
            if (!$orgId) {
                return ApiResponse::error('Active organization required', 'TENANT_REQUIRED', Response::HTTP_FORBIDDEN);
            }

            $query->where(function ($q) use ($orgId) {
                $q->where('buyer_organization_id', $orgId)
                  ->orWhere('seller_organization_id', $orgId);
            });
        }

        $deal = $query->first();
        if (!$deal) {
            // A deal that exists but is not yours answers 403 without leaking
            // its contents; a deal that does not exist at all must not pretend
            // to be a permission problem.
            $exists = Deal::whereKey($id)->exists();

            return $exists
                ? ApiResponse::error('Unauthorized to access this deal', 'DEAL_ACCESS_DENIED', Response::HTTP_FORBIDDEN)
                : ApiResponse::error('Deal not found', 'NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        return ApiResponse::success($deal, 'Deal details retrieved successfully');
    }

    /**
     * Permission each target state requires.
     *
     * The route cannot express this: one endpoint serves both counterparties
     * and the platform, and "who may do this" is part of the state machine,
     * not of the URL. A BuyerStaff member holds `deals.deliver` but not
     * `deals.confirm`, so a single blanket gate would either lock them out of
     * their own job or let them approve deals.
     */
    protected const TRANSITION_PERMISSIONS = [
        Deal::STATUS_PENDING_PAYMENT => 'deals.confirm',
        Deal::STATUS_ESCROW_FUNDED => 'deals.confirm',
        Deal::STATUS_ACCEPTED => 'deals.confirm',
        Deal::STATUS_PROCESSING => 'deals.dispatch',
        Deal::STATUS_READY_FOR_PICKUP => 'deals.dispatch',
        Deal::STATUS_DISPATCHED => 'deals.dispatch',
        Deal::STATUS_DELIVERED => 'deals.deliver',
        Deal::STATUS_INSPECTION => 'deals.deliver',
        Deal::STATUS_COMPLETED => 'deals.confirm',
        Deal::STATUS_SETTLED => 'deals.manage',
        Deal::STATUS_DISPUTED => 'deals.dispute',
        Deal::STATUS_RESOLVED => 'disputes.resolve',
        Deal::STATUS_REFUNDED => 'disputes.resolve',
        Deal::STATUS_CANCELLED => 'deals.confirm',
    ];

    /**
     * Executes state machine transition on the deal.
     */
    public function transition(Request $request, int $id): JsonResponse
    {
        $orgId = TenantContext::id();
        $user = $request->user();

        $deal = Deal::findOrFail($id);

        $isPlatform = $user->hasRole('SuperAdmin');

        if (!$isPlatform && !in_array((int) $orgId, [(int) $deal->buyer_organization_id, (int) $deal->seller_organization_id], true)) {
            return ApiResponse::error('Unauthorized to modify this deal', 'DEAL_ACCESS_DENIED', Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'status' => ['required', 'string'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ]);

        $requiredPermission = self::TRANSITION_PERMISSIONS[$validated['status']] ?? null;

        if ($requiredPermission !== null && !$user->can($requiredPermission)) {
            return ApiResponse::error(
                "Moving a deal to {$validated['status']} requires the '{$requiredPermission}' permission.",
                'DEAL_TRANSITION_FORBIDDEN',
                Response::HTTP_FORBIDDEN
            );
        }

        if ($requiredPermission === null && !$isPlatform) {
            // An unknown target is rejected here rather than inside the state
            // machine, so a typo cannot be mistaken for a legal state the
            // actor rules were meant to guard.
            return ApiResponse::error(
                'Unknown deal state',
                'DEAL_STATE_UNKNOWN',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $updatedDeal = $this->stateMachine->transition(
                $deal,
                $validated['status'],
                $user,
                $validated['notes'] ?? null,
                $validated['metadata'] ?? null,
                $isPlatform ? null : $orgId
            );

            return ApiResponse::success($updatedDeal->fresh([
                'buyerOrganization',
                'sellerOrganization',
                'escrow',
                'purchaseRequest',
                'statusHistories',
            ]), "Deal state successfully transitioned to {$validated['status']}");
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), 'STATE_TRANSITION_FAILED', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
