<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status', 20)->default('draft'); // draft|locked

            $table->string('position_snapshot')->nullable();
            $table->boolean('is_promotor')->default(false);
            $table->boolean('is_technician')->default(false);

            $table->unsignedInteger('present_days')->default(0);
            $table->decimal('gapok', 15, 2)->default(0);
            $table->unsignedInteger('closing_qty')->default(0);
            $table->decimal('insentif_hp', 15, 2)->default(0);
            $table->decimal('service_profit', 15, 2)->default(0);
            $table->decimal('service_incentive', 15, 2)->default(0);

            $table->decimal('insentif_acc', 15, 2)->default(0);
            $table->decimal('bonus_absen', 15, 2)->default(0);
            $table->decimal('hutang', 15, 2)->default(0);
            $table->decimal('pengeluaran', 15, 2)->default(0);

            $table->decimal('total', 15, 2)->default(0);
            $table->string('note')->nullable();

            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('input_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'year', 'month']);
            $table->index(['branch_id', 'year', 'month']);
            $table->index(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
