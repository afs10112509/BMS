<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ServiceRecord;
use App\Support\Money;

$r = ServiceRecord::query()->find(9);
if (! $r) {
    fwrite(STDERR, "Record id=9 tidak ditemukan.\n");
    exit(1);
}

echo 'BEFORE='.json_encode($r->only(['id', 'brand', 'device_type', 'damage', 'cost', 'price', 'profit']), JSON_UNESCAPED_UNICODE).PHP_EOL;

$r->cost = '75000.00';
$r->price = '200000.00';
$r->profit = Money::sub($r->price, $r->cost);
if (stripos((string) $r->damage, 'cam') !== false) {
    $r->damage = 'CAMM DEPAN';
}
$r->save();

echo 'AFTER='.json_encode($r->fresh()->only(['id', 'brand', 'device_type', 'damage', 'cost', 'price', 'profit']), JSON_UNESCAPED_UNICODE).PHP_EOL;

$sum = ServiceRecord::query()
    ->where('employee_id', $r->employee_id)
    ->whereBetween('service_date', ['2026-07-01', '2026-07-31'])
    ->sum('profit');
echo 'SUM_PROFIT='.$sum.' HALF='.Money::percentOf($sum, 50).PHP_EOL;
