<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The legal papers a business hands the platform, kept as files.
 *
 * `organizations.commercial_registration_number` and `vat_number` are strings
 * an applicant typed. Until now a platform reviewer deciding
 * `verified` / `rejected` (`OrganizationController::verification`) had nothing
 * in front of them but those typed strings, so the decision was made on the
 * applicant's own word and the record showed no evidence either way.
 *
 * A document row is therefore the thing a decision can point at: the file, who
 * uploaded it, when, what a reviewer concluded and why.
 *
 * Naming matters as much as the schema. Accepting a document means "a member of
 * this platform's staff looked at this copy and it matches what the applicant
 * claims". It does NOT mean the register of any country confirmed the number —
 * no official source is consulted anywhere in this codebase, so no status,
 * label or message here may say otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // A fixed vocabulary the reviewer's console groups by. Free text
            // here would let an applicant file "papers" of an unknown kind and
            // leave the reviewer to guess what is still missing.
            $table->string('type', 40);
            $table->string('reference_number')->nullable();

            $table->string('disk', 40)->default('public');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');

            $table->string('status', 20)->default('pending'); // pending | accepted | rejected
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'type']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_documents');
    }
};
