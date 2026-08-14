<?php

/**
 * One-off import: Catatan Servis Zulkifli @ Sawai (Juli 2026).
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
    $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
    if ($s === '' || ! is_numeric($s)) {
        throw new RuntimeException("Nominal tidak valid: {$raw}");
    }

    return number_format((float) $s, 2, '.', '');
}

function parseDate(string $raw): string
{
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
02/07/26,VIVO,Y12,LCD,"Rp130.000,00","Rp550.000,00","Rp420.000,00"
05/07/26,REDMI,NOTE 9,LCD,"Rp130.000,00","Rp550.000,00","Rp420.000,00"
05/07/26,VIVO,V20 4G,LCD,"Rp150.000,00","Rp700.000,00","Rp550.000,00"
06/07/26,VIVO,Y36,LCD,"Rp160.000,00","Rp600.000,00","Rp440.000,00"
06/07/26,OPPO,A18,LCD,"Rp135.000,00","Rp600.000,00","Rp465.000,00"
06/07/26,TECNO,SPARK GO 1,LCD,"Rp160.000,00","Rp600.000,00","Rp440.000,00"
06/07/26,REALME,NOTE 60,LCD,"Rp150.000,00","Rp600.000,00","Rp450.000,00"
06/07/26,VIVO,Y02,LCD,"Rp125.000,00","Rp500.000,00","Rp375.000,00"
06/07/26,OPPO,A18,LCD,"Rp135.000,00","Rp550.000,00","Rp415.000,00"
10/07/26,VIVO,S1 PRO,LCD,"Rp165.000,00","Rp650.000,00","Rp485.000,00"
10/07/26,INFINIX,SMART 6,BACKDOOR,"Rp45.000,00","Rp200.000,00","Rp155.000,00"
10/07/26,INFINIX,SMART 6,LCD,"Rp140.000,00","Rp500.000,00","Rp360.000,00"
12/07/26,REDMI,NOTE 10,BATTERAI,"Rp110.000,00","Rp500.000,00","Rp390.000,00"
14/07/26,VIVO,Y20,LCD,"Rp150.000,00","Rp650.000,00","Rp500.000,00"
15/07/26,OPPO,A3X,LCD,"Rp140.000,00","Rp600.000,00","Rp460.000,00"
19/07/26,REALME,C71,BATTERAI,"Rp155.000,00","Rp350.000,00","Rp195.000,00"
20/07/26,INFINIX,HOT 30I,LCD,"Rp130.000,00","Rp600.000,00","Rp470.000,00"
20/07/26,OPPO,A52,LCD,"Rp130.000,00","Rp550.000,00","Rp420.000,00"
24/07/26,VIVO,V17,LCD,"Rp175.000,00","Rp650.000,00","Rp475.000,00"
27/07/26,SAMSUNG,A50,BATTERAI,"Rp125.000,00","Rp350.000,00","Rp225.000,00"
27/07/26,REDMI,NOTE 10,LCD,"Rp160.000,00","Rp600.000,00","Rp440.000,00"
29/07/26,OPPO,A17K,LCD,"Rp135.000,00","Rp500.000,00","Rp365.000,00"
CSV;

$tech = Employee::query()
    ->where('name', 'ilike', 'Zulkifli')
    ->whereHas('branch', fn ($q) => $q->where('name', 'ilike', 'Sawai'))
    ->first();

if (! $tech || ! $tech->isTechnician()) {
    fwrite(STDERR, "Teknisi Zulkifli (Sawai) tidak ditemukan / bukan teknisi.\n");
    exit(1);
}

$admin = User::query()
    ->where('role', 'admin')
    ->where('branch_id', $tech->branch_id)
    ->first();

if (! $admin) {
    fwrite(STDERR, "Admin cabang Sawai tidak ditemukan.\n");
    exit(1);
}

echo "EMPLOYEE={$tech->id}|{$tech->name}|branch={$tech->branch_id}\n";
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
        'branch_id' => (int) $tech->branch_id,
        'employee_id' => (int) $tech->id,
        'user_id' => (int) $admin->id,
        'service_date' => parseDate($dateRaw),
        'brand' => trim($brand),
        'device_type' => trim($type),
        'damage' => trim($damage),
        'cost' => $cost,
        'price' => $price,
        'profit' => $profit,
        'notes' => 'Import CSV Juli 2026 Sawai',
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

echo 'PARSED='.count($rows)."\n";

$marker = 'Import CSV Juli 2026 Sawai';
$existing = ServiceRecord::query()
    ->where('employee_id', $tech->id)
    ->where('branch_id', $tech->branch_id)
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
    ->where('employee_id', $tech->id)
    ->where('notes', $marker)
    ->count();

$sumProfit = ServiceRecord::query()
    ->where('employee_id', $tech->id)
    ->where('notes', $marker)
    ->sum('profit');

echo "INSERTED={$inserted}\n";
echo "SUM_PROFIT={$sumProfit}\n";
echo "DONE\n";
