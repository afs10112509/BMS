<?php

/**
 * One-off import: Catatan Servis Ilham @ Waebulen (Juli 2026).
 */
require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Employee;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

function parseRp(?string $raw): string
{
    $s = trim((string) $raw);
    $s = str_replace(['Rp', 'rp', '"', ' '], '', $s);
    // "125.000,00" → 125000.00
    $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
    if ($s === '' || ! is_numeric($s)) {
        throw new RuntimeException("Nominal tidak valid: {$raw}");
    }

    return number_format((float) $s, 2, '.', '');
}

function parseDate(string $raw): string
{
    // DD/MM/YY
    $parts = explode('/', trim($raw));
    if (count($parts) !== 3) {
        throw new RuntimeException("Tanggal tidak valid: {$raw}");
    }
    [$d, $m, $y] = $parts;
    $year = (int) $y;
    if ($year < 100) {
        $year += 2000;
    }

    return sprintf('%04d-%02d-%02d', $year, (int) $m, (int) $d);
}

$csv = <<<'CSV'
05/07/26,VIVO,Y19S,LCD,"Rp125.000,00","Rp600.000,00","Rp475.000,00"
09/07/26,INFINIX,HOT 50,LCD,"Rp150.000,00","Rp650.000,00","Rp500.000,00"
10/07/26,REDMI,NOTE 8,LCD,"Rp130.000,00","Rp600.000,00","Rp470.000,00"
10/07/26,INFINIX,SMART 8,LCD,"Rp120.000,00","Rp600.000,00","Rp480.000,00"
10/07/26,OPPO,A54,LEM LCD,"Rp0,00","Rp100.000,00","Rp100.000,00"
12/07/26,VIVO,Y19S,PASANG LCD,"Rp0,00","Rp250.000,00","Rp250.000,00"
13/07/26,OPPO,A12,BATRAI,"Rp125.000,00","Rp300.000,00","Rp175.000,00"
15/07/26,IPHONE,IP 7,LCD,"Rp130.000,00","Rp750.000,00","Rp620.000,00"
15/07/26,IPHONE,IP 7,CAMM DEPAN,"Rp75.000,00","Rp200.000,00","Rp125.000,00"
17/07/26,OPPO,A11K,LCD,"Rp130.000,00","Rp600.000,00","Rp470.000,00"
17/07/26,OPPO,A12,ON/OFF,"Rp20.000,00","Rp100.000,00","Rp80.000,00"
18/07/26,XIOMI,NOTE 13 P,LCD,"Rp170.000,00","Rp700.000,00","Rp530.000,00"
19/07/26,IPHONE,IP 13,BATRAI,"Rp200.000,00","Rp700.000,00","Rp500.000,00"
20/07/26,SAMSUNG,A05,LCD,"Rp150.000,00","Rp600.000,00","Rp450.000,00"
20/07/26,VIVO,Y15,LCD,"Rp125.000,00","Rp500.000,00","Rp375.000,00"
23/07/26,REDMI,12 RPO,LCD,"Rp180.000,00","Rp700.000,00","Rp520.000,00"
27/07/26,VIVO,Y20S,LCD,"Rp120.000,00","Rp550.000,00","Rp430.000,00"
31/07/26,INFINIX,SMART 9,LCD,"Rp135.000,00","Rp600.000,00","Rp465.000,00"
CSV;

$ilham = Employee::query()
    ->where('name', 'ilike', 'Ilham')
    ->whereHas('branch', fn ($q) => $q->where('name', 'ilike', 'Waebulen'))
    ->first();

if (! $ilham || ! $ilham->isTechnician()) {
    fwrite(STDERR, "Teknisi Ilham (Waebulen) tidak ditemukan / bukan teknisi.\n");
    exit(1);
}

$admin = User::query()
    ->where('role', 'admin')
    ->where('branch_id', $ilham->branch_id)
    ->first();

if (! $admin) {
    fwrite(STDERR, "Admin cabang Waebulen tidak ditemukan.\n");
    exit(1);
}

echo "EMPLOYEE={$ilham->id}|{$ilham->name}|branch={$ilham->branch_id}\n";
echo "USER={$admin->id}|{$admin->email}\n";

$rows = [];
foreach (preg_split("/\r\n|\n|\r/", trim($csv)) as $line) {
    if ($line === '') {
        continue;
    }
    $cols = str_getcsv($line);
    if (count($cols) < 7) {
        throw new RuntimeException("Baris tidak lengkap: {$line}");
    }
    [$dateRaw, $brand, $type, $damage, $modal, $harga, $total] = $cols;
    $cost = parseRp($modal);
    $price = parseRp($harga);
    $profit = Money::sub($price, $cost);
    $expected = parseRp($total);
    if (bccomp($profit, $expected, 2) !== 0) {
        echo "WARN profit mismatch {$brand} {$type}: calc={$profit} csv={$expected}\n";
    }
    $rows[] = [
        'branch_id' => (int) $ilham->branch_id,
        'employee_id' => (int) $ilham->id,
        'user_id' => (int) $admin->id,
        'service_date' => parseDate($dateRaw),
        'brand' => trim($brand),
        'device_type' => trim($type),
        'damage' => trim($damage),
        'cost' => $cost,
        'price' => $price,
        'profit' => $profit,
        'notes' => 'Import CSV Juli 2026',
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

echo 'PARSED='.count($rows)."\n";

$marker = 'Import CSV Juli 2026';
$existing = ServiceRecord::query()
    ->where('employee_id', $ilham->id)
    ->where('branch_id', $ilham->branch_id)
    ->where('notes', $marker)
    ->count();

if ($existing > 0) {
    echo "SKIP: sudah ada {$existing} baris dengan notes '{$marker}'. Hapus dulu jika ingin import ulang.\n";
    exit(0);
}

DB::transaction(function () use ($rows) {
    foreach ($rows as $row) {
        ServiceRecord::query()->create($row);
    }
});

$inserted = ServiceRecord::query()
    ->where('employee_id', $ilham->id)
    ->where('notes', $marker)
    ->count();

$sumProfit = ServiceRecord::query()
    ->where('employee_id', $ilham->id)
    ->where('notes', $marker)
    ->sum('profit');

echo "INSERTED={$inserted}\n";
echo "SUM_PROFIT={$sumProfit}\n";
echo "DONE\n";
