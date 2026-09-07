<?php

/**
 * Import pengeluaran + transfer Cash→Mandiri konter Waebulen Juli 2026.
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
    $fallback = 'c:\\Users\\62823\\Downloads\\Untitled spreadsheet (3).xlsx';
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

$cash = Account::query()->where('code', 'cash')->orWhere('name', 'ilike', 'cash')->first();
$mandiri = Account::query()->where('code', 'mandiri')->orWhere('name', 'ilike', 'mandiri')->first();
if (! $admin || ! $cash || ! $mandiri) {
    fwrite(STDERR, "User/akun Cash/Mandiri tidak lengkap.\n");
    exit(1);
}

$ensureExpense = static function (string $name) use ($branch): Category {
    $existing = Category::query()
        ->where('type', 'expense')
        ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
        ->where(function ($q) use ($branch) {
            $q->whereNull('branch_id')->orWhere('branch_id', $branch->id);
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
        'branch_id' => null,
        'name' => $name,
        'type' => 'expense',
        'is_active' => true,
    ]);
};

foreach ([
    'Dapur',
    'Listrik',
    'Ongkir Barang',
    'Operasional',
    'Sparepart',
    'Tabungan',
    'Pembelian Second',
    'Belanja Barang',
    'Isi Saldo',
    'Kasbon Bahar',
    'Kasbon Ilham',
    'Kasbon Zulkifli',
    'Transfer Antar Akun - Keluar',
    'Transfer Antar Akun - Masuk',
] as $name) {
    $type = str_starts_with($name, 'Transfer Antar Akun - Masuk') ? 'income' : 'expense';
    if ($type === 'income') {
        Category::query()->firstOrCreate(
            ['branch_id' => null, 'name' => $name],
            ['type' => 'income', 'is_active' => true]
        );
    } else {
        $ensureExpense($name);
    }
}

$catByKey = [];
foreach (Category::query()->whereIn('type', ['expense', 'income'])->get() as $c) {
    $catByKey[mb_strtolower($c->name)] = $c;
}

$resolveExpenseCat = static function (string $raw) use ($catByKey, $ensureExpense): ?Category {
    $label = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
    $u = mb_strtoupper($label);

    $map = [
        'DAPUR' => 'Dapur',
        'SPAREPART' => 'Sparepart',
        'TABUNGAN' => 'Tabungan',
        'TOKEN LISTRIK' => 'Listrik',
        'LISTRIK' => 'Listrik',
        'ONGKIR' => 'Ongkir Barang',
        'BENSIN' => 'Operasional',
        'BELI KARTU' => 'Belanja Barang',
        'BELI SECEND' => 'Pembelian Second',
        'BELI SECOND' => 'Pembelian Second',
        'ISI SALDO' => 'Isi Saldo',
        'BAHAR' => 'Kasbon Bahar',
        'ILHAM' => 'Kasbon Ilham',
        'ZUKI' => 'Kasbon Zulkifli',
        'ZULKIFLI' => 'Kasbon Zulkifli',
    ];

    foreach ($map as $needle => $target) {
        if ($u === $needle || str_contains($u, $needle)) {
            $key = mb_strtolower($target);

            return $catByKey[$key] ?? $ensureExpense($target);
        }
    }

    return null;
};

$marker = 'Import spreadsheet pengeluaran Juli 2026 Waebulen';
$existing = Transaction::withoutGlobalScopes()
    ->where('branch_id', $branch->id)
    ->where(function ($q) use ($marker) {
        $q->where('description', 'ilike', $marker.'%')
            ->orWhere('description', 'ilike', '%'.$marker.'%');
    })
    ->count();
if ($existing > 0) {
    echo "SKIP: sudah ada {$existing} transaksi dengan marker import.\n";
    exit(0);
}

/**
 * @return list<array{date:string,amount:float,label:string}>
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
        if ($dateRaw === '' || $amountRaw === '' || $label === '' || ! is_numeric($amountRaw)) {
            continue;
        }
        if (! is_numeric($dateRaw) && ! preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $dateRaw)) {
            continue;
        }
        $date = is_numeric($dateRaw) ? $excelDate($dateRaw) : substr(trim((string) $dateRaw), 0, 10);
        $out[] = [
            'date' => $date,
            'amount' => (float) $amountRaw,
            'label' => $label,
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

$expenseCatOut = $catByKey[mb_strtolower('Transfer Antar Akun - Keluar')]
    ?? Category::query()->firstOrCreate(
        ['branch_id' => null, 'name' => 'Transfer Antar Akun - Keluar'],
        ['type' => 'expense', 'is_active' => true]
    );
$incomeCatIn = $catByKey[mb_strtolower('Transfer Antar Akun - Masuk')]
    ?? Category::query()->firstOrCreate(
        ['branch_id' => null, 'name' => 'Transfer Antar Akun - Masuk'],
        ['type' => 'income', 'is_active' => true]
    );

echo "BRANCH={$branch->id}|{$branch->name}\n";
echo "INPUT_BY={$admin->id}|{$admin->email}\n";
echo "CASH={$cash->id}|{$cash->name} MANDIRI={$mandiri->id}|{$mandiri->name}\n";
echo 'PARSED='.count($rows)."\n";

$createdExpense = 0;
$createdTransferPairs = 0;
$sumExpense = 0.0;
$sumTransfer = 0.0;

DB::transaction(function () use (
    $rows,
    $resolveExpenseCat,
    $branch,
    $admin,
    $cash,
    $mandiri,
    $expenseCatOut,
    $incomeCatIn,
    $marker,
    &$createdExpense,
    &$createdTransferPairs,
    &$sumExpense,
    &$sumTransfer
) {
    foreach ($rows as $r) {
        $labelU = mb_strtoupper(trim($r['label']));
        $isTransfer = str_contains($labelU, 'TRANFER') || $labelU === 'TRANSFER';

        if ($isTransfer) {
            $note = "{$marker} | Cash → Mandiri";
            $amount = Money::of($r['amount']);

            Transaction::withoutGlobalScopes()->create([
                'branch_id' => $branch->id,
                'user_id' => $admin->id,
                'category_id' => $expenseCatOut->id,
                'account_id' => $cash->id,
                'amount' => $amount,
                'description' => "[Transfer Antar Akun - Keluar] {$note}",
                'transaction_date' => $r['date'],
            ]);
            Transaction::withoutGlobalScopes()->create([
                'branch_id' => $branch->id,
                'user_id' => $admin->id,
                'category_id' => $incomeCatIn->id,
                'account_id' => $mandiri->id,
                'amount' => $amount,
                'description' => "[Transfer Antar Akun - Masuk] {$note}",
                'transaction_date' => $r['date'],
            ]);
            $createdTransferPairs++;
            $sumTransfer += $r['amount'];
            echo "XFER {$r['date']}|Cash→Mandiri|{$r['amount']}\n";
            continue;
        }

        $cat = $resolveExpenseCat($r['label']);
        if (! $cat) {
            throw new RuntimeException('Kategori tidak terpetakan: '.$r['label']);
        }

        Transaction::withoutGlobalScopes()->create([
            'branch_id' => $branch->id,
            'user_id' => $admin->id,
            'category_id' => $cat->id,
            'account_id' => $cash->id,
            'amount' => Money::of($r['amount']),
            'description' => $marker.' | '.$cat->name.' | sumber: '.$r['label'],
            'transaction_date' => $r['date'],
        ]);
        $createdExpense++;
        $sumExpense += $r['amount'];
        echo "EXP {$r['date']}|{$cat->name}|{$r['amount']}\n";
    }
});

echo "CREATED_EXPENSE={$createdExpense}\n";
echo 'SUM_EXPENSE='.number_format($sumExpense, 2, '.', '')."\n";
echo "CREATED_TRANSFER_PAIRS={$createdTransferPairs}\n";
echo 'SUM_TRANSFER='.number_format($sumTransfer, 2, '.', '')."\n";
echo "DONE\n";
