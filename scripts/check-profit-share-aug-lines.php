<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ProfitShare;

$ps = ProfitShare::with('lines', 'branch')
    ->where('year', 2026)
    ->where('month', 8)
    ->first();

if (!$ps) {
    echo "Tidak ada slip Agustus 2026\n";
    exit(0);
}

echo "Slip #{$ps->id} {$ps->branch?->name} {$ps->status}\n";
echo "updated_at={$ps->updated_at} created_at={$ps->created_at}\n";
echo "income={$ps->total_income} expense={$ps->total_expense} net={$ps->net_profit}\n";
echo "pic={$ps->pic_name} pct={$ps->pic_share_pct} amt={$ps->pic_amount}\n";
echo "note=" . ($ps->note ?: '-') . "\n\n";
echo "baris:\n";
foreach ($ps->lines->sortBy('sort_order') as $l) {
    echo sprintf(
        "  #%s | %s | %s | amount=%s\n",
        $l->sort_order,
        $l->type,
        $l->name,
        $l->amount
    );
}
