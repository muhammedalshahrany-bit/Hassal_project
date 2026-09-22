<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Reservation;
use App\Services\InventoryReservationService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The buyer's side of a stock hold: reading it and giving it back.
 *
 * There is deliberately no create action here. `reserved_quantity` moves when
 * the seller accepts a purchase request (`PurchaseRequestController::accept()`),
 * which runs the same atomic, row-locked reservation through
 * `InventoryReservationService`. The route that let a buyer lock a seller's
 * shelf without being asked was removed rather than left as an unused endpoint,
 * because a review the buyer could bypass was not a review. Everything that
 * route guaranteed is still asserted — through the review path now — in
 * `InventoryReservationTest`.
 */
class ReservationController extends Controller
{
    public function __construct(
        protected InventoryReservationService $reservationService
    ) {}

    /**
     * Lists active reservations for the current buyer organization.
     */
    public function index(Request $request): JsonResponse
    {
        $buyerOrgId = TenantContext::id();
        if (!$buyerOrgId) {
            return ApiResponse::error('Active buyer organization required', 'TENANT_REQUIRED', Response::HTTP_FORBIDDEN);
        }

        $query = Reservation::with(['offer.variant.product', 'offer.seller', 'offer.warehouse'])
            ->where('buyer_organization_id', $buyerOrgId)
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $reservations = $query->paginate(20);

        return ApiResponse::paginated($reservations, 'Reservations retrieved successfully');
    }

    /**
     * Cancels / releases an active reservation.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $buyerOrgId = TenantContext::id();
        $reservation = Reservation::where('buyer_organization_id', $buyerOrgId)->findOrFail($id);

        $released = $this->reservationService->release($reservation, $request->input('reason', 'Cancelled by buyer'));

        if (!$released) {
            // The hold was already ended — by a deal, a timeout, or an earlier
            // cancellation. Saying "inventory released" here would report an
            // action this request did not perform.
            $current = $reservation->fresh();

            return ApiResponse::error(
                'This reservation is no longer active and holds no stock.',
                'RESERVATION_NOT_ACTIVE',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['status' => $current->status]
            );
        }

        return ApiResponse::success($reservation->fresh(), 'Reservation cancelled and inventory released');
    }
}
