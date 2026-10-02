<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->string('deal_number', 50)->unique();
            $table->foreignId('offer_id')->constrained('offers')->restrictOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->foreignId('buyer_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('seller_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('buyer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('seller_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

            // Deal Quantities & Currency
            $table->decimal('quantity', 14, 2);
            $table->decimal('unit_price', 18, 4);
            $table->string('currency_code', 3)->default('YER');
            // No `transaction_currency` and no `exchange_rate`: both were copies
            // of a fact this row already carries — the currency the deal is made
            // in, and the rate frozen at the moment it was made. Two columns that
            // can disagree is one too many, and a deal that stores the platform's
            // *current* rate in a row meant to be immutable stores nothing. They
            // were dropped by `2026_09_25_100000_drop_duplicated_currency_facts_from_deals.php`,
            // and the lines are removed here so a fresh database never holds a
            // shape the platform has already decided against.
            $table->string('base_currency', 3)->default('YER');
            $table->decimal('exchange_rate_snapshot', 18, 6)->default(1.000000);
            $table->string('rate_source', 50)->default('MANUAL_ADMIN_RATE');
            $table->timestamp('rate_timestamp')->nullable();

            // Pricing Snapshot (Immutable after deal creation)
            $table->decimal('base_amount', 18, 4);
            $table->decimal('discount_amount', 18, 4)->default(0.0000);
            $table->decimal('tax_rate_snapshot', 6, 4)->default(0.1500);
            $table->decimal('tax_amount', 18, 4)->default(0.0000);
            $table->decimal('platform_fee_rate_snapshot', 6, 4)->default(0.0250);
            $table->decimal('platform_fee_amount', 18, 4)->default(0.0000);
            $table->decimal('delivery_fee_amount', 18, 4)->default(0.0000);
            $table->decimal('buyer_total_amount', 18, 4);
            $table->decimal('seller_net_amount', 18, 4);

            // Lifecycle Status
            //
            // DRAFT, PENDING_SELLER_APPROVAL, ACCEPTED, PROCESSING,
            // READY_FOR_PICKUP, DISPATCHED, DELIVERED, INSPECTION, COMPLETED,
            // CANCELLED, DISPUTED, RESOLVED — the list is `App\Trading\Models\Deal::STATUSES`,
            // and `deals_status_is_canonical` at the foot of this method holds the
            // table to it.
            $table->string('status', 40)->default('PENDING_SELLER_APPROVAL');

            // Logistics & Inspection
            $table->string('delivery_method', 50)->default('PICKUP'); // PICKUP, SELLER_DELIVERY, THIRD_PARTY_CARRIER
            $table->string('delivery_address', 255)->nullable();
            $table->unsignedInteger('inspection_window_hours')->default(48);
            $table->timestamp('inspection_deadline_at')->nullable();

            // Lifecycle Timestamps
            //
            // No `paid_at` and no `settled_at`: they recorded the moment money was
            // said to pass through HASSAL, and no money does. Payment is settled
            // between the two businesses outside the platform, so a timestamp of a
            // platform-side payment would be a record of an event that cannot happen.
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Indexes
            $table->index(['buyer_organization_id', 'status']);
            $table->index(['seller_organization_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('offer_id');
        });

        Schema::create('deal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('snapshot_product_name');
            $table->string('snapshot_sku', 100);
            $table->string('snapshot_unit_name', 50);
            $table->decimal('quantity', 14, 2);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('subtotal_amount', 18, 4);
            $table->timestamps();

            $table->index('deal_id');
        });

        Schema::create('deal_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->string('from_status', 40);
            $table->string('to_status', 40);
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['deal_id', 'created_at']);
        });

        // A CHECK built from `Deal::STATUSES`, so the schema and the state machine
        // cannot drift into naming two different sets of states. It exists because
        // a row can reach this table from somewhere other than the machine — a
        // seeder, a database console, a restored replica — and a deal in a state no
        // client has a label for is a deal every screen has to guess about.
        // Compared case-sensitively (`utf8mb4_bin`): these tokens are names, and
        // `completed` is not a state this platform speaks.
        $values = implode(',', array_map(
            static fn (string $status): string => "'" . addslashes($status) . "'",
            \App\Trading\Models\Deal::STATUSES
        ));

        DB::statement("ALTER TABLE `deals` ADD CONSTRAINT `deals_status_is_canonical` CHECK (`status` COLLATE utf8mb4_bin IN ({$values}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_status_histories');
        Schema::dropIfExists('deal_items');
        Schema::dropIfExists('deals');
    }
};
