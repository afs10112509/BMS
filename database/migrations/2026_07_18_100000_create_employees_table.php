<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('position')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->date('joined_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
