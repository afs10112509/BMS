<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Branch;
use App\Models\Employee;

$branch = Branch::query()
    ->where(function ($q) {
        $q->where('name', 'ilike', 'Bengkel Belawa')
            ->orWhere('name', 'ilike', 'Bengkel');
    })
    ->orderByRaw("CASE WHEN name ILIKE 'Bengkel Belawa' THEN 0 ELSE 1 END")
    ->first();

if (! $branch) {
    fwrite(STDERR, "Cabang Bengkel tidak ditemukan.\n");
    exit(1);
}

$emp = Employee::query()
    ->where('branch_id', $branch->id)
    ->where('name', 'ilike', 'Salman')
    ->first();

if ($emp) {
    echo "EXISTS={$emp->id}|{$emp->name}|{$emp->status}|branch={$emp->branch_id}\n";
    exit(0);
}

$emp = Employee::query()->create([
    'branch_id' => $branch->id,
    'name' => 'Salman',
    'phone' => '8000000008',
    'positions' => Employee::normalizePositions(['teknisi']),
    'position' => 'Teknisi',
    'status' => 'active',
    'joined_at' => now()->toDateString(),
]);

echo "CREATED={$emp->id}|{$emp->name}|{$emp->status}|branch={$emp->branch_id}\n";
