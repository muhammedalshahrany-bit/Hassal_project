<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use App\Services\InventoryReservationService;
use Carbon\Carbon;
use Tests\TestCase;

class ReservationExpirySweepTest extends TestCase
{
    public function test_expired_reservations_are_auto_released_and_stock_restored(): void
    {
        $reservationService = app(InventoryReservationService::class);

        $buyerOrg = Organization::where('type', 'buyer')->first() ?? Organization::first();
        $buyerUser = User::first();

        $offer = Offer::first();
        $initialAvailable = (float) $offer->available_quantity;
        $initialReserved = (float) $offer->reserved_quantity;

        // Create an active reservation
        $reservation = $reservationService->reserve($offer, $buyerOrg, $buyerUser, 15.00);

        // Manually backdate the expires_at to 10 minutes in the past
        $reservation->update([
            'expires_at' => Carbon::now()->subMinutes(10),
        ]);

        // Run the expiry sweeper
        $releasedCount = $reservationService->releaseExpiredReservations();

        $this->assertGreaterThanOrEqual(1, $releasedCount);
        $this->assertEquals(Reservation::STATUS_EXPIRED, $reservation->fresh()->status);

        $offer->refresh();
        $this->assertEquals($initialAvailable, (float) $offer->available_quantity);
        $this->assertEquals($initialReserved, (float) $offer->reserved_quantity);
    }
}
