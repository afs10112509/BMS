<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('service_date');
            $table->string('brand');
            $table->string('device_type');
            $table->string('damage');
            $table->decimal('cost', 15, 2);
            $table->decimal('price', 15, 2);
            $table->decimal('profit', 15, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'service_date']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_records');
    }
};
