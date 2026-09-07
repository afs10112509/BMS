<?php

/**
 * Import pemasukan cabang Bengkel dari spreadsheet (8).
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

$xlsx = $argv[1] ?? null;
if (! $xlsx || ! is_file($xlsx)) {
    $fallback = 'c:\\Users\\62823\\Downloads\\Untitled spreadsheet (8).xlsx';
    $xlsx = is_file($fallback) ? $fallback : null;
}
if (! $xlsx || ! is_file($xlsx)) {
    fwrite(STDERR, "File Excel tidak ditemukan.\n");
    exit(1);
}

$branch = Branch::query()->where('name', 'ilike', '%bengkel%')->first();
if (! $branch) {
    fwrite(STDERR, "Cabang Bengkel tidak ditemukan.\n");
    exit(1);
}

$admin = User::query()
    ->where('role', 'admin')
    ->where('branch_id', $branch->id)
    ->first()
    ?: User::query()->where('role', 'owner')->first();

$cash = Account::query()->where('code', 'cash')->orWhere('name', 'ilike', 'cash')->first();
if (! $admin || ! $cash) {
    fwrite(STDERR, "User/akun Cash tidak ditemukan.\n");
    exit(1);
}

$ensureIncome = static function (string $name, ?int $branchId = null) use ($branch): Category {
    $existing = Category::query()
        ->where('type', 'income')
        ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
        ->where(function ($q) use ($branchId, $branch) {
            if ($branchId === null) {
                $q->whereNull('branch_id')->orWhere('branch_id', $branch->id);
            } else {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            }
        })
        ->orderByRaw('CASE WHEN branch_id IS NULL THEN 0 ELSE 1 END')
        ->first();

    if ($existing) {
        if ($existing->is_active === false) {
            $existing->update(['is_active' => true]);
        }

        return $existing;
    }

    return Category::query()->create([
        'branch_id' => $branchId,
        'name' => $name,
        'type' => 'income',
        'is_active' => true,
    ]);
};

// Kategori khusus bengkel (boleh global agar konsisten).
$catSparepart = $ensureIncome('Penjualan Sparepart', null);
$catUangLebih = $ensureIncome('Uang Lebih', null);
$catUangTunai = $ensureIncome('Uang Tunai', null);

$resolve = static function (string $raw) use ($catSparepart, $catUangLebih, $catUangTunai): array {
    $label = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
    $u = mb_strtoupper($label);

    if (str_contains($u, 'SPAREPART') || str_contains($u, 'SPARE PART')) {
        return [$catSparepart, 'Penjualan Sparepart'];
    }
    if (str_contains($u, 'UANG LEBIH') || $u === 'LEBIH') {
        return [$catUangLebih, 'Uang Lebih'];
    }
    if (str_contains($u, 'UANG TUNAI') || $u === 'TUNAI') {
        return [$catUangTunai, 'Uang Tunai'];
    }

    throw new RuntimeException('Kategori tidak terpetakan: '.$raw);
};

$parseAmount = static function ($raw): ?float {
    if ($raw === null || $raw === '') {
        return null;
    }
    if (is_numeric($raw)) {
        return (float) $raw;
    }
    $s = trim((string) $raw);
    $s = str_replace(['Rp', 'rp', ' '], '', $s);
    if (str_contains($s, ',')) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    }
    if (! is_numeric($s)) {
        return null;
    }

    return (float) $s;
};

$parseDate = static function ($raw): ?string {
    if ($raw === null || $raw === '') {
        return null;
    }
    if (is_numeric($raw)) {
        return gmdate('Y-m-d', (int) round(((float) $raw - 25569) * 86400));
    }
    $s = trim((string) $raw);
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
        return substr($s, 0, 10);
    }
    // "10 - Mei - 26" / "10-Mei-2026"
    $months = [
        'jan' => 1, 'januari' => 1,
        'feb' => 2, 'februari' => 2,
        'mar' => 3, 'maret' => 3,
        'apr' => 4, 'april' => 4,
        'mei' => 5, 'may' => 5,
        'jun' => 6, 'juni' => 6,
        'jul' => 7, 'juli' => 7,
        'agu' => 8, 'ags' => 8, 'agustus' => 8, 'aug' => 8,
        'sep' => 9, 'sept' => 9, 'september' => 9,
        'okt' => 10, 'oktober' => 10, 'oct' => 10,
        'nov' => 11, 'november' => 11,
        'des' => 12, 'desember' => 12, 'dec' => 12,
    ];
    if (preg_match('/^(\d{1,2})\s*[-–\/]\s*([A-Za-z]+)\s*[-–\/]\s*(\d{2,4})$/u', $s, $m)) {
        $day = (int) $m[1];
        $monKey = mb_strtolower($m[2]);
        $year = (int) $m[3];
        if ($year < 100) {
            $year += 2000;
        }
        $month = $months[$monKey] ?? null;
        if (! $month || $day < 1 || $day > 31) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    return null;
};

$marker = 'Import spreadsheet pemasukan Bengkel';
$existing = Transaction::withoutGlobalScopes()
    ->where('branch_id', $branch->id)
    ->where('description', 'ilike', $marker.'%')
    ->count();
if ($existing > 0) {
    echo "SKIP: sudah ada {$existing} transaksi dengan marker import.\n";
    exit(0);
}

$zip = new ZipArchive;
if ($zip->open($xlsx) !== true) {
    fwrite(STDERR, "Gagal membuka Excel.\n");
    exit(1);
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
$xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
$rows = [];
foreach ($xml->sheetData->row as $row) {
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
    $joined = mb_strtolower(implode('|', $cells));
    if (str_contains($joined, 'tanggal') || str_contains($joined, 'nominal') || str_contains($joined, 'jenis akun')) {
        continue;
    }
    $date = $parseDate($cells[1] ?? '');
    $amount = $parseAmount($cells[2] ?? '');
    $label = trim((string) ($cells[3] ?? ''));
    if (! $date || $amount === null || $label === '') {
        continue;
    }
    $rows[] = [
        'date' => $date,
        'amount' => $amount,
        'label' => $label,
    ];
}
$zip->close();

if ($rows === []) {
    fwrite(STDERR, "Tidak ada baris valid di Excel.\n");
    exit(1);
}

echo "BRANCH={$branch->id}|{$branch->name}\n";
echo "INPUT_BY={$admin->id}|{$admin->email}\n";
echo "ACCOUNT={$cash->id}|{$cash->name}\n";
echo 'CATS=Sparepart#'.$catSparepart->id.'|UangLebih#'.$catUangLebih->id.'|UangTunai#'.$catUangTunai->id."\n";
echo 'PARSED='.count($rows)."\n";

$created = 0;
$sum = 0.0;

DB::transaction(function () use ($rows, $resolve, $branch, $admin, $cash, $marker, &$created, &$sum) {
    foreach ($rows as $r) {
        [$cat, $nice] = $resolve($r['label']);
        $desc = $marker.' | '.$nice;
        if (mb_strtoupper($r['label']) !== mb_strtoupper($nice)
            && mb_strtoupper($r['label']) !== mb_strtoupper(str_replace('Penjualan ', '', $nice))) {
            $desc .= ' | sumber: '.$r['label'];
        }

        Transaction::withoutGlobalScopes()->create([
            'branch_id' => $branch->id,
            'user_id' => $admin->id,
            'category_id' => $cat->id,
            'account_id' => $cash->id,
            'amount' => Money::of($r['amount']),
            'description' => $desc,
            'transaction_date' => $r['date'],
        ]);
        $created++;
        $sum += $r['amount'];
        echo "OK {$r['date']}|{$cat->name}|{$r['amount']}\n";
    }
});

echo "CREATED={$created}\n";
echo 'SUM_AMOUNT='.number_format($sum, 2, '.', '')."\n";
echo "DONE\n";
