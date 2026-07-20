<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reset global-only settings; per-teknisi starts fresh (default 50% until set).
        DB::table('workshop_wage_settings')->delete();

        Schema::table('workshop_wage_settings', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'year', 'month']);
        });

        Schema::table('workshop_wage_settings', function (Blueprint $table) {
            $table->foreignId('employee_id')->after('branch_id')->constrained('employees')->cascadeOnDelete();
            $table->unique(['branch_id', 'year', 'month', 'employee_id'], 'workshop_wage_settings_branch_period_emp_unique');
        });

        Schema::table('workshop_weeks', function (Blueprint $table) {
            $table->json('shares_snapshot')->nullable()->after('tech_share_pct_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('workshop_weeks', function (Blueprint $table) {
            $table->dropColumn('shares_snapshot');
        });

        Schema::table('workshop_wage_settings', function (Blueprint $table) {
            $table->dropUnique('workshop_wage_settings_branch_period_emp_unique');
            $table->dropConstrainedForeignId('employee_id');
            $table->unique(['branch_id', 'year', 'month']);
        });
    }
};
