<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photographs of the goods, as files on disk — not a URL somebody typed.
 *
 * `products.image_url` and `product_variants.image_url` exist and are read into
 * offer payloads, but nothing has ever written them: there is no upload path in
 * the application, so any value in those columns came from a seeder or from
 * hand-edited SQL. A single string column also cannot express what a buyer
 * actually needs — several photos of a pallet, the carton and the label, with
 * one of them chosen as the cover.
 *
 * Each row is an uploaded file: the disk and relative path are what
 * `Storage` needs to serve or delete it, and the mime type, byte size and
 * pixel dimensions are recorded at upload time so a listing can show an
 * aspect ratio before the image loads and so a reviewer can see what was
 * accepted. The original filename is kept for the audit trail, never used to
 * build the path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // The business that uploaded it. Products are organization-owned in
            // this schema, and the media that documents a product belongs to
            // the same tenant — this is also what a rival must not be able to
            // read or delete.
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('disk', 40)->default('public');
            $table->string('path');

            $table->string('original_name')->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_cover')->default(false);

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
            $table->index(['organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
