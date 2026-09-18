<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Offer;
use App\Models\Reservation;
use App\Services\InventoryReservationService;
use App\Services\TenantContext;
use App\Services\Notifications\NotificationManager;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReservationController extends Controller
{
    public function __construct(
        protected InventoryReservationService $reservationService,
        protected NotificationManager $notificationManager
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
     * Atomically locks stock and creates a reservation.
     */
    public function store(Request $request): JsonResponse
    {
        $buyerOrgId = TenantContext::id();
        if (!$buyerOrgId) {
            return ApiResponse::error('Active buyer organization required', 'TENANT_REQUIRED', Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'offer_id' => ['required', 'exists:offers,id'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'delivery_preference' => ['nullable', 'in:PICKUP,DELIVERY'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $offer = Offer::findOrFail($validated['offer_id']);

        if ($offer->seller_organization_id === $buyerOrgId) {
            return ApiResponse::error('Cannot purchase your own organization offer', 'SELF_PURCHASE_FORBIDDEN', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $reservation = $this->reservationService->reserve(
                $offer,
                $request->user(),
                $buyerOrgId,
                (float)$validated['quantity'],
                $validated['delivery_preference'] ?? 'PICKUP',
                $validated['notes'] ?? null
            );

            $seller = $offer->seller;
            if ($seller) {
                $this->notificationManager->notifyOrganization(
                    $seller,
                    'طلب شراء جديد',
                    "تم حجز كمية {$reservation->quantity} من العرض رقم {$offer->id}.",
                    ['type' => 'RESERVATION', 'reservation_id' => $reservation->id, 'offer_id' => $offer->id, 'organization_id' => $offer->seller_organization_id]
                );
            }

            $this->notificationManager->notify(
                $request->user(),
                'تم حجز المخزون',
                "تم حجز الكمية بنجاح من العرض رقم {$offer->id}.",
                ['type' => 'RESERVATION', 'reservation_id' => $reservation->id, 'offer_id' => $offer->id, 'organization_id' => $buyerOrgId]
            );

            return ApiResponse::success(
                $reservation->load(['offer.variant.product', 'offer.seller', 'offer.warehouse']),
                'Inventory reserved successfully. Please complete deal confirmation before reservation timeout.',
                Response::HTTP_CREATED
            );
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), 'RESERVATION_FAILED', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * Cancels / releases an active reservation.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $buyerOrgId = TenantContext::id();
        $reservation = Reservation::where('buyer_organization_id', $buyerOrgId)->findOrFail($id);

        try {
            $this->reservationService->release($reservation, $request->input('reason', 'Cancelled by buyer'));

            return ApiResponse::success($reservation->fresh(), 'Reservation cancelled and inventory released');
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), 'CANCELLATION_FAILED', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
