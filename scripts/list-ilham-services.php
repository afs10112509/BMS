<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Employee;
use App\Models\ServiceRecord;

$e = Employee::query()->where('name', 'ilike', 'Ilham')->firstOrFail();
$rows = ServiceRecord::query()
    ->where('employee_id', $e->id)
    ->whereBetween('service_date', ['2026-07-01', '2026-07-31'])
    ->orderBy('service_date')
    ->orderBy('id')
    ->get(['id', 'service_date', 'brand', 'device_type', 'damage', 'cost', 'price', 'profit', 'notes']);

foreach ($rows as $r) {
    echo implode('|', [
        $r->id,
        $r->service_date?->toDateString(),
        $r->brand,
        $r->device_type,
        $r->damage,
        $r->cost,
        $r->price,
        $r->profit,
        $r->notes,
    ]).PHP_EOL;
}
echo 'COUNT='.$rows->count().' SUM_PROFIT='.$rows->sum('profit').PHP_EOL;
