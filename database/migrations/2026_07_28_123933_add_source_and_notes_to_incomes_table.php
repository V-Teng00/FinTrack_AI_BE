<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incomes', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'month']); // was one-row-per-month; now multiple sources allowed
            $table->string('source')->default('Income')->after('month');
            $table->text('notes')->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('incomes', function (Blueprint $table) {
            $table->dropColumn(['source', 'notes']);
            $table->unique(['user_id', 'month']);
        });
    }
};