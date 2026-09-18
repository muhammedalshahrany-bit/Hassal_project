<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\User;
use App\Services\InventoryReservationService;
use Exception;
use Tests\TestCase;

class InventoryReservationAndConcurrencyTest extends TestCase
{
    protected InventoryReservationService $reservationService;
    protected Offer $offer;
    protected Organization $buyerOrg;
    protected User $buyerUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reservationService = app(InventoryReservationService::class);

        $this->buyerOrg = Organization::firstOrCreate(
            ['commercial_registration_number' => '1010333444'],
            [
                'name_ar' => 'شركة أسواق المستهلك المركزية',
                'name_en' => 'Consumer Central Markets',
                'type' => 'buyer',
                'status' => 'active',
            ]
        );

        $this->buyerUser = User::firstOrCreate(
            ['email' => 'purchasing@consumer.sa'],
            [
                'name' => 'Purchasing Manager',
                'phone' => '+966555544433',
                'password' => bcrypt('Secret123!'),
                'is_active' => true,
            ]
        );

        // Find or create active Offer with known inventory
        $this->offer = Offer::firstOrCreate(
            ['offer_number' => 'OFF-CONCURRENCY-TEST'],
            [
                'organization_id' => 1,
                'product_id' => 1,
                'warehouse_id' => 1,
                'title_ar' => 'عرض اختبار التزامن والمخزون',
                'title_en' => 'Concurrency Inventory Test Offer',
                'currency_code' => 'SAR',
                'original_price' => 100.00,
                'offer_price' => 80.00,
                'total_quantity' => 100.00,
                'available_quantity' => 100.00,
                'reserved_quantity' => 0.00,
                'sold_quantity' => 0.00,
                'min_order_quantity' => 10,
                'max_order_quantity' => 100,
                'status' => Offer::STATUS_ACTIVE,
            ]
        );

        // Reset stock to exact 100
        $this->offer->update([
            'available_quantity' => 100.00,
            'reserved_quantity' => 0.00,
            'sold_quantity' => 0.00,
            'status' => Offer::STATUS_ACTIVE,
        ]);
    }

    public function test_successful_atomic_inventory_reservation(): void
    {
        $reservation = $this->reservationService->reserve(
            $this->offer,
            $this->buyerOrg,
            $this->buyerUser,
            30.00
        );

        $this->assertInstanceOf(Reservation::class, $reservation);
        $this->assertEquals(Reservation::STATUS_ACTIVE, $reservation->status);
        $this->assertEquals(30.00, $reservation->quantity);

        $this->offer->refresh();
        $this->assertEquals(70.00, (float) $this->offer->available_quantity);
        $this->assertEquals(30.00, (float) $this->offer->reserved_quantity);
    }

    public function test_overselling_prevention_throws_exception(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Insufficient available stock');

        // Request 150 when only 100 available
        $this->reservationService->reserve(
            $this->offer,
            $this->buyerOrg,
            $this->buyerUser,
            150.00
        );
    }

    public function test_reservation_cancellation_restores_available_stock(): void
    {
        $reservation = $this->reservationService->reserve(
            $this->offer,
            $this->buyerOrg,
            $this->buyerUser,
            25.00
        );

        $this->offer->refresh();
        $this->assertEquals(75.00, (float) $this->offer->available_quantity);

        // Cancel reservation
        $this->reservationService->cancelReservation($reservation, 'Buyer cancelled intent');

        $this->offer->refresh();
        $this->assertEquals(100.00, (float) $this->offer->available_quantity);
        $this->assertEquals(0.00, (float) $this->offer->reserved_quantity);
        $this->assertEquals(Reservation::STATUS_CANCELLED, $reservation->fresh()->status);
    }
}
