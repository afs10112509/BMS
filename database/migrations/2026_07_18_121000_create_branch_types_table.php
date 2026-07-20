<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->boolean('allows_service')->default(true);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::table('branch_types')->insert([
            [
                'code' => 'konter',
                'name' => 'Konter',
                'allows_service' => true,
                'status' => 'active',
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'bengkel',
                'name' => 'Bengkel',
                'allows_service' => false,
                'status' => 'active',
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Hapus tipe lama "cabang" jika ada
        DB::table('branches')
            ->where('type', 'cabang')
            ->update(['type' => 'konter']);
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_types');
    }
};
