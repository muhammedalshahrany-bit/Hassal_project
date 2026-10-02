<?php

namespace App\Trading\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Http\ApiResponse;
use App\Core\Exceptions\ApiRefused;
use App\Trading\Models\Deal;
use App\Trading\Models\Reservation;
use App\Trading\Services\DealCreationService;
use App\Trading\Services\DealStateMachineService;
use App\Core\Tenant\TenantContext;
use App\Trading\Support\HandoverRefusal;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deals over HTTP: who may look, who may move one, and the envelope each
 * answer travels in. What a deal is made of — the pricing snapshot, the item
 * copy, the consumed reservation — is `DealCreationService`'s, and how it may
 * change state is `DealStateMachineService`'s.
 */
class DealController extends Controller
{
    public function __construct(
        protected DealStateMachineService $stateMachine,
        protected DealCreationService $creation,
    ) {}

    /**
     * Lists deals for the active organization (as buyer or seller).
     */
    public function index(Request $request): JsonResponse
    {
        $orgId = TenantContext::id();
        $user = $request->user();

        $query = Deal::with(['buyerOrganization', 'sellerOrganization', 'offer.variant.product', 'warehouse', 'handover']);

        if (!$user->isPlatformOperator()) {
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
            'delivery_method' => ['required', Rule::in(Deal::DELIVERY_METHODS)],
            'delivery_address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $reservation = Reservation::where('id', $validated['reservation_id'])
            ->where('buyer_organization_id', $buyerOrgId)
            ->where('status', Reservation::STATUS_ACTIVE)
            ->with(['offer.variant.product'])
            ->firstOrFail();

        try {
            $deal = $this->creation->fromReservation($reservation, $buyerOrgId, $request->user(), $validated);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        } catch (Exception $e) {
            // Same envelope an unwritten deal always got from this door.
            return ApiResponse::error($e->getMessage(), 'DEAL_CREATION_FAILED', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return ApiResponse::success(
            $deal->load(['buyerOrganization', 'sellerOrganization', 'offer.variant.product', 'warehouse', 'items']),
            'Deal created successfully. Waiting for seller confirmation.',
            Response::HTTP_CREATED
        );
    }

    /**
     * Retrieves full deal details, item snapshot, status timeline, and handover code.
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
            'handover',
            'dispute',
        ])->whereKey($id);

        if (!$user->isPlatformOperator()) {
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
        Deal::STATUS_PENDING_SELLER_APPROVAL => 'deals.confirm',
        Deal::STATUS_ACCEPTED => 'deals.confirm',
        Deal::STATUS_PROCESSING => 'deals.dispatch',
        Deal::STATUS_READY_FOR_PICKUP => 'deals.dispatch',
        Deal::STATUS_DISPATCHED => 'deals.dispatch',
        Deal::STATUS_DELIVERED => 'deals.dispatch',
        // The buyer's own receipt. `deals.deliver` belongs to whoever moves the
        // goods, and they are the party with something to gain from recording an
        // arrival; "I have it, close the order" is the other side's sentence, so
        // it is gated on the other side's permission.
        Deal::STATUS_INSPECTION => 'deals.confirm',
        Deal::STATUS_COMPLETED => 'deals.confirm',
        Deal::STATUS_DISPUTED => 'deals.dispute',
        Deal::STATUS_RESOLVED => 'disputes.resolve',
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

        $isPlatform = $user->isPlatformOperator();

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

            // No announcement here: the state machine tells the other side, so a
            // second call would be a second row in their shade for one action.

            return ApiResponse::success($updatedDeal->fresh([
                'buyerOrganization',
                'sellerOrganization',
                'handover',
                'purchaseRequest',
                'statusHistories',
            ]), "Deal state successfully transitioned to {$validated['status']}");
        } catch (HandoverRefusal $e) {
            // Its own arm because its code is contract: a client that hears
            // `HANDOVER_NOT_VERIFIED` can send the user to the scanner, while a
            // generic transition failure can only say "that did not work".
            return ApiResponse::error($e->getMessage(), $e->refusalCode, Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), 'STATE_TRANSITION_FAILED', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
