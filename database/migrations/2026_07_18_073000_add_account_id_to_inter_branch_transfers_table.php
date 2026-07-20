<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inter_branch_transfers', function (Blueprint $table) {
            $table->foreignId('account_id')
                ->nullable()
                ->after('amount')
                ->constrained('accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inter_branch_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
        });
    }
};
