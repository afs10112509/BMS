<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profit_shares', function (Blueprint $table) {
            $table->foreignId('pic_employee_id')
                ->nullable()
                ->after('pic_name')
                ->constrained('employees')
                ->nullOnDelete();
        });

        $shares = DB::table('profit_shares')
            ->whereNull('pic_employee_id')
            ->whereNotNull('pic_name')
            ->get(['id', 'branch_id', 'pic_name']);

        foreach ($shares as $share) {
            $name = mb_strtolower(trim((string) $share->pic_name));
            if ($name === '') {
                continue;
            }

            $employee = DB::table('employees')
                ->where('branch_id', $share->branch_id)
                ->whereRaw('LOWER(TRIM(name)) = ?', [$name])
                ->orderBy('id')
                ->first(['id']);

            if (! $employee) {
                continue;
            }

            DB::table('profit_shares')
                ->where('id', $share->id)
                ->update(['pic_employee_id' => $employee->id]);
        }
    }

    public function down(): void
    {
        Schema::table('profit_shares', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pic_employee_id');
        });
    }
};
