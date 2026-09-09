<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code')->nullable();
                $table->string('phone')->nullable();
                $table->string('address')->nullable();
                $table->string('contact_person')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            // Seed default suppliers
            $now = now();
            DB::table('suppliers')->insert([
                ['name' => 'Distributor Utama HP', 'code' => 'SUP-HP-01', 'phone' => '081234567890', 'address' => 'Makassar', 'contact_person' => 'Budi', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Grosir Aksesoris Makassar', 'code' => 'SUP-AKS-01', 'phone' => '081298765432', 'address' => 'Makassar', 'contact_person' => 'Hendra', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }

        if (!Schema::hasTable('service_types')) {
            Schema::create('service_types', function (Blueprint $table) {
                $table->id();
                $table->string('name'); // Ganti LCD, Baterai, Flash Software, Bypass FRP, dll
                $table->string('category')->default('hardware'); // hardware, software
                $table->decimal('default_estimated_cost', 15, 2)->default(0);
                $table->decimal('default_cost_price', 15, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            // Seed default tarif & jenis servis
            $now = now();
            DB::table('service_types')->insert([
                ['name' => 'Ganti LCD / Touchscreen', 'category' => 'hardware', 'default_estimated_cost' => 250000, 'default_cost_price' => 150000, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Ganti Baterai', 'category' => 'hardware', 'default_estimated_cost' => 150000, 'default_cost_price' => 80000, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Flash Software / Install Ulang', 'category' => 'software', 'default_estimated_cost' => 75000, 'default_cost_price' => 20000, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Bypass FRP / Lupa Akun Google', 'category' => 'software', 'default_estimated_cost' => 100000, 'default_cost_price' => 30000, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Ganti Konektor Charger', 'category' => 'hardware', 'default_estimated_cost' => 100000, 'default_cost_price' => 35000, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }

        if (!Schema::hasTable('bank_accounts')) {
            Schema::create('bank_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->onDelete('cascade');
                $table->string('bank_name'); // BRI, BCA, Mandiri, QRIS
                $table->string('account_number');
                $table->string('account_name');
                $table->decimal('current_balance', 15, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            // Seed default bank accounts
            $now = now();
            DB::table('bank_accounts')->insert([
                ['branch_id' => null, 'bank_name' => 'Bank BRI (BRILink Toko)', 'account_number' => '535995435942', 'account_name' => 'Belawa Cell', 'current_balance' => 50000000, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
                ['branch_id' => null, 'bank_name' => 'QRIS All Payment', 'account_number' => 'QRIS-BELAWACELL', 'account_name' => 'Belawa Cell', 'current_balance' => 10000000, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('service_types');
        Schema::dropIfExists('suppliers');
    }
};
