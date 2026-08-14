<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliations', function (Blueprint $table) {
            $table->timestamp('adjusted_at')->nullable()->after('difference');
            $table->foreignId('adjusted_by')->nullable()->after('adjusted_at')->constrained('users')->nullOnDelete();
            $table->foreignId('adjustment_transaction_id')->nullable()->after('adjusted_by')->constrained('transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reconciliations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('adjustment_transaction_id');
            $table->dropConstrainedForeignId('adjusted_by');
            $table->dropColumn('adjusted_at');
        });
    }
};
