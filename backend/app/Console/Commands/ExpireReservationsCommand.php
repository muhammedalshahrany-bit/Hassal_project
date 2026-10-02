<?php

namespace App\Console\Commands;

use App\Trading\Services\InventoryReservationService;
use Illuminate\Console\Command;

class ExpireReservationsCommand extends Command
{
    protected $signature = 'app:expire-reservations';
    protected $description = 'Sweeps and expires timed-out inventory reservations, returning stock to active offers.';

    public function handle(InventoryReservationService $reservationService): int
    {
        $this->info('Starting inventory reservation expiration sweep...');
        $expiredCount = $reservationService->sweepExpiredReservations();
        $this->info("Sweep completed. Expired reservations released: {$expiredCount}");

        return Command::SUCCESS;
    }
}
