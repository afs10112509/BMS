<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $globals = [
            ['name' => 'Penjualan HP', 'type' => 'income'],
            ['name' => 'Penjualan Pulsa', 'type' => 'income'],
            ['name' => 'Service', 'type' => 'income'],
            ['name' => 'Dapur', 'type' => 'expense'],
            ['name' => 'Listrik', 'type' => 'expense'],
            ['name' => 'Operasional', 'type' => 'expense'],
            ['name' => 'Sparepart', 'type' => 'expense'],
        ];

        foreach ($globals as $category) {
            Category::query()->updateOrCreate(
                [
                    'branch_id' => null,
                    'name' => $category['name'],
                    'type' => $category['type'],
                ],
                ['is_active' => true]
            );
        }

        // Nama karyawan: lokal cabang Sawai (bukan global).
        $sawaiId = Branch::query()->where('name', 'Sawai')->value('id');
        if ($sawaiId) {
            foreach (['Roni', 'Uki', 'Hasmin'] as $name) {
                Category::query()->updateOrCreate(
                    [
                        'branch_id' => $sawaiId,
                        'name' => $name,
                        'type' => 'expense',
                    ],
                    ['is_active' => true]
                );

                // Bersihkan sisa global lama jika ada (hindari duplikat dari seed sebelumnya).
                Category::query()
                    ->whereNull('branch_id')
                    ->where('name', $name)
                    ->where('type', 'expense')
                    ->whereDoesntHave('transactions')
                    ->delete();
            }
        }
    }
}
