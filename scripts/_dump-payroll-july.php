<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rows = App\Models\Payroll::withoutGlobalScopes()
    ->with(['employee:id,name,position', 'branch:id,name'])
    ->where('year', 2026)
    ->where('month', 7)
    ->orderBy('branch_id')
    ->get();

foreach ($rows as $p) {
    echo implode('|', [
        $p->branch->name ?? '',
        $p->employee->name ?? '',
        $p->employee->position ?? '',
        'hadir='.$p->present_days,
        'prom='.($p->is_promotor ? '1' : '0'),
        'gapok='.$p->gapok,
        'hp='.$p->insentif_hp,
        'svc='.$p->service_incentive,
        'acc='.$p->insentif_acc,
        'bonus='.$p->bonus_absen,
        'hutang='.$p->hutang,
        'pengeluaran='.$p->pengeluaran,
        'total='.$p->total,
        $p->status,
    ]).PHP_EOL;
}
echo 'COUNT='.$rows->count().PHP_EOL;
