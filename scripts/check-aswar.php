<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rows = App\Models\Employee::query()
    ->where('name', 'ilike', '%aswar%')
    ->get(['id', 'name', 'position', 'positions', 'status', 'branch_id']);

foreach ($rows as $r) {
    echo implode('|', [
        $r->id,
        $r->name,
        $r->position,
        json_encode($r->positions),
        $r->status,
        'branch='.$r->branch_id,
        'mgmt='.($r->isManagement() ? '1' : '0'),
        'tech='.($r->isTechnician() ? '1' : '0'),
    ]).PHP_EOL;
}

$eligible = App\Models\Employee::query()
    ->where('branch_id', 3)
    ->where('status', 'active')
    ->withoutOwner()
    ->orderBy('name')
    ->pluck('name')
    ->all();
echo 'ELIGIBLE_WITHOUT_OWNER='.implode(',', $eligible).PHP_EOL;
