<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Close the gap between the documented commercial flow and the schema.
 *
 * The product flow is:
 *   Offer -> PurchaseRequest -> seller review -> Reservation -> Deal
 *
 * Nothing in the schema recorded two links in that chain:
 *   * a purchase request had no reservation, so "the seller accepted" was a
 *     status string with no commercial consequence — stock was never held and
 *     a buyer could be told a request was accepted while the offer's quantity
 *     stayed untouched for another buyer to take;
 *   * a deal had no purchase request, so the agreed terms a deal snapshots
 *     could not be traced back to the ask that produced them. Only
 *     `reservation_id` existed, making the reservation look like the origin of
 *     a transaction when the request was.
 *
 * Both columns are nullable because a reservation can still be raised directly
 * against a published offer, which is the shorter path the brief allows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->foreignId('reservation_id')
                ->nullable()
                ->after('offer_id')
                ->constrained('reservations')
                ->nullOnDelete();
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->foreignId('purchase_request_id')
                ->nullable()
                ->after('reservation_id')
                ->constrained('purchase_requests')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_request_id');
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reservation_id');
        });
    }
};
