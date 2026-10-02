<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_code', 50)->unique(); // e.g., '1010-CASH', '2010-ESCROW-HOLDING', '2020-SELLER-PAYABLE', '4010-COMMISSION-REVENUE', '2030-VAT-OUTPUT'
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('type', 30); // ASSET, LIABILITY, EQUITY, REVENUE, EXPENSE
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('currency_code', 3)->default('YER');
            $table->decimal('balance', 18, 4)->default(0.0000);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'type']);
            $table->index('is_active');
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_number', 50)->unique();
            $table->string('transaction_reference', 100)->nullable();
            $table->string('currency_code', 3)->default('YER');
            $table->decimal('exchange_rate_to_base', 18, 6)->default(1.000000);
            $table->text('description');
            $table->string('entity_type', 100)->nullable(); // the kind of row the entry is about: Deal today, and only that
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('total_debit', 18, 4);
            $table->decimal('total_credit', 18, 4);
            $table->timestamp('posted_at');
            $table->boolean('is_reversal')->default(false);
            $table->foreignId('reversal_of_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index('posted_at');
            $table->index('transaction_reference');
        });

        Schema::create('ledger_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->decimal('debit_amount', 18, 4)->default(0.0000);
            $table->decimal('credit_amount', 18, 4)->default(0.0000);
            $table->string('memo')->nullable();
            $table->timestamps();

            $table->index(['ledger_account_id', 'organization_id']);
            $table->index('journal_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('ledger_accounts');
    }
};
