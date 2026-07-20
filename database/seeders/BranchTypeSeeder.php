<?php

namespace Database\Seeders;

use App\Models\BranchType;
use Illuminate\Database\Seeder;

class BranchTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'code' => 'konter',
                'name' => 'Konter',
                'allows_service' => true,
                'status' => 'active',
                'sort_order' => 1,
            ],
            [
                'code' => 'bengkel',
                'name' => 'Bengkel',
                'allows_service' => false,
                'status' => 'active',
                'sort_order' => 2,
            ],
        ];

        foreach ($types as $type) {
            BranchType::query()->updateOrCreate(
                ['code' => $type['code']],
                $type
            );
        }
    }
}
