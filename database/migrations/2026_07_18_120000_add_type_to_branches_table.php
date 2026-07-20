<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('type', 20)->default('konter')->after('name');
        });

        DB::table('branches')
            ->whereRaw('LOWER(TRIM(name)) = ?', ['bengkel'])
            ->update(['type' => 'bengkel']);

        DB::table('branches')
            ->where('type', '!=', 'bengkel')
            ->update(['type' => 'konter']);
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
