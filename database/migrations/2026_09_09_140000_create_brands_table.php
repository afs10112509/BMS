<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed brand awal toko HP & aksesoris
        $now = now();
        $defaultBrands = [
            ['name' => 'Samsung', 'code' => 'SAM', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Xiaomi', 'code' => 'XIA', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Oppo', 'code' => 'OPP', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Vivo', 'code' => 'VIV', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Realme', 'code' => 'REA', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Infinix', 'code' => 'INF', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Apple / iPhone', 'code' => 'APL', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Robot', 'code' => 'ROB', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Vivan', 'code' => 'VIV', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Anker', 'code' => 'ANK', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ];

        DB::table('brands')->insert($defaultBrands);
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
