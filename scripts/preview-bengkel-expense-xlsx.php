<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\Branch;
use App\Models\Category;

$path = $argv[1] ?? 'c:\\Users\\62823\\Downloads\\Untitled spreadsheet (9).xlsx';
if (! is_file($path)) {
    fwrite(STDERR, "File tidak ditemukan: {$path}\n");
    exit(1);
}

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
    $months = [
        'jan' => 1, 'januari' => 1, 'feb' => 2, 'februari' => 2,
        'mar' => 3, 'maret' => 3, 'apr' => 4, 'april' => 4,
        'mei' => 5, 'may' => 5, 'jun' => 6, 'juni' => 6,
        'jul' => 7, 'juli' => 7, 'agu' => 8, 'ags' => 8, 'agustus' => 8,
        'sep' => 9, 'september' => 9, 'okt' => 10, 'oktober' => 10,
        'nov' => 11, 'november' => 11, 'des' => 12, 'desember' => 12,
    ];
    if (preg_match('/^(\d{1,2})\s*[-–\/]\s*([A-Za-z]+)\s*[-–\/]\s*(\d{2,4})$/u', $s, $m)) {
        $day = (int) $m[1];
        $month = $months[mb_strtolower($m[2])] ?? null;
        $year = (int) $m[3];
        if ($year < 100) {
            $year += 2000;
        }
        if (! $month) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    return null;
};

$zip = new ZipArchive;
$zip->open($path);
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
$samples = [];
$parsed = [];
$byLabel = [];
$byAcc = [];
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
    if (count($samples) < 10) {
        $samples[] = $cells;
    }
    $joined = mb_strtolower(implode('|', $cells));
    if (str_contains($joined, 'tanggal') || str_contains($joined, 'nominal') || str_contains($joined, 'kategori') || str_contains($joined, 'pengeluaran')) {
        continue;
    }
    $date = $parseDate($cells[1] ?? '');
    $amount = $parseAmount($cells[2] ?? '');
    $label = trim((string) ($cells[3] ?? ''));
    $account = trim((string) ($cells[4] ?? ''));
    $note = trim((string) ($cells[5] ?? ''));
    if (! $date || $amount === null || $label === '') {
        continue;
    }
    $parsed[] = compact('date', 'amount', 'label', 'account', 'note');
    if (! isset($byLabel[$label])) {
        $byLabel[$label] = ['n' => 0, 'sum' => 0.0, 'accounts' => [], 'notes' => [], 'samples' => []];
    }
    $byLabel[$label]['n']++;
    $byLabel[$label]['sum'] += $amount;
    if ($account !== '') {
        $byLabel[$label]['accounts'][$account] = ($byLabel[$label]['accounts'][$account] ?? 0) + 1;
    }
    if ($note !== '') {
        $byLabel[$label]['notes'][$note] = ($byLabel[$label]['notes'][$note] ?? 0) + 1;
    }
    if (count($byLabel[$label]['samples']) < 2) {
        $byLabel[$label]['samples'][] = "{$date}|{$amount}|acc={$account}|note={$note}";
    }
    $a = $account !== '' ? $account : '(kosong)';
    $byAcc[$a] = ($byAcc[$a] ?? 0) + 1;
}
$zip->close();

echo "SAMPLE:\n";
foreach ($samples as $s) {
    echo json_encode($s, JSON_UNESCAPED_UNICODE)."\n";
}
echo 'PARSED='.count($parsed)."\n";
$dates = array_column($parsed, 'date');
sort($dates);
echo 'PERIOD='.($dates[0] ?? '-').'..'.(end($dates) ?: '-')."\n";
echo 'TOTAL='.number_format(array_sum(array_column($parsed, 'amount')), 2, '.', '')."\n";
echo 'ACCOUNTS_FILE='.json_encode($byAcc, JSON_UNESCAPED_UNICODE)."\n";
echo "BY_LABEL:\n";
ksort($byLabel);
foreach ($byLabel as $lab => $info) {
    echo "LABEL={$lab}|n={$info['n']}|sum=".number_format($info['sum'], 2, '.', '')
        .'|acc='.json_encode($info['accounts'], JSON_UNESCAPED_UNICODE)
        .'|notes='.json_encode($info['notes'], JSON_UNESCAPED_UNICODE)."\n";
    foreach ($info['samples'] as $s) {
        echo "  sample: {$s}\n";
    }
}

$branch = Branch::query()->where('name', 'ilike', '%bengkel%')->first();
echo 'BRANCH='.($branch ? "{$branch->id}|{$branch->name}" : 'NULL')."\n";
echo "EXISTING_EXPENSE_CATS:\n";
$cats = Category::query()
    ->where('type', 'expense')
    ->where(function ($q) use ($branch) {
        $q->whereNull('branch_id')->orWhere('branch_id', $branch?->id);
    })
    ->orderBy('name')
    ->get(['id', 'name', 'branch_id']);
foreach ($cats as $c) {
    echo "{$c->id}|{$c->name}|branch=".($c->branch_id ?? 'global')."\n";
}
echo "ACCOUNTS_DB:\n";
foreach (Account::query()->orderBy('name')->get(['id', 'code', 'name']) as $a) {
    echo "{$a->id}|{$a->code}|{$a->name}\n";
}
