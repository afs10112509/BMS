<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brilink_daily_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->date('sheet_date');
            $table->decimal('previous_total', 15, 2)->default(0); // saldo kemarin (total)
            $table->decimal('total_amount', 15, 2)->default(0); // Σ baris hari ini
            $table->decimal('profit', 15, 2)->default(0); // total − kemarin
            $table->string('note')->nullable();
            $table->foreignId('input_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'sheet_date']);
            $table->index(['sheet_date']);
        });

        Schema::create('brilink_daily_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brilink_daily_sheet_id')->constrained('brilink_daily_sheets')->cascadeOnDelete();
            $table->string('name', 150);
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['brilink_daily_sheet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brilink_daily_lines');
        Schema::dropIfExists('brilink_daily_sheets');
    }
};
