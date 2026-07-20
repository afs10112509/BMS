<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $branches = [
            [
                'name' => 'Sawai',
                'type' => Branch::TYPE_KONTER,
                'address' => null,
                'status' => 'active',
            ],
            [
                'name' => 'Waebulen',
                'type' => Branch::TYPE_KONTER,
                'address' => null,
                'status' => 'active',
            ],
            [
                'name' => 'Bengkel',
                'type' => Branch::TYPE_BENGKEL,
                'address' => null,
                'status' => 'active',
            ],
            [
                'name' => 'Lukulamo',
                'type' => Branch::TYPE_KONTER,
                'address' => null,
                'status' => 'active',
            ],
        ];

        foreach ($branches as $branch) {
            Branch::query()->updateOrCreate(
                ['name' => $branch['name']],
                $branch
            );
        }
    }
}
