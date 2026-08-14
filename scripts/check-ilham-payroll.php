<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\ServiceRecord;
use App\Services\Payroll\PayrollCalculator;
use App\Support\Money;

$e = Employee::query()->where('name', 'ilike', 'Ilham')->firstOrFail();
$sum = ServiceRecord::query()
    ->where('employee_id', $e->id)
    ->whereBetween('service_date', ['2026-07-01', '2026-07-31'])
    ->sum('profit');
$cnt = ServiceRecord::query()
    ->where('employee_id', $e->id)
    ->whereBetween('service_date', ['2026-07-01', '2026-07-31'])
    ->count();

$p = Payroll::query()->where('employee_id', $e->id)->where('year', 2026)->where('month', 7)->first();

$calc = new PayrollCalculator;
$auto = $calc->computeAutoBatch(collect([$e]), 2026, 7)[$e->id];

echo "ILHAM_ID={$e->id}\n";
echo "SERVICE_CNT={$cnt}\n";
echo "SUM_PROFIT={$sum}\n";
echo 'HALF='.Money::percentOf($sum, 50)."\n";
echo 'AUTO_PROFIT='.$auto['service_profit']."\n";
echo 'AUTO_INCENTIVE='.$auto['service_incentive']."\n";
echo 'PAYROLL='.json_encode($p?->only([
    'id', 'status', 'service_profit', 'service_incentive', 'total', 'insentif_acc', 'gapok', 'insentif_hp',
]), JSON_UNESCAPED_UNICODE)."\n";
