<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Role employee (PostgreSQL enum/check → varchar + check baru)
        DB::statement('ALTER TABLE users ALTER COLUMN role DROP DEFAULT');
        DB::statement('ALTER TABLE users ALTER COLUMN role TYPE VARCHAR(20) USING role::text');
        DB::statement("ALTER TABLE users ALTER COLUMN role SET DEFAULT 'admin'");
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('owner', 'admin', 'employee'))");

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('branch_id')->constrained('employees')->nullOnDelete();
            $table->unique('employee_id');
        });

        Schema::create('attendance_branch_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->time('check_in_start')->default('07:00:00');
            $table->time('check_in_end')->default('09:00:00');
            $table->time('check_out_start')->default('16:00:00');
            $table->time('check_out_end')->default('20:00:00');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('branch_id');
        });

        DB::statement('ALTER TABLE employee_attendances ALTER COLUMN status DROP NOT NULL');

        Schema::table('employee_attendances', function (Blueprint $table) {
            $table->timestamp('check_in_at')->nullable()->after('status');
            $table->timestamp('check_out_at')->nullable()->after('check_in_at');
            $table->string('check_in_photo')->nullable()->after('check_out_at');
            $table->string('check_out_photo')->nullable()->after('check_in_photo');
            // null = input admin / selesai normal; checked_in | pending_review | approved_incomplete | rejected
            $table->string('self_state', 30)->nullable()->after('check_out_photo');
            $table->timestamp('reviewed_at')->nullable()->after('self_state');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->string('review_note')->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('employee_attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn([
                'check_in_at',
                'check_out_at',
                'check_in_photo',
                'check_out_photo',
                'self_state',
                'reviewed_at',
                'review_note',
            ]);
        });

        Schema::dropIfExists('attendance_branch_settings');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
        });

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('owner', 'admin'))");
    }
};
