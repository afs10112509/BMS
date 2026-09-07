<?php

/**
 * Import job upah bengkel Juli 2026 dari spreadsheet Excel.
 * Kolom: TANGGAL | KERJA | ASWAR | IKUL | APIP | SALIM | TEO | ROBI | SALMAN
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkshopJob;
use App\Models\WorkshopJobType;
use Illuminate\Support\Facades\DB;

$xlsx = $argv[1] ?? (getenv('WW_XLSX') ?: null);
if (! $xlsx || ! is_file($xlsx)) {
    $fallback = 'c:\\Users\\62823\\Downloads\\Untitled spreadsheet (1).xlsx';
    $xlsx = is_file($fallback) ? $fallback : null;
}
if (! $xlsx || ! is_file($xlsx)) {
    fwrite(STDERR, "File Excel tidak ditemukan. Usage: php scripts/import-bengkel-jobs-july-2026.php /path/to/file.xlsx\n");
    exit(1);
}

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

$need = ['ASWAR', 'IKUL', 'APIP', 'SALIM', 'TEO', 'ROBI', 'SALMAN'];
foreach ($need as $n) {
    if (! $emps->has($n)) {
        fwrite(STDERR, "Karyawan {$n} tidak ada di cabang {$branch->name}.\n");
        exit(1);
    }
}

$marker = 'Import spreadsheet Juli 2026';
$existing = WorkshopJob::withoutGlobalScopes()
    ->where('branch_id', $branch->id)
    ->where('note', $marker)
    ->count();

if ($existing > 0) {
    echo "SKIP: sudah ada {$existing} job dengan note '{$marker}'. Hapus dulu jika ingin import ulang.\n";
    exit(0);
}

/**
 * @return list<array{date:string,type:string,employee:string,amount:float}>
 */
function parseWorkshopXlsx(string $path): array
{
    $zip = new ZipArchive;
    if ($zip->open($path) !== true) {
        throw new RuntimeException("Gagal membuka Excel: {$path}");
    }

    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss) {
        $sx = simplexml_load_string($ss);
        foreach ($sx->si as $si) {
            if (isset($si->t)) {
                $shared[] = (string) $si->t;
            } else {
                $t = '';
                foreach ($si->r as $r) {
                    $t .= (string) $r->t;
                }
                $shared[] = $t;
            }
        }
    }

    $colToNum = static function (string $col): int {
        $n = 0;
        foreach (str_split($col) as $c) {
            $n = $n * 26 + (ord($c) - 64);
        }

        return $n;
    };

    $excelDate = static function ($n): string {
        return gmdate('Y-m-d', (int) round(((float) $n - 25569) * 86400));
    };

    $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
    $headers = [];
    $jobs = [];

    foreach ($xml->sheetData->row as $row) {
        $rnum = (int) $row['r'];
        $cells = [];
        foreach ($row->c as $c) {
            preg_match('/([A-Z]+)/', (string) $c['r'], $m);
            $ci = $colToNum($m[1]);
            $t = (string) $c['t'];
            $v = isset($c->v) ? (string) $c->v : '';
            if ($t === 's') {
                $v = $shared[(int) $v] ?? '';
            }
            $cells[$ci] = $v;
        }

        if ($rnum === 1) {
            $headers = $cells;
            continue;
        }

        $dateRaw = $cells[1] ?? '';
        $type = WorkshopJobType::normalizeName((string) ($cells[2] ?? ''));
        if ($dateRaw === '' || $type === '') {
            continue;
        }

        $date = is_numeric($dateRaw) ? $excelDate($dateRaw) : trim((string) $dateRaw);

        for ($ci = 3; $ci <= 20; $ci++) {
            if (! isset($headers[$ci])) {
                continue;
            }
            $amt = $cells[$ci] ?? '';
            if ($amt === '' || ! is_numeric($amt) || (float) $amt <= 0) {
                continue;
            }
            $emp = mb_strtoupper(trim((string) $headers[$ci]));
            if ($emp === '') {
                continue;
            }
            $jobs[] = [
                'date' => $date,
                'type' => $type,
                'employee' => $emp,
                'amount' => (float) $amt,
            ];
        }
    }

    $zip->close();

    return $jobs;
}

$jobs = parseWorkshopXlsx($xlsx);
if ($jobs === []) {
    fwrite(STDERR, "Tidak ada baris job di Excel.\n");
    exit(1);
}

echo "BRANCH={$branch->id}|{$branch->name}\n";
echo "INPUT_BY={$admin->id}|{$admin->email}\n";
echo "XLSX={$xlsx}\n";
echo 'PARSED='.count($jobs)."\n";

$created = 0;
$sum = 0.0;

DB::transaction(function () use ($jobs, $emps, $branch, $admin, $marker, &$created, &$sum) {
    foreach ($jobs as $job) {
        $emp = $emps->get($job['employee']);
        if (! $emp) {
            throw new RuntimeException('Teknisi tidak ditemukan: '.$job['employee']);
        }

        WorkshopJob::withoutGlobalScopes()->create([
            'branch_id' => $branch->id,
            'employee_id' => $emp->id,
            'job_date' => $job['date'],
            'job_type' => $job['type'],
            'amount' => number_format($job['amount'], 2, '.', ''),
            'note' => $marker,
            'input_by' => $admin->id,
        ]);
        $created++;
        $sum += $job['amount'];
    }
});

echo "CREATED={$created}\n";
echo 'SUM_AMOUNT='.number_format($sum, 2, '.', '')."\n";
echo "DONE\n";
