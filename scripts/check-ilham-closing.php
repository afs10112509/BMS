<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Employee;
use App\Models\EmployeeDailyClosing;
use App\Models\EmployeeMonthlyTarget;
use App\Models\Branch;

$ilhams = Employee::withoutGlobalScopes()
    ->with(['branch:id,name,type', 'branch.branchType'])
    ->where('name', 'ilike', '%ilham%')
    ->get();

echo "=== EMPLOYEE ILHAM ===\n";
foreach ($ilhams as $e) {
    echo implode('|', [
        'id='.$e->id,
        'name='.$e->name,
        'branch='.($e->branch?->name ?? '-'),
        'type='.($e->branch?->type ?? '-'),
        'allows_service='.(($e->branch?->allows_service ?? null) === null ? 'null' : ($e->branch->allows_service ? '1' : '0')),
        'status='.$e->status,
        'position='.($e->position ?? '-'),
        'positions='.json_encode($e->positions),
        'isOwner='.($e->isOwner() ? '1' : '0'),
        'isManagement='.($e->isManagement() ? '1' : '0'),
        'isPromotor='.($e->isPromotor() ? '1' : '0'),
    ]).PHP_EOL;
}

echo "\n=== CLOSING BOARD ELIGIBLE (konter + withoutOwner) ===\n";
$elig = Employee::query()
    ->with('branch:id,name')
    ->where('status', 'active')
    ->whereHas('branch', fn ($q) => $q->where('type', Branch::TYPE_KONTER))
    ->withoutOwner()
    ->orderBy('name')
    ->get(['id', 'name', 'branch_id', 'position', 'positions', 'status']);

foreach ($elig as $e) {
    $mark = str_contains(mb_strtolower($e->name), 'ilham') ? ' << ILHAM' : '';
    echo "{$e->id}|{$e->name}|branch={$e->branch_id}|pos={$e->position}{$mark}\n";
}
echo 'COUNT_ELIG='.$elig->count()."\n";
echo 'HAS_ILHAM='.($elig->contains(fn ($e) => str_contains(mb_strtolower($e->name), 'ilham')) ? 'YES' : 'NO')."\n";

echo "\n=== WITHOUT withoutOwner (active konter only) ===\n";
$allKonter = Employee::query()
    ->where('status', 'active')
    ->whereHas('branch', fn ($q) => $q->where('type', Branch::TYPE_KONTER))
    ->orderBy('name')
    ->get(['id', 'name', 'position', 'positions']);
foreach ($allKonter as $e) {
    if (str_contains(mb_strtolower($e->name), 'ilham') || str_contains(mb_strtolower($e->position ?? ''), 'owner') || str_contains(mb_strtolower($e->position ?? ''), 'pic')) {
        echo "{$e->id}|{$e->name}|pos={$e->position}|positions=".json_encode($e->positions)
            .'|isOwner='.($e->isOwner() ? '1' : '0')
            .'|isMgmt='.($e->isManagement() ? '1' : '0')."\n";
    }
}

if ($ilhams->isNotEmpty()) {
    $id = $ilhams->first()->id;
    $from = '2026-07-01';
    $to = '2026-07-31';
    $qty = (int) EmployeeDailyClosing::query()
        ->where('employee_id', $id)
        ->whereBetween('closing_date', [$from, $to])
        ->sum('qty');
    $target = EmployeeMonthlyTarget::query()
        ->where('employee_id', $id)
        ->where('year', 2026)
        ->where('month', 7)
        ->first();
    echo "\n=== ILHAM JULI DATA ===\n";
    echo "closing_qty_sum={$qty}\n";
    echo 'target='.($target?->target ?? 'null')."\n";
}

echo "\n=== BRANCH TYPES ===\n";
foreach (Branch::query()->with('branchType')->orderBy('id')->get() as $b) {
    echo "{$b->id}|{$b->name}|type={$b->type}|allows_service=".($b->allows_service ? '1' : '0')."\n";
}
