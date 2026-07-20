<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_wage_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('tech_share_pct', 5, 2)->default(50);
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'year', 'month']);
        });

        Schema::create('workshop_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('job_date');
            $table->string('job_type', 100);
            $table->decimal('amount', 15, 2);
            $table->string('note')->nullable();
            $table->foreignId('input_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'job_date']);
            $table->index(['employee_id', 'job_date']);
        });

        Schema::create('workshop_weeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->date('week_start');
            $table->date('week_end');
            $table->string('status', 20)->default('open'); // open|paid
            $table->decimal('tech_share_pct_snapshot', 5, 2)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'week_start']);
            $table->index(['branch_id', 'week_start', 'week_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_weeks');
        Schema::dropIfExists('workshop_jobs');
        Schema::dropIfExists('workshop_wage_settings');
    }
};
