<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profit_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status', 20)->default('draft'); // draft|locked
            $table->string('pic_name')->nullable();
            $table->decimal('pic_share_pct', 8, 2)->default(50);
            $table->decimal('total_income', 15, 2)->default(0);
            $table->decimal('total_expense', 15, 2)->default(0);
            $table->decimal('net_profit', 15, 2)->default(0);
            $table->decimal('pic_amount', 15, 2)->default(0);
            $table->string('note')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('input_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'year', 'month']);
            $table->index(['year', 'month']);
        });

        Schema::create('profit_share_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profit_share_id')->constrained('profit_shares')->cascadeOnDelete();
            $table->string('type', 20); // income|expense
            $table->string('name');
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['profit_share_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profit_share_lines');
        Schema::dropIfExists('profit_shares');
    }
};
