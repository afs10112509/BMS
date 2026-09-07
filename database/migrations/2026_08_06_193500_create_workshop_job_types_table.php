<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_job_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('name', 100);
            $table->decimal('default_amount', 15, 2)->nullable();
            $table->string('status', 20)->default('active'); // active|inactive
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });

        // Unik per cabang, case-insensitive (PostgreSQL).
        DB::statement('CREATE UNIQUE INDEX workshop_job_types_branch_name_unique ON workshop_job_types (branch_id, LOWER(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_job_types');
    }
};
