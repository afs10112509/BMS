<?php

/**
 * Import pemasukan konter Sawai Juli 2026 dari spreadsheet.
 * Kolom: tanggal | nominal | kategori | akun
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
    $fallback = 'c:\\Users\\62823\\Downloads\\Untitled spreadsheet (4).xlsx';
    $xlsx = is_file($fallback) ? $fallback : null;
}
if (! $xlsx || ! is_file($xlsx)) {
    fwrite(STDERR, "File Excel tidak ditemukan.\n");
    exit(1);
}

$branch = Branch::query()->where('name', 'ilike', '%sawai%')->first();
if (! $branch) {
    fwrite(STDERR, "Cabang Sawai tidak ditemukan.\n");
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

$incomeCats = Category::query()
    ->where('type', 'income')
    ->where(function ($q) use ($branch) {
        $q->whereNull('branch_id')->orWhere('branch_id', $branch->id);
    })
    ->where(function ($q) {
        $q->whereNull('is_active')->orWhere('is_active', true);
    })
    ->orderByRaw('CASE WHEN branch_id IS NULL THEN 0 ELSE 1 END')
    ->get();

$findCat = static function (string ...$wants) use ($incomeCats): ?Category {
    foreach ($wants as $want) {
        $want = mb_strtolower(trim($want));
        foreach ($incomeCats as $c) {
            if (mb_strtolower(trim($c->name)) === $want) {
                return $c;
            }
        }
    }
    foreach ($wants as $want) {
        $want = mb_strtolower(trim($want));
        foreach ($incomeCats as $c) {
            if (str_contains(mb_strtolower($c->name), $want)) {
                return $c;
            }
        }
    }

    return null;
};

$mapLabel = static function (string $raw) use ($findCat): array {
    $label = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
    $upper = mb_strtoupper($label);

    if (str_contains($upper, 'PULSA')) {
        $cat = $findCat('Penjualan Pulsa', 'Pulsa');

        return [$cat, 'Pulsa', $label];
    }
    if (str_contains($upper, 'SERVIS') || str_contains($upper, 'SERVICE')) {
        $cat = $findCat('Service', 'Servis');

        return [$cat, 'Service', $label];
    }
    if (str_contains($upper, 'HP') || str_contains($upper, 'ACC') || str_contains($upper, 'PENJUALAN')) {
        $cat = $findCat('Penjualan HP', 'Penjualan');

        return [$cat, 'Penjualan HP ACC', $label];
    }

    $cat = $findCat($label, 'Lain-lain', 'Penjualan');

    return [$cat, $label, $label];
};

$marker = 'Import spreadsheet pemasukan Juli 2026 Sawai';
$existing = Transaction::withoutGlobalScopes()
    ->where('branch_id', $branch->id)
    ->where('description', 'ilike', $marker.'%')
    ->count();
if ($existing > 0) {
    echo "SKIP: sudah ada {$existing} transaksi dengan marker import.\n";
    exit(0);
}

/**
 * @return list<array{date:string,amount:float,label:string}>
 */
function parseIncomeXlsx(string $path): array
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
    $out = [];
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
        if (str_contains($joined, 'kategori') || str_contains($joined, 'tanggal')) {
            continue;
        }
        $dateRaw = $cells[1] ?? '';
        $amountRaw = $cells[2] ?? '';
        $label = trim((string) ($cells[3] ?? ''));
        if ($dateRaw === '' || $amountRaw === '' || $label === '' || ! is_numeric($amountRaw)) {
            continue;
        }
        $date = is_numeric($dateRaw)
            ? $excelDate($dateRaw)
            : substr(trim((string) $dateRaw), 0, 10);
        $out[] = [
            'date' => $date,
            'amount' => (float) $amountRaw,
            'label' => $label,
        ];
    }
    $zip->close();

    return $out;
}

$rows = parseIncomeXlsx($xlsx);
if ($rows === []) {
    fwrite(STDERR, "Tidak ada baris di Excel.\n");
    exit(1);
}

echo "BRANCH={$branch->id}|{$branch->name}\n";
echo "INPUT_BY={$admin->id}|{$admin->email}\n";
echo "ACCOUNT={$cash->id}|{$cash->name}\n";
echo 'PARSED='.count($rows)."\n";

$created = 0;
$sum = 0.0;

DB::transaction(function () use ($rows, $mapLabel, $branch, $admin, $cash, $marker, &$created, &$sum) {
    foreach ($rows as $r) {
        [$cat, $nice, $raw] = $mapLabel($r['label']);
        if (! $cat) {
            throw new RuntimeException('Kategori tidak ditemukan untuk: '.$r['label']);
        }
        $desc = $marker.' | '.$nice;
        if (mb_strtoupper($raw) !== mb_strtoupper($nice)) {
            $desc .= ' | sumber: '.$raw;
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
