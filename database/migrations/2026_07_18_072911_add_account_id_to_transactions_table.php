<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('account_id')
                ->nullable()
                ->after('category_id')
                ->constrained('accounts')
                ->restrictOnDelete();
        });

        $cashId = DB::table('accounts')->where('code', 'cash')->value('id');
        if ($cashId) {
            DB::table('transactions')->whereNull('account_id')->update(['account_id' => $cashId]);
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
        });
    }
};
