<?php

/**
 * Bandingkan pengeluaran/hutang gaji Juli vs transaksi kategori Kasbon.
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Category;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

$year = 2026;
$month = 7;
$from = sprintf('%04d-%02d-01', $year, $month);
$to = date('Y-m-t', strtotime($from));

$payrolls = Payroll::withoutGlobalScopes()
    ->with(['employee:id,name,position,branch_id', 'branch:id,name'])
    ->where('year', $year)
    ->where('month', $month)
    ->orderBy('branch_id')
    ->get();

$kasbonCats = Category::query()
    ->where('type', 'expense')
    ->whereRaw("LOWER(name) LIKE 'kasbon%'")
    ->get(['id', 'name', 'branch_id']);

echo "KASBON_CATEGORIES:\n";
foreach ($kasbonCats as $c) {
    echo "  {$c->id}|{$c->name}|branch=".($c->branch_id ?? 'global')."\n";
}

$txByCat = Transaction::withoutGlobalScopes()
    ->select('branch_id', 'category_id', DB::raw('COUNT(*) as n'), DB::raw('COALESCE(SUM(amount),0) as total'))
    ->whereIn('category_id', $kasbonCats->pluck('id'))
    ->whereDate('transaction_date', '>=', $from)
    ->whereDate('transaction_date', '<=', $to)
    ->groupBy('branch_id', 'category_id')
    ->get();

$catName = $kasbonCats->keyBy('id');
echo "\nKASBON_TX_JULI (per cabang×kategori):\n";
foreach ($txByCat as $r) {
    $cn = $catName[$r->category_id]->name ?? '?';
    echo "  branch={$r->branch_id}|{$cn}|n={$r->n}|sum={$r->total}\n";
}

// Map employee name → possible kasbon category names
$normalize = static function (string $s): string {
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

    return $s;
};

$employees = Employee::query()->get(['id', 'name', 'branch_id', 'position']);

echo "\nCOMPARE_PAYROLL_VS_KASBON:\n";
echo "cabang|karyawan|payroll_hutang|payroll_keluar|kasbon_tx_sum|match_hutang|match_keluar|match_sum|cat_hits\n";

$grandPayrollKeluar = 0.0;
$grandPayrollHutang = 0.0;
$grandKasbon = 0.0;
$matchedKeluar = 0;
$matchedHutang = 0;
$matchedSum = 0;

foreach ($payrolls as $p) {
    $emp = $p->employee;
    $empName = $emp?->name ?? '';
    $empNorm = $normalize($empName);
    $first = explode(' ', $empNorm)[0] ?? $empNorm;

    // Find kasbon categories that match this employee (by full name or first name)
    $matchedCats = $kasbonCats->filter(function ($c) use ($normalize, $empNorm, $first, $p) {
        $cn = $normalize($c->name);
        $cn = preg_replace('/^kasbon\s+/u', '', $cn) ?? $cn;
        if ($c->branch_id !== null && (int) $c->branch_id !== (int) $p->branch_id) {
            // allow global; skip other-branch local cats
            return false;
        }
        if ($cn === $empNorm || $cn === $first) {
            return true;
        }
        if (str_contains($empNorm, $cn) || str_contains($cn, $first)) {
            return true;
        }
        // aliases
        $aliases = [
            'awaluddin' => ['awal'],
            'gufroni' => ['roni'],
            'zulkifli' => ['uki', 'zuki'],
            'sitimarifat' => ['siti'],
        ];
        foreach ($aliases as $full => $als) {
            if (str_contains($empNorm, $full)) {
                foreach ($als as $a) {
                    if ($cn === $a || str_contains($cn, $a)) {
                        return true;
                    }
                }
            }
        }

        return false;
    });

    $catIds = $matchedCats->pluck('id')->all();
    $kasbonSum = 0.0;
    if ($catIds) {
        $kasbonSum = (float) Transaction::withoutGlobalScopes()
            ->where('branch_id', $p->branch_id)
            ->whereIn('category_id', $catIds)
            ->whereDate('transaction_date', '>=', $from)
            ->whereDate('transaction_date', '<=', $to)
            ->sum('amount');
    }

    $hutang = (float) $p->hutang;
    $keluar = (float) $p->pengeluaran;
    $sumDebit = $hutang + $keluar;

    $mH = abs($hutang - $kasbonSum) < 0.01 && $kasbonSum > 0;
    $mK = abs($keluar - $kasbonSum) < 0.01 && $kasbonSum > 0;
    $mS = abs($sumDebit - $kasbonSum) < 0.01 && $kasbonSum > 0;

    if ($mK) {
        $matchedKeluar++;
    }
    if ($mH) {
        $matchedHutang++;
    }
    if ($mS) {
        $matchedSum++;
    }

    $grandPayrollKeluar += $keluar;
    $grandPayrollHutang += $hutang;
    $grandKasbon += $kasbonSum;

    $hits = $matchedCats->pluck('name')->implode(',');
    echo implode('|', [
        $p->branch?->name ?? '',
        $empName,
        number_format($hutang, 2, '.', ''),
        number_format($keluar, 2, '.', ''),
        number_format($kasbonSum, 2, '.', ''),
        $mH ? 'YES' : 'no',
        $mK ? 'YES' : 'no',
        $mS ? 'YES' : 'no',
        $hits ?: '-',
    ]).PHP_EOL;
}

echo "\nTOTALS:\n";
echo 'payroll_hutang_all='.number_format($grandPayrollHutang, 2, '.', '')."\n";
echo 'payroll_keluar_all='.number_format($grandPayrollKeluar, 2, '.', '')."\n";
echo 'payroll_debit_all='.number_format($grandPayrollHutang + $grandPayrollKeluar, 2, '.', '')."\n";
echo 'kasbon_tx_matched_to_employees='.number_format($grandKasbon, 2, '.', '')."\n";

$allKasbon = (float) Transaction::withoutGlobalScopes()
    ->whereIn('category_id', $kasbonCats->pluck('id'))
    ->whereDate('transaction_date', '>=', $from)
    ->whereDate('transaction_date', '<=', $to)
    ->sum('amount');
echo 'kasbon_tx_all_branches='.number_format($allKasbon, 2, '.', '')."\n";
echo "match_count_keluar={$matchedKeluar}\n";
echo "match_count_hutang={$matchedHutang}\n";
echo "match_count_hutang+keluar={$matchedSum}\n";
echo 'payroll_rows='.$payrolls->count()."\n";
