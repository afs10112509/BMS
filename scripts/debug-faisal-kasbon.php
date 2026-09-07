<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Category;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Transaction;
use App\Services\Payroll\PayrollCalculator;
use Illuminate\Support\Facades\DB;

$now = now(config('app.timezone'));
$year = (int) ($argv[1] ?? $now->year);
$month = (int) ($argv[2] ?? $now->month);
$from = sprintf('%04d-%02d-01', $year, $month);
$to = date('Y-m-t', strtotime($from));

echo "PERIODE={$from}..{$to} TZ=".config('app.timezone')."\n\n";

$emps = Employee::query()
    ->with('branch:id,name')
    ->whereRaw("LOWER(name) LIKE ?", ['%faisal%'])
    ->get(['id', 'name', 'phone', 'branch_id', 'status', 'positions', 'position']);

echo "=== KARYAWAN FAISAL ===\n";
foreach ($emps as $e) {
    echo "id={$e->id}|{$e->name}|cabang=".($e->branch?->name)."|status={$e->status}|jabatan={$e->position}\n";
}
if ($emps->isEmpty()) {
    echo "(tidak ada)\n";
}

$cats = Category::query()
    ->whereRaw("LOWER(name) LIKE 'kasbon%'")
    ->orderBy('name')
    ->get(['id', 'name', 'branch_id', 'type', 'is_active']);

echo "\n=== KATEGORI KASBON* ===\n";
foreach ($cats as $c) {
    echo "id={$c->id}|{$c->name}|branch=".($c->branch_id ?? 'global')."|active=".(($c->is_active ?? true) ? '1' : '0')."\n";
}

$faisalCats = $cats->filter(function ($c) {
    return str_contains(mb_strtolower($c->name), 'faisal');
});

echo "\n=== KATEGORI MENGANDUNG FAISAL ===\n";
foreach ($faisalCats as $c) {
    echo "id={$c->id}|{$c->name}\n";
}

$calc = app(PayrollCalculator::class);
$keys = [];
foreach ($emps as $e) {
    $ref = new ReflectionClass($calc);
    $m = $ref->getMethod('employeeKasbonKeys');
    $m->setAccessible(true);
    $k = $m->invoke($calc, $e->name);
    echo "keys {$e->name} => ".json_encode($k, JSON_UNESCAPED_UNICODE)."\n";
    $keys[$e->id] = $k;
}

echo "\n=== TRANSAKSI KASBON PERIODE (kategori nama ~ faisal ATAU semua kasbon amount kecil) ===\n";
$catIds = $cats->pluck('id');
$tx = Transaction::withoutGlobalScopes()
    ->with(['category:id,name', 'branch:id,name'])
    ->whereIn('category_id', $catIds)
    ->whereDate('transaction_date', '>=', $from)
    ->whereDate('transaction_date', '<=', $to)
    ->orderBy('transaction_date')
    ->get(['id', 'branch_id', 'category_id', 'amount', 'description', 'transaction_date']);

$byCat = [];
foreach ($tx as $t) {
    $cn = $t->category?->name ?? '?';
    $byCat[$cn] = ($byCat[$cn] ?? 0) + (float) $t->amount;
    $low = mb_strtolower($cn.' '.$t->description);
    if (str_contains($low, 'faisal') || (float) $t->amount <= 10) {
        echo "tx={$t->id}|{$t->transaction_date->toDateString()}|{$t->branch?->name}|{$cn}|amount={$t->amount}|desc=".str_replace("\n", ' ', (string) $t->description)."\n";
    }
}

echo "\n=== SUM KASBON PER KATEGORI PERIODE ===\n";
arsort($byCat);
foreach ($byCat as $name => $sum) {
    echo number_format($sum, 2, '.', '')."|{$name}\n";
}

echo "\n=== AUTO KASBON PAYROLL FAISAL ===\n";
if ($emps->isNotEmpty()) {
    $auto = $calc->computeAutoBatch($emps, $year, $month);
    foreach ($emps as $e) {
        $row = $auto[$e->id] ?? [];
        echo "{$e->name}|kasbon_auto=".($row['kasbon'] ?? '?')."\n";
    }
}

echo "\n=== PAYROLL TERSIMPAN FAISAL ===\n";
$pays = Payroll::query()
    ->whereIn('employee_id', $emps->pluck('id')->all() ?: [0])
    ->where('year', $year)
    ->where('month', $month)
    ->get();
foreach ($pays as $p) {
    echo "emp={$p->employee_id}|pengeluaran={$p->pengeluaran}|hutang={$p->hutang}|status={$p->status}\n";
}

echo "\n=== MATCH: suffix kategori vs keys karyawan (semua karyawan, kategori yang nyangkut faisal) ===\n";
$allEmps = Employee::query()->where('status', 'active')->get(['id', 'name']);
$ref = new ReflectionClass($calc);
$suffixM = $ref->getMethod('kasbonCategorySuffix');
$suffixM->setAccessible(true);
$keyM = $ref->getMethod('employeeKasbonKeys');
$keyM->setAccessible(true);

foreach ($cats as $c) {
    $suffix = $suffixM->invoke($calc, $c->name);
    $hit = [];
    foreach ($allEmps as $e) {
        $ks = $keyM->invoke($calc, $e->name);
        if (in_array($suffix, $ks, true)) {
            $hit[] = $e->name;
        }
    }
    if ($hit !== [] && (str_contains(mb_strtolower($c->name), 'faisal') || count($hit) > 1 && $suffix === 'faisal')) {
        echo "cat={$c->name}|suffix={$suffix}|employees=".implode(', ', $hit)."\n";
    }
}

echo "\n=== BRANCHES ===\n";
foreach (\App\Models\Branch::query()->orderBy('id')->get(['id', 'name']) as $b) {
    echo "branch {$b->id}|{$b->name}\n";
}

echo "\n=== EMPLOYEES ===\n";
foreach (Employee::query()->with('branch:id,name')->orderBy('name')->get(['id', 'name', 'branch_id', 'status']) as $e) {
    echo "emp {$e->id}|{$e->name}|".($e->branch?->name)."|{$e->status}\n";
}

echo "\n=== TX LIFETIME KASBON FAISAL (39 & 44) ===\n";
$rows = Transaction::withoutGlobalScopes()
    ->whereIn('category_id', [39, 44])
    ->selectRaw('category_id, count(*) n, coalesce(sum(amount),0) total, min(transaction_date) dmin, max(transaction_date) dmax')
    ->groupBy('category_id')
    ->get();
foreach ($rows as $r) {
    echo "cat={$r->category_id}|n={$r->n}|sum={$r->total}|{$r->dmin}..{$r->dmax}\n";
}

echo "\n=== PAYROLL AUG pengeluaran kecil / Akhsan ===\n";
foreach (Payroll::query()->with('employee:id,name')->where('year', 2026)->where('month', 8)->get() as $p) {
    $nm = mb_strtolower($p->employee?->name ?? '');
    if ((float) $p->pengeluaran <= 10 || str_contains($nm, 'faisal') || str_contains($nm, 'akhsan')) {
        echo "emp={$p->employee_id}|".($p->employee?->name)."|keluar={$p->pengeluaran}|hutang={$p->hutang}\n";
    }
}

echo "\nDONE\n";
