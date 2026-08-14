<?php

/**
 * Import pengeluaran konter Lukulamo Juli 2026 dari spreadsheet.
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
    $fallback = 'c:\\Users\\62823\\Downloads\\Untitled spreadsheet (7).xlsx';
    $xlsx = is_file($fallback) ? $fallback : null;
}
if (! $xlsx || ! is_file($xlsx)) {
    fwrite(STDERR, "File Excel tidak ditemukan.\n");
    exit(1);
}

$branch = Branch::query()->where('name', 'ilike', '%lukulamo%')->first();
if (! $branch) {
    fwrite(STDERR, "Cabang Lukulamo tidak ditemukan.\n");
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

$ensureExpense = static function (string $name, ?int $branchId = null) use ($branch): Category {
    $scopeBranch = $branchId;
    $existing = Category::query()
        ->where('type', 'expense')
        ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
        ->where(function ($q) use ($scopeBranch, $branch) {
            if ($scopeBranch === null) {
                $q->whereNull('branch_id')->orWhere('branch_id', $branch->id);
            } else {
                $q->where('branch_id', $scopeBranch)->orWhereNull('branch_id');
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
        'branch_id' => $scopeBranch,
        'name' => $name,
        'type' => 'expense',
        'is_active' => true,
    ]);
};

foreach (['Dapur', 'Listrik', 'Operasional', 'Sparepart', 'Tabungan', 'Isi Saldo', 'Kasbon Bahar'] as $n) {
    $ensureExpense($n, null);
}
foreach (['Kasbon Rafli', 'Kasbon Awal', 'Kasbon Wirda'] as $n) {
    $ensureExpense($n, (int) $branch->id);
}

$catCache = [];
$getCat = static function (string $name) use (&$catCache, $ensureExpense, $branch): Category {
    $key = mb_strtolower($name);
    if (! isset($catCache[$key])) {
        $isKasbonLocal = in_array($name, ['Kasbon Rafli', 'Kasbon Awal', 'Kasbon Wirda'], true);
        $catCache[$key] = $ensureExpense($name, $isKasbonLocal ? (int) $branch->id : null);
    }

    return $catCache[$key];
};

$resolve = static function (string $raw) use ($getCat): array {
    $label = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
    $u = mb_strtoupper($label);

    if ($u === 'DAPUR' || str_contains($u, 'DAPUR')) {
        return [$getCat('Dapur'), 'Dapur'];
    }
    if (str_contains($u, 'SPAREPART')) {
        return [$getCat('Sparepart'), 'Sparepart'];
    }
    if (str_contains($u, 'OPERASIONAL')) {
        return [$getCat('Operasional'), 'Operasional'];
    }
    if (str_contains($u, 'TOKEN') || str_contains($u, 'LISTRIK')) {
        return [$getCat('Listrik'), 'Token Listrik'];
    }
    if (str_contains($u, 'TABUNGAN')) {
        return [$getCat('Tabungan'), 'Tabungan'];
    }
    if (str_contains($u, 'ISI SALDO') || $u === 'SALDO') {
        return [$getCat('Isi Saldo'), 'Isi Saldo'];
    }
    if (str_contains($u, 'RAFLI')) {
        return [$getCat('Kasbon Rafli'), 'Rafli'];
    }
    if ($u === 'AWAL' || str_contains($u, 'AWAL')) {
        return [$getCat('Kasbon Awal'), 'Awal'];
    }
    if (str_contains($u, 'BAHAR')) {
        return [$getCat('Kasbon Bahar'), 'bahar'];
    }
    if (str_contains($u, 'WIRDA')) {
        return [$getCat('Kasbon Wirda'), 'wirda'];
    }

    throw new RuntimeException('Kategori tidak terpetakan: '.$raw);
};

$marker = 'Import spreadsheet pengeluaran Juli 2026 Lukulamo';
$existing = Transaction::withoutGlobalScopes()
    ->where('branch_id', $branch->id)
    ->where('description', 'ilike', $marker.'%')
    ->count();
if ($existing > 0) {
    echo "SKIP: sudah ada {$existing} transaksi dengan marker import.\n";
    exit(0);
}

/**
 * @return list<array{date:string,amount:float,label:string,note:string}>
 */
function parseExpenseXlsx(string $path): array
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
        $note = trim((string) ($cells[5] ?? ''));
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
            'note' => $note,
        ];
    }
    $zip->close();

    return $out;
}

$rows = parseExpenseXlsx($xlsx);
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

DB::transaction(function () use ($rows, $resolve, $branch, $admin, $cash, $marker, &$created, &$sum) {
    foreach ($rows as $r) {
        [$cat, $nice] = $resolve($r['label']);
        $desc = $marker.' | '.$nice;
        if ($r['note'] !== '') {
            $desc .= ' | '.$r['note'];
        }
        if (mb_strtoupper($r['label']) !== mb_strtoupper($nice)) {
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
