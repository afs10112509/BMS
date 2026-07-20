<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'owner@bftbg.test'],
            [
                'branch_id' => null,
                'name' => 'Owner Belawa',
                'password' => Hash::make('password'),
                'role' => 'owner',
                'email_verified_at' => now(),
            ]
        );

        $branchNames = ['Sawai', 'Waebulen', 'Bengkel', 'Lukulamo'];

        foreach ($branchNames as $branchName) {
            $branch = Branch::query()->where('name', $branchName)->firstOrFail();
            $slug = Str::lower($branchName);

            User::query()->updateOrCreate(
                ['email' => "admin.{$slug}@bftbg.test"],
                [
                    'branch_id' => $branch->id,
                    'name' => "Admin {$branchName}",
                    'password' => Hash::make('password'),
                    'role' => 'admin',
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}
