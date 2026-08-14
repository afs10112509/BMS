<?php

/**
 * Cek ulang: payroll.pengeluaran (Keluar/Kasbon) vs transaksi kategori Kasbon*.
 * Hutang diabaikan (manual).
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

$normalize = static function (string $s): string {
    $s = mb_strtolower(trim($s));

    return preg_replace('/\s+/u', ' ', $s) ?? $s;
};

$kasbonSuffix = static function (string $catName) use ($normalize): string {
    $n = $normalize($catName);
    $n = preg_replace('/^kasbon\s+/u', '', $n) ?? $n;

    return trim($n);
};

/** @return list<string> */
$empKeys = static function (string $name) use ($normalize): array {
    $n = $normalize($name);
    $first = explode(' ', $n)[0] ?? $n;
    $keys = array_unique(array_filter([$n, $first]));
    $aliases = [
        'awaluddin' => ['awal'],
        'gufroni' => ['roni'],
        'zulkifli' => ['uki', 'zuki'],
        'wirda safitri' => ['wirda'],
    ];
    foreach ($aliases as $full => $als) {
        if ($n === $full || str_starts_with($n, $full) || str_contains($n, $full)) {
            foreach ($als as $a) {
                $keys[] = $a;
            }
        }
    }

    return array_values(array_unique($keys));
};

$cats = Category::query()
    ->where('type', 'expense')
    ->whereRaw("LOWER(name) LIKE 'kasbon%'")
    ->get(['id', 'name', 'branch_id']);

$txRows = Transaction::withoutGlobalScopes()
    ->select('branch_id', 'category_id', DB::raw('COUNT(*) as n'), DB::raw('COALESCE(SUM(amount),0) as total'))
    ->whereIn('category_id', $cats->pluck('id'))
    ->whereDate('transaction_date', '>=', $from)
    ->whereDate('transaction_date', '<=', $to)
    ->groupBy('branch_id', 'category_id')
    ->get();

echo "=== SEMUA TRANSAKSI KASBON JULI (semua cabang) ===\n";
$grandKasbon = 0.0;
foreach ($txRows->sortBy(['branch_id', 'category_id']) as $r) {
    $cn = $cats->firstWhere('id', $r->category_id)?->name ?? '?';
    $grandKasbon += (float) $r->total;
    echo "branch={$r->branch_id}|{$cn}|n={$r->n}|sum={$r->total}\n";
}
echo 'GRAND_KASBON_TX='.number_format($grandKasbon, 2, '.', '')."\n\n";

$payrolls = Payroll::withoutGlobalScopes()
    ->with(['employee:id,name,branch_id', 'branch:id,name'])
    ->where('year', $year)
    ->where('month', $month)
    ->orderBy('branch_id')
    ->get();

echo "=== PER KARYAWAN: keluar gaji vs kasbon cabang sendiri vs kasbon semua cabang ===\n";
echo "cabang|karyawan|keluar_gaji|kasbon_cabang|kasbon_semua_cabang|match_cabang|match_semua|cats\n";

$sumKeluar = 0.0;
$sumKasbonBranch = 0.0;
$sumKasbonAll = 0.0;
$okBranch = 0;
$okAll = 0;

