<?php

/**
 * Import pemasukan konter Waebulen Juli 2026 dari spreadsheet.
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
    $fallback = 'c:\\Users\\62823\\Downloads\\Untitled spreadsheet (2).xlsx';
    $xlsx = is_file($fallback) ? $fallback : null;
}
if (! $xlsx || ! is_file($xlsx)) {
    fwrite(STDERR, "File Excel tidak ditemukan.\n");
    exit(1);
}

$branch = Branch::query()->where('name', 'ilike', '%waebulen%')->first();
if (! $branch) {
    fwrite(STDERR, "Cabang Waebulen tidak ditemukan.\n");
    exit(1);
}

$admin = User::query()
    ->where('role', 'admin')
    ->where('branch_id', $branch->id)
    ->first()
    ?: User::query()->where('role', 'owner')->first();

if (! $admin) {
    fwrite(STDERR, "User input tidak ditemukan.\n");
    exit(1);
}

$cash = Account::query()->where('code', 'cash')->orWhere('name', 'ilike', 'cash')->first();
if (! $cash) {
    fwrite(STDERR, "Akun Cash tidak ditemukan.\n");
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
    ->get();

$findCat = static function (string $want) use ($incomeCats): ?Category {
    $want = mb_strtolower(trim($want));
    foreach ($incomeCats as $c) {
        if (mb_strtolower(trim($c->name)) === $want) {
            return $c;
        }
    }
    foreach ($incomeCats as $c) {
        if (str_contains(mb_strtolower($c->name), $want) || str_contains($want, mb_strtolower($c->name))) {
            return $c;
        }
    }

    return null;
};

$mapLabel = static function (string $raw) use ($findCat): array {
    $label = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
    $upper = mb_strtoupper($label);

    if (str_contains($upper, 'PULSA')) {
        $cat = $findCat('Penjualan Pulsa') ?: $findCat('Pulsa');
        return [$cat, 'Pulsa', $label];
    }
    if (str_contains($upper, 'SECOND') || str_contains($upper, 'JUAL SECOND')) {
        $cat = $findCat('Penjualan') ?: $findCat('Penjualan HP') ?: $findCat('Lain-lain');
        return [$cat, 'Jual second', $label];
    }
    if (str_contains($upper, 'HP') || str_contains($upper, 'ACC') || str_contains($upper, 'PENJUALAN')) {
        $cat = $findCat('Penjualan HP') ?: $findCat('Penjualan');
        return [$cat, 'Penjualan HP ACC', $label];
    }

    $cat = $findCat($label) ?: $findCat('Lain-lain') ?: $findCat('Penjualan');
    return [$cat, $label, $label];
};

$marker = 'Import spreadsheet pemasukan Juli 2026 Waebulen';
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
        $dateRaw = $cells[1] ?? '';
        $amountRaw = $cells[2] ?? '';
        $label = trim((string) ($cells[3] ?? ''));
        if ($dateRaw === '' || $amountRaw === '' || $label === '' || ! is_numeric($amountRaw)) {
            continue;
        }
        $date = is_numeric($dateRaw) ? $excelDate($dateRaw) : trim((string) $dateRaw);
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

// Koreksi pola: 20 Juli ada 2x Pulsa; nominal besar seharusnya Penjualan HP ACC.
foreach ($rows as $i => $r) {
    if ($r['date'] === '2026-07-20'
        && mb_stripos($r['label'], 'pulsa') !== false
        && $r['amount'] >= 1000000) {
        $rows[$i]['label'] = 'Penjualan HP ACC';
        $rows[$i]['_fixed'] = true;
    }
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
        if (! empty($r['_fixed'])) {
            $desc .= ' (koreksi dari label Pulsa)';
        } elseif (mb_strtoupper($raw) !== mb_strtoupper($nice)) {
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
