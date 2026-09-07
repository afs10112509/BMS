<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('nik', 32)->nullable()->after('nickname');
            $table->string('gender', 16)->nullable()->after('nik');
            $table->text('address')->nullable()->after('emergency_contact');
            $table->string('bank_name', 100)->nullable()->after('birth_date');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['nik', 'gender', 'address', 'bank_name']);
        });
    }
};
