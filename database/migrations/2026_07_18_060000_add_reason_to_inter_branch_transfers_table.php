<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inter_branch_transfers', function (Blueprint $table) {
            $table->text('reason')->nullable()->after('requested_by');
        });
    }

    public function down(): void
    {
        Schema::table('inter_branch_transfers', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
