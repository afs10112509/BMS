<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Branch;
use App\Models\Employee;

$branch = Branch::query()
    ->where('name', 'ilike', '%Bengkel%')
    ->orderBy('id')
    ->get(['id', 'name', 'type']);

echo "BRANCHES=\n";
foreach ($branch as $b) {
    echo $b->id.'|'.$b->name.'|'.$b->type.PHP_EOL;
}

$target = Branch::query()
    ->where(function ($q) {
        $q->where('name', 'ilike', 'Bengkel Belawa')
            ->orWhere('name', 'ilike', 'Bengkel');
    })
    ->orderByRaw("CASE WHEN name ILIKE 'Bengkel Belawa' THEN 0 ELSE 1 END")
    ->first();

if (! $target) {
    fwrite(STDERR, "Cabang Bengkel tidak ditemukan.\n");
    exit(1);
}

echo "TARGET={$target->id}|{$target->name}\n";

$rows = [
    ['name' => 'Apip', 'phone' => '8000000002', 'positions' => ['teknisi']],
    ['name' => 'Aris', 'phone' => '8000000006', 'positions' => ['teknisi']],
    ['name' => 'Aswar', 'phone' => '085240411140', 'positions' => ['pic', 'teknisi']],
    ['name' => 'Haikal', 'phone' => '8000000007', 'positions' => ['kasir', 'teknisi']],
    ['name' => 'Ikul', 'phone' => '8000000001', 'positions' => ['teknisi']],
    ['name' => 'Robi', 'phone' => '8000000005', 'positions' => ['teknisi']],
    ['name' => 'Salim', 'phone' => '8000000003', 'positions' => ['teknisi']],
    ['name' => 'Teo', 'phone' => '8000000004', 'positions' => ['teknisi']],
];

foreach ($rows as $row) {
    $emp = Employee::query()
        ->where('branch_id', $target->id)
        ->where('name', 'ilike', $row['name'])
        ->first();

    $payload = [
        'branch_id' => $target->id,
        'name' => $row['name'],
        'phone' => $row['phone'],
        'positions' => Employee::normalizePositions($row['positions']),
        'status' => 'active',
    ];

    if ($emp) {
        $emp->fill($payload);
        $emp->save();
        echo "UPDATED={$emp->id}|{$emp->name}|{$emp->position}|{$emp->phone}\n";
    } else {
        $emp = Employee::query()->create($payload);
        echo "CREATED={$emp->id}|{$emp->name}|{$emp->position}|{$emp->phone}\n";
    }
}

echo "DONE count=".Employee::query()->where('branch_id', $target->id)->where('status', 'active')->count().PHP_EOL;
