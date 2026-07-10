<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('store_name');
            $table->date('date');
            $table->decimal('total', 10, 2);
            $table->string('currency', 3)->default('MYR');
            $table->string('category')->default('Uncategorized');

            // original uploaded image, stored on disk/S3 — keep the path, not the bytes
            $table->string('image_path')->nullable();

            // raw JSON returned by the Lambda extraction pipeline, kept for
            // debugging low-confidence extractions and for future re-processing
            $table->json('raw_extraction')->nullable();

            // per-field confidence scores from the extraction model, e.g.
            // {"store_name": 0.98, "total": 0.91, "date": 0.76}
            $table->json('confidence')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'date']);
            $table->index(['user_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
