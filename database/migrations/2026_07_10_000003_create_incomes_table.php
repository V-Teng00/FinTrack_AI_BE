<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deliberately simple: one income figure per user per month. The original
     * feature list said "Saving/Income" on the dashboard but never specified
     * where income comes from — it has to be user-entered (there's no salary
     * slip parsing in this scope; see project notes on why that was cut).
     * Savings is derived, not stored: income - total_spending for that month.
     */
    public function up(): void
    {
        Schema::create('incomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('month', 7); // 'YYYY-MM'
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->unique(['user_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incomes');
    }
};
