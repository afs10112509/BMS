<?php

$path = $argv[1] ?? 'c:\\Users\\62823\\Downloads\\Untitled spreadsheet (8).xlsx';
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
    $s = str_replace(["Rp", "rp", " "], '', $s);
    // 2.976.000,00 → 2976000.00
    if (str_contains($s, ',')) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        $s = str_replace(',', '', $s);
    }
    if (! is_numeric($s)) {
        return null;
    }

    return (float) $s;
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
$excelDate = static function ($n): string {
    return gmdate('Y-m-d', (int) round(((float) $n - 25569) * 86400));
};

$xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
$parsed = [];
$byLabel = [];
$byAcc = [];
$samples = [];
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
    if (count($samples) < 8) {
        $samples[] = $cells;
    }
    $joined = mb_strtolower(implode('|', $cells));
    if (str_contains($joined, 'tanggal') || str_contains($joined, 'nominal') || str_contains($joined, 'pemasukan')) {
        continue;
    }
    $dateRaw = $cells[1] ?? '';
    $amount = $parseAmount($cells[2] ?? '');
    $label = trim((string) ($cells[3] ?? ''));
    $account = trim((string) ($cells[4] ?? ''));
    if ($dateRaw === '' || $amount === null || $label === '') {
        continue;
    }
    $date = is_numeric($dateRaw)
        ? $excelDate($dateRaw)
        : substr(trim((string) $dateRaw), 0, 10);
    $parsed[] = [
        'date' => $date,
        'amt' => $amount,
        'label' => $label,
        'account' => $account,
    ];
    $byLabel[$label]['n'] = ($byLabel[$label]['n'] ?? 0) + 1;
    $byLabel[$label]['sum'] = ($byLabel[$label]['sum'] ?? 0) + $amount;
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
echo 'TOTAL='.number_format(array_sum(array_column($parsed, 'amt')), 2, '.', '')."\n";
echo 'ACCOUNTS='.json_encode($byAcc, JSON_UNESCAPED_UNICODE)."\n";
echo "BY_LABEL:\n";
ksort($byLabel);
foreach ($byLabel as $lab => $info) {
    echo $lab.'|n='.$info['n'].'|sum='.number_format($info['sum'], 2, '.', '')."\n";
}
