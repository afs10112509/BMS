<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('nickname')->nullable()->after('name');
            $table->string('birth_place')->nullable()->after('phone');
            $table->date('birth_date')->nullable()->after('birth_place');
            $table->string('bank_account_name')->nullable()->after('birth_date');
            $table->string('bank_account_number')->nullable()->after('bank_account_name');
            $table->string('emergency_contact')->nullable()->after('bank_account_number');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'nickname',
                'birth_place',
                'birth_date',
                'bank_account_name',
                'bank_account_number',
                'emergency_contact',
            ]);
        });
    }
};
