<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * Pos Alur Kas dari lembar kerja spreadsheet.
 * Global = dipakai semua cabang; lokal = khas cabang tertentu.
 */
class AlurKasCategorySeeder extends Seeder
{
    public function run(): void
    {
        $globals = [
            // Pendapatan
            ['name' => 'Penjualan', 'type' => 'income'],
            ['name' => 'Service', 'type' => 'income'],
            ['name' => 'Brilink', 'type' => 'income'],
            ['name' => 'Kos Kosan', 'type' => 'income'],
            ['name' => 'Lain-lain', 'type' => 'income'],
            // Pengeluaran
            ['name' => 'Gaji Karyawan', 'type' => 'expense'],
            ['name' => 'Operasional', 'type' => 'expense'],
            ['name' => 'Dapur', 'type' => 'expense'],
            ['name' => 'Listrik', 'type' => 'expense'],
            ['name' => 'Jajan', 'type' => 'expense'],
            ['name' => 'Lain-lain', 'type' => 'expense'],
        ];

        foreach ($globals as $row) {
            Category::query()->updateOrCreate(
                [
                    'branch_id' => null,
                    'name' => $row['name'],
                    'type' => $row['type'],
                ],
                ['is_active' => true]
            );
        }

        $local = [
            'Sawai' => [
                ['name' => 'Pulsa', 'type' => 'income'],
            ],
            'Lukulamo' => [
                ['name' => 'Insentif PIC', 'type' => 'expense'],
            ],
            'Waebulen' => [
                ['name' => 'In Acc HP Bonus', 'type' => 'expense'],
            ],
        ];

        foreach ($local as $branchName => $rows) {
            $branchId = Branch::query()->where('name', $branchName)->value('id');
            if (! $branchId) {
                $this->command?->warn("Cabang {$branchName} tidak ditemukan — dilewati.");
                continue;
            }

            foreach ($rows as $row) {
                Category::query()->updateOrCreate(
                    [
                        'branch_id' => $branchId,
                        'name' => $row['name'],
                        'type' => $row['type'],
                    ],
                    ['is_active' => true]
                );
            }
        }

        $this->command?->info('Pos Alur Kas (global + lokal) berhasil disiapkan.');
    }
}
