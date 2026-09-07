<?php

/**
 * Sinkron kolom pengeluaran (kasbon) pada slip draf Juli dari transaksi.
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Employee;
use App\Models\Payroll;
use App\Services\Payroll\PayrollCalculator;
use App\Support\Money;

$year = (int) ($argv[1] ?? 2026);
$month = (int) ($argv[2] ?? 7);

$calc = app(PayrollCalculator::class);
$payrolls = Payroll::withoutGlobalScopes()
    ->where('year', $year)
    ->where('month', $month)
    ->where('status', Payroll::STATUS_DRAFT)
    ->get();

$emps = Employee::query()
    ->whereIn('id', $payrolls->pluck('employee_id'))
    ->get()
    ->keyBy('id');

$auto = $calc->computeAutoBatch($emps->values(), $year, $month);
$updated = 0;

foreach ($payrolls as $p) {
    $kasbon = (float) ($auto[$p->employee_id]['kasbon'] ?? 0);
    $total = Payroll::computeTotal(
        $p->gapok,
        $p->insentif_hp,
        $p->service_incentive,
        $p->insentif_acc,
        $p->bonus_absen,
        $p->hutang,
        $kasbon,
    );
    $old = (float) $p->pengeluaran;
    $p->pengeluaran = Money::of($kasbon);
    $p->total = Money::of($total);
    $p->save();
    $updated++;
    $name = $emps[$p->employee_id]->name ?? $p->employee_id;
    echo "OK {$name}|old={$old}|kasbon={$kasbon}|total={$total}\n";
}

echo "UPDATED={$updated}\n";
