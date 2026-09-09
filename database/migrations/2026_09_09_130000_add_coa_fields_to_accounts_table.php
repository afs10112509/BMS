<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('accounts', 'type')) {
                $table->enum('type', ['aset', 'liabilitas', 'ekuitas', 'pendapatan', 'beban'])->default('aset')->after('code');
            }
            if (!Schema::hasColumn('accounts', 'parent_id')) {
                $table->foreignId('parent_id')->nullable()->after('type')->constrained('accounts')->nullOnDelete();
            }
        });

        // Seed Akun Standar SAK EMKM jika belum ada
        $now = now();
        $accounts = [
            ['name' => 'Persediaan Barang Dagang', 'code' => '1-1200', 'type' => 'aset', 'is_active' => true, 'sort_order' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Pendapatan Penjualan', 'code' => '4-1000', 'type' => 'pendapatan', 'is_active' => true, 'sort_order' => 20, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Harga Pokok Penjualan (HPP)', 'code' => '5-1000', 'type' => 'beban', 'is_active' => true, 'sort_order' => 30, 'created_at' => $now, 'updated_at' => $now],
        ];

        foreach ($accounts as $acc) {
            DB::table('accounts')->updateOrInsert(
                ['code' => $acc['code']],
                $acc
            );
        }
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            if (Schema::hasColumn('accounts', 'parent_id')) {
                $table->dropForeign(['parent_id']);
                $table->dropColumn('parent_id');
            }
            if (Schema::hasColumn('accounts', 'type')) {
                $table->dropColumn('type');
            }
        });
    }
};
