<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliations', function (Blueprint $table) {
            $table->foreignId('account_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('accounts')
                ->restrictOnDelete();

            $table->unique(
                ['branch_id', 'account_id', 'reconciliation_date'],
                'reconciliations_branch_account_date_unique'
            );
        });

        $cashId = DB::table('accounts')->where('code', 'cash')->value('id');
        if ($cashId) {
            DB::table('reconciliations')->whereNull('account_id')->update(['account_id' => $cashId]);
        }
    }

    public function down(): void
    {
        Schema::table('reconciliations', function (Blueprint $table) {
            $table->dropUnique('reconciliations_branch_account_date_unique');
            $table->dropConstrainedForeignId('account_id');
        });
    }
};
