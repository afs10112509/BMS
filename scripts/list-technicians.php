<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$all = App\Models\Employee::query()
    ->with('branch:id,name')
    ->where('status', 'active')
    ->orderBy('branch_id')
    ->orderBy('name')
    ->get(['id', 'branch_id', 'name', 'positions', 'position', 'status']);

echo "ALL_ACTIVE=".$all->count().PHP_EOL;
foreach ($all as $e) {
    echo implode('|', [
        $e->id,
        $e->branch?->name ?? '-',
        $e->name,
        json_encode($e->positions),
        'tech='.($e->isTechnician() ? '1' : '0'),
    ]).PHP_EOL;
}

$tech = App\Models\Employee::query()
    ->withPosition('teknisi')
    ->where('status', 'active')
    ->count();
echo "TECH_COUNT={$tech}".PHP_EOL;
