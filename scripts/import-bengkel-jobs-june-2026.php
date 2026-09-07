<?php

/**
 * Import job upah bengkel Juni 2026 (spreadsheet).
 * Kolom BENGKEL = job_type.
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkshopJob;
use Illuminate\Support\Facades\DB;

$branch = Branch::query()
    ->where(function ($q) {
        $q->where('name', 'ilike', 'Bengkel Belawa')
            ->orWhere('name', 'ilike', 'Bengkel');
    })
    ->orderByRaw("CASE WHEN name ILIKE 'Bengkel Belawa' THEN 0 ELSE 1 END")
    ->first();

if (! $branch) {
    fwrite(STDERR, "Cabang Bengkel tidak ditemukan.\n");
    exit(1);
}

$admin = User::query()
    ->where('role', 'admin')
    ->where('branch_id', $branch->id)
    ->first()
    ?: User::query()->where('role', 'owner')->first();

if (! $admin) {
    fwrite(STDERR, "User input_by tidak ditemukan.\n");
    exit(1);
}

$emps = Employee::query()
    ->where('branch_id', $branch->id)
    ->where('status', 'active')
    ->get()
    ->keyBy(fn ($e) => mb_strtoupper(trim($e->name)));

$need = ['ASWAR', 'IKUL', 'APIP', 'SALIM', 'TEO', 'ROBI', 'ARIS'];
foreach ($need as $n) {
    if (! $emps->has($n)) {
        fwrite(STDERR, "Karyawan {$n} tidak ada di cabang {$branch->name}.\n");
        exit(1);
    }
}

echo "BRANCH={$branch->id}|{$branch->name}\n";
echo "INPUT_BY={$admin->id}|{$admin->email}\n";

// date|job_type|employee|amount
$raw = <<<'CSV'
24-6-2026|ONGKER|SALIM|80000
24-6-2026|ONGKER|IKUL|30000
24-6-2026|ONGKER|IKUL|5000
24-6-2026|GANTI BAN DALAM|APIP|40000
25-6-2026|GANTI KAMPAS|SALIM|20000
25-6-2026|ONGKER|SALIM|25000
25-6-2026|GANTI OLI|SALIM|20000
25-6-2026|GANTI OLI|APIP|10000
25-6-2026|ONGKER|SALIM|25000
25-6-2026|GANTI KAMPAS|SALIM|20000
25-6-2026|GANTI KAMPAS|APIP|20000
25-6-2026|GANTI LAHAR|APIP|20000
25-6-2026|ONGKER|SALIM|2000
25-6-2026|GANTI KAMPAS|SALIM|10000
25-6-2026|GANTI BAN DALAM|SALIM|15000
25-6-2026|ONGKER|SALIM|50000
25-6-2026|ONGKER|APIP|50000
25-6-2026|ONGKER|SALIM|10000
26-6-2026|ONGKER|APIP|15000
26-6-2026|ONGKER|SALIM|20000
26-6-2026|ONGKER|IKUL|20000
26-6-2026|ONGKER|SALIM|70000
26-6-2026|ONGKER|APIP|80000
26-6-2026|GANTI OLI|APIP|10000
26-6-2026|ONGKER|IKUL|50000
26-6-2026|GANTI KAMPAS|SALIM|20000
26-6-2026|GANTI BAN DALAM|SALIM|15000
26-6-2026|ONGKER|APIP|30000
26-6-2026|GANTI BAN DALAM|APIP|40000
27-6-2026|GANTI KAMPAS|IKUL|25000
27-6-2026|GANTI OLI|APIP|20000
28-6-2026|ONGKER|APIP|30000
28-6-2026|GANTI BAN DALAM|APIP|40000
28-6-2026|ONGKER|SALIM|20000
28-6-2026|GANTI OLI|APIP|10000
28-6-2026|GANTI LAHAR|SALIM|20000
29-6-2026|GANTI OLI|SALIM|10000
29-6-2026|GANTI BAN DALAM|SALIM|15000
29-6-2026|ONGKER|APIP|30000
29-6-2026|ONGKER|IKUL|30000
29-6-2026|GANTI OLI|APIP|20000
29-6-2026|GANTI BAN DALAM|APIP|15000
30-6-2026|GANTI OLI|SALIM|20000
30-6-2026|GANTI OLI|APIP|20000
30-6-2026|ONGKER|SALIM|10000
30-6-2026|ONGKER|IKUL|400000
30-6-2026|ONGKER|SALIM|80000
CSV;

$marker = 'Import spreadsheet Juni 2026';
$existing = WorkshopJob::withoutGlobalScopes()
    ->where('branch_id', $branch->id)
    ->where('note', $marker)
    ->count();

if ($existing > 0) {
    echo "SKIP: sudah ada {$existing} job dengan note '{$marker}'. Hapus dulu jika ingin import ulang.\n";
    exit(0);
}

$created = 0;
$sum = 0.0;

DB::transaction(function () use ($raw, $emps, $branch, $admin, $marker, &$created, &$sum) {
    foreach (preg_split("/\r\n|\n|\r/", trim($raw)) as $line) {
        if ($line === '') {
            continue;
        }
        [$d, $type, $name, $amount] = explode('|', $line);
        $parts = explode('-', $d);
        if (count($parts) !== 3) {
            throw new RuntimeException("Tanggal invalid: {$d}");
        }
        [$day, $month, $year] = $parts;
        $jobDate = sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
        $key = mb_strtoupper(trim($name));
        $emp = $emps->get($key);
        if (! $emp) {
            throw new RuntimeException("Teknisi tidak ditemukan: {$name}");
        }

        WorkshopJob::withoutGlobalScopes()->create([
            'branch_id' => $branch->id,
            'employee_id' => $emp->id,
            'job_date' => $jobDate,
            'job_type' => trim($type),
            'amount' => number_format((float) $amount, 2, '.', ''),
            'note' => $marker,
            'input_by' => $admin->id,
        ]);
        $created++;
        $sum += (float) $amount;
    }
});

echo "CREATED={$created}\n";
echo 'SUM_AMOUNT='.number_format($sum, 2, '.', '')."\n";
echo "DONE\n";
