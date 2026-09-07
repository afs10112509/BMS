<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Branch;
use App\Models\ProfitShare;
use App\Models\ProfitShareLine;

echo "=== profit_shares by period ===\n";
$periods = ProfitShare::query()
    ->selectRaw('year, month, COUNT(*) as n, SUM(net_profit) as net, SUM(pic_amount) as pic')
    ->groupBy('year', 'month')
    ->orderBy('year')
    ->orderBy('month')
    ->get();
if ($periods->isEmpty()) {
    echo "(tabel profit_shares kosong)\n";
} else {
    foreach ($periods as $p) {
        echo sprintf("%04d-%02d  slip=%d  net=%s  pic=%s\n", $p->year, $p->month, $p->n, $p->net, $p->pic);
    }
}

echo "\n=== Agustus 2026 detail ===\n";
$rows = ProfitShare::query()
    ->with(['branch:id,name', 'lines'])
    ->where('year', 2026)
    ->where('month', 8)
    ->orderBy('branch_id')
    ->get();

echo 'jumlah slip: '.$rows->count().PHP_EOL;
foreach ($rows as $s) {
    echo sprintf(
        "id=%d branch=%s status=%s pic=%s pct=%s income=%s expense=%s net=%s pic_amt=%s lines=%d note=%s\n",
        $s->id,
        $s->branch?->name ?? '-',
        $s->status,
        $s->pic_name ?: '-',
        $s->pic_share_pct,
        $s->total_income,
        $s->total_expense,
        $s->net_profit,
        $s->pic_amount,
        $s->lines->count(),
        $s->note ?: '-'
    );
}

echo "\n=== cabang konter (untuk board) ===\n";
$branches = Branch::query()->with('branchType')->orderBy('id')->get();
foreach ($branches as $b) {
    $ws = method_exists($b, 'isWorkshop') && $b->isWorkshop() ? 'bengkel' : 'konter';
    echo $b->id.' | '.$b->name.' | '.$ws.PHP_EOL;
}

echo "\n=== profit_share_lines count ===\n";
echo 'total lines: '.ProfitShareLine::query()->count().PHP_EOL;
echo 'lines for 2026-08: '.ProfitShareLine::query()
    ->whereHas('profitShare', fn ($q) => $q->where('year', 2026)->where('month', 8))
    ->count().PHP_EOL;
