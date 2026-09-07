<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashflow_workbooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('total_income', 15, 2)->default(0);
            $table->decimal('total_expense', 15, 2)->default(0);
            $table->decimal('net_profit', 15, 2)->default(0);
            $table->string('note')->nullable();
            $table->foreignId('input_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'year', 'month']);
            $table->index(['year', 'month']);
        });

        Schema::create('cashflow_workbook_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashflow_workbook_id')->constrained('cashflow_workbooks')->cascadeOnDelete();
            $table->string('type', 20); // income|expense
            $table->string('name');
            $table->decimal('amount', 15, 2)->default(0);
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('source', 30)->default('manual'); // transaction|workshop_shop|manual
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['cashflow_workbook_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashflow_workbook_lines');
        Schema::dropIfExists('cashflow_workbooks');
    }
};