foreach ($payrolls as $p) {
    $emp = $p->employee;
    if (! $emp) {
        continue;
    }
    $keys = $empKeys($emp->name);
    $matched = $cats->filter(function ($c) use ($kasbonSuffix, $keys, $p) {
        $suffix = $kasbonSuffix($c->name);
        if (! in_array($suffix, $keys, true)) {
            // partial: key contained
            foreach ($keys as $k) {
                if ($suffix === $k || str_contains($suffix, $k) || str_contains($k, $suffix)) {
                    return $c->branch_id === null || (int) $c->branch_id === (int) $p->branch_id || true;
                }
            }

            return false;
        }

        return true;
    })->filter(function ($c) use ($kasbonSuffix, $keys) {
        $suffix = $kasbonSuffix($c->name);
        foreach ($keys as $k) {
            if ($suffix === $k || $suffix === explode(' ', $k)[0]) {
                return true;
            }
        }

        return false;
    });

    // tighter match
    $matched = $cats->filter(function ($c) use ($kasbonSuffix, $keys) {
        $suffix = $kasbonSuffix($c->name);
        foreach ($keys as $k) {
            if ($suffix === $k) {
                return true;
            }
        }

        return false;
    });

    $catIds = $matched->pluck('id')->all();
    $keluar = (float) $p->pengeluaran;

    $kasbonBranch = $catIds
        ? (float) Transaction::withoutGlobalScopes()
            ->where('branch_id', $p->branch_id)
            ->whereIn('category_id', $catIds)
            ->whereDate('transaction_date', '>=', $from)
            ->whereDate('transaction_date', '<=', $to)
            ->sum('amount')
        : 0.0;

    $kasbonAll = $catIds
        ? (float) Transaction::withoutGlobalScopes()
            ->whereIn('category_id', $catIds)
            ->whereDate('transaction_date', '>=', $from)
            ->whereDate('transaction_date', '<=', $to)
            ->sum('amount')
        : 0.0;

    $mB = abs($keluar - $kasbonBranch) < 0.01;
    $mA = abs($keluar - $kasbonAll) < 0.01;
    if ($mB) {
        $okBranch++;
    }
    if ($mA) {
        $okAll++;
    }

    $sumKeluar += $keluar;
    $sumKasbonBranch += $kasbonBranch;
    $sumKasbonAll += $kasbonAll;

    echo implode('|', [
        $p->branch?->name ?? '',
        $emp->name,
        number_format($keluar, 2, '.', ''),
        number_format($kasbonBranch, 2, '.', ''),
        number_format($kasbonAll, 2, '.', ''),
        $mB ? 'YES' : 'NO',
        $mA ? 'YES' : 'NO',
        $matched->pluck('name')->implode(',') ?: '-',
    ]).PHP_EOL;
}

echo "\n=== RINGKASAN ===\n";
echo 'sum_keluar_gaji='.number_format($sumKeluar, 2, '.', '')."\n";
echo 'sum_kasbon_per_karyawan_cabang='.number_format($sumKasbonBranch, 2, '.', '')."\n";
echo 'sum_kasbon_per_karyawan_semua_cabang='.number_format($sumKasbonAll, 2, '.', '')."\n";
echo 'grand_kasbon_tx_all='.number_format($grandKasbon, 2, '.', '')."\n";
echo "rows_match_cabang={$okBranch}/{$payrolls->count()}\n";
echo "rows_match_semua={$okAll}/{$payrolls->count()}\n";
echo 'diff_keluar_vs_kasbon_cabang='.number_format($sumKeluar - $sumKasbonBranch, 2, '.', '')."\n";
echo 'diff_keluar_vs_kasbon_semua='.number_format($sumKeluar - $sumKasbonAll, 2, '.', '')."\n";

// Employees with kasbon tx but no/zero keluar
echo "\n=== KARYAWAN ADA KASBON TX TAPI KELUAR≠KASBON (cabang) ===\n";
foreach ($payrolls as $p) {
    $emp = $p->employee;
    if (! $emp) {
        continue;
    }
    $keys = $empKeys($emp->name);
    $matched = $cats->filter(function ($c) use ($kasbonSuffix, $keys) {
        $suffix = $kasbonSuffix($c->name);
        foreach ($keys as $k) {
            if ($suffix === $k) {
                return true;
            }
        }

        return false;
    });
    $catIds = $matched->pluck('id')->all();
    $kasbonBranch = $catIds
        ? (float) Transaction::withoutGlobalScopes()
            ->where('branch_id', $p->branch_id)
            ->whereIn('category_id', $catIds)
            ->whereDate('transaction_date', '>=', $from)
            ->whereDate('transaction_date', '<=', $to)
            ->sum('amount')
        : 0.0;
    $keluar = (float) $p->pengeluaran;
    if (abs($keluar - $kasbonBranch) >= 0.01) {
        echo ($p->branch?->name)."|"."{$emp->name}|keluar={$keluar}|kasbon={$kasbonBranch}|delta=".($keluar - $kasbonBranch)."\n";
    }
}
