<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\Branch;
use App\Models\Category;

$path = $argv[1] ?? 'c:\\Users\\62823\\Downloads\\Untitled spreadsheet (7).xlsx';
if (! is_file($path)) {
    fwrite(STDERR, "File tidak ditemukan: {$path}\n");
    exit(1);
}

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
$excelDate = static function ($n): string {
    return gmdate('Y-m-d', (int) round(((float) $n - 25569) * 86400));
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
    $rows[] = $cells;
}
$zip->close();

echo "RAW_COUNT=".count($rows)."\n";
echo "SAMPLE:\n";
foreach (array_slice($rows, 0, 8) as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
}

$parsed = [];
$byLabel = [];
foreach ($rows as $cells) {
    $joined = mb_strtolower(implode('|', $cells));
    if (str_contains($joined, 'kategori') || str_contains($joined, 'tanggal')) {
        continue;
    }
    $dateRaw = $cells[1] ?? '';
    $amountRaw = $cells[2] ?? '';
    $label = trim((string) ($cells[3] ?? ''));
    $account = trim((string) ($cells[4] ?? ''));
    $note = trim((string) ($cells[5] ?? ''));
    // alternate: col3 account, col4 category, col5 note — detect from sample
    if ($dateRaw === '' || $amountRaw === '' || ! is_numeric($amountRaw)) {
        continue;
    }
    if ($label === '' && $account === '') {
        continue;
    }
    $date = is_numeric($dateRaw)
        ? $excelDate($dateRaw)
        : substr(trim((string) $dateRaw), 0, 10);
    $amt = (float) $amountRaw;
    $parsed[] = compact('date', 'amt', 'label', 'account', 'note') + ['raw' => $cells];
    $key = $label !== '' ? $label : '(kosong)';
    if (! isset($byLabel[$key])) {
        $byLabel[$key] = ['n' => 0, 'sum' => 0.0, 'accounts' => [], 'notes' => [], 'samples' => []];
    }
    $byLabel[$key]['n']++;
    $byLabel[$key]['sum'] += $amt;
    if ($account !== '') {
        $byLabel[$key]['accounts'][$account] = ($byLabel[$key]['accounts'][$account] ?? 0) + 1;
    }
    if ($note !== '') {
        $byLabel[$key]['notes'][$note] = ($byLabel[$key]['notes'][$note] ?? 0) + 1;
    }
    if (count($byLabel[$key]['samples']) < 3) {
        $byLabel[$key]['samples'][] = "{$date}|{$amt}|acc={$account}|note={$note}";
    }
}

echo 'PARSED='.count($parsed)."\n";
$dates = array_column($parsed, 'date');
sort($dates);
echo 'PERIOD='.($dates[0] ?? '-').'..'.(end($dates) ?: '-')."\n";
echo 'TOTAL='.array_sum(array_column($parsed, 'amt'))."\n";

$accs = [];
foreach ($parsed as $p) {
    $a = $p['account'] !== '' ? $p['account'] : '(kosong)';
    $accs[$a] = ($accs[$a] ?? 0) + 1;
}
echo 'ACCOUNTS_IN_FILE='.json_encode($accs, JSON_UNESCAPED_UNICODE)."\n";

echo "BY_LABEL:\n";
ksort($byLabel);
foreach ($byLabel as $lab => $info) {
    echo "LABEL={$lab}|n={$info['n']}|sum={$info['sum']}|acc=".json_encode($info['accounts'], JSON_UNESCAPED_UNICODE)
        .'|notes='.json_encode($info['notes'], JSON_UNESCAPED_UNICODE)."\n";
    foreach ($info['samples'] as $s) {
        echo "  sample: {$s}\n";
    }
}

$branch = Branch::query()->where('name', 'ilike', '%lukulamo%')->first();
echo 'BRANCH='.($branch ? "{$branch->id}|{$branch->name}" : 'NULL')."\n";

$cats = Category::query()
    ->where('type', 'expense')
    ->where(function ($q) use ($branch) {
        $q->whereNull('branch_id')->orWhere('branch_id', $branch?->id);
    })
    ->orderBy('name')
    ->get(['id', 'name', 'branch_id']);
echo "EXISTING_EXPENSE_CATS:\n";
foreach ($cats as $c) {
    echo "{$c->id}|{$c->name}|branch=".($c->branch_id ?? 'global')."\n";
}

echo "ACCOUNTS_DB:\n";
foreach (Account::query()->orderBy('name')->get(['id', 'code', 'name']) as $a) {
    echo "{$a->id}|{$a->code}|{$a->name}\n";
}
