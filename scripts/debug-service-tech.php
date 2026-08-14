<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Employee;
use App\Models\User;
use App\Models\Branch;

$ilham = Employee::query()->where('name', 'ilike', '%Ilham%')->get(['id', 'branch_id', 'name', 'position', 'positions', 'status']);
echo "ILHAM_ROWS=".json_encode($ilham, JSON_UNESCAPED_UNICODE).PHP_EOL;

foreach (User::query()->where('role', 'admin')->with('branch:id,name,type')->get(['id', 'name', 'email', 'branch_id', 'role']) as $u) {
    $bt = $u->branch?->branchType;
    echo 'ADMIN|'.$u->id.'|'.$u->name.'|'.$u->email.'|branch='.$u->branch_id.'|'.($u->branch->name ?? '-').'|type='.($u->branch->type ?? '-').PHP_EOL;
}

$wae = Branch::query()->where('name', 'ilike', '%Waebulen%')->with('branchType')->first();
echo 'WAE='.json_encode($wae?->only(['id', 'name', 'type', 'status']), JSON_UNESCAPED_UNICODE).PHP_EOL;
echo 'WAE_ALLOWS='.json_encode($wae?->branchType?->allows_service).PHP_EOL;

if ($wae) {
    $q = Employee::query()
        ->where('employees.status', 'active')
        ->where(function ($q) {
            $q->withPosition(Employee::POS_TEKNISI)
                ->orWhere('employees.position', 'ilike', '%teknisi%');
        })
        ->where('employees.branch_id', $wae->id)
        ->orderBy('employees.name')
        ->select([
            'employees.id',
            'employees.branch_id',
            'employees.name',
            'employees.phone',
            'employees.position',
            'employees.positions',
            'employees.status',
        ]);

    echo 'SQL='.$q->toSql().PHP_EOL;
    echo 'BINDINGS='.json_encode($q->getBindings()).PHP_EOL;
    try {
        $rows = $q->get();
        echo 'COUNT='.$rows->count().PHP_EOL;
        foreach ($rows as $r) {
            echo $r->id.'|'.$r->name.'|'.$r->position.'|'.json_encode($r->positions).'|isTech='.($r->isTechnician() ? '1' : '0').PHP_EOL;
        }
    } catch (Throwable $t) {
        echo 'ERR='.$t->getMessage().PHP_EOL;
    }
}

// Simulate controller for each admin
foreach (User::query()->where('role', 'admin')->get() as $admin) {
    $branchId = (int) $admin->branch_id;
    $rows = Employee::query()
        ->where('employees.status', 'active')
        ->where(function ($q) {
            $q->withPosition(Employee::POS_TEKNISI)
                ->orWhere('employees.position', 'ilike', '%teknisi%');
        })
        ->where('employees.branch_id', $branchId)
        ->get(['employees.id', 'employees.name', 'employees.positions', 'employees.status']);
    echo 'SIM_ADMIN|'.$admin->email.'|branch='.$branchId.'|count='.$rows->count().'|names='.$rows->pluck('name')->implode(',').PHP_EOL;
}
