<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['name' => 'Cash', 'code' => 'cash', 'sort_order' => 1],
            ['name' => 'Mandiri', 'code' => 'mandiri', 'sort_order' => 2],
            ['name' => 'BRI', 'code' => 'bri', 'sort_order' => 3],
            ['name' => 'GoPay', 'code' => 'gopay', 'sort_order' => 4],
        ];

        foreach ($accounts as $account) {
            Account::query()->updateOrCreate(
                ['code' => $account['code']],
                [
                    'name' => $account['name'],
                    'is_active' => true,
                    'sort_order' => $account['sort_order'],
                ]
            );
        }
    }
}
