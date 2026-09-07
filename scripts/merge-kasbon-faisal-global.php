<?php

/**
 * Lebur dua kategori "Kasbon Faisal" (cabang 2 & 3) menjadi 1 kategori global.
 *
 *   php scripts/merge-kasbon-faisal-global.php --apply
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$apply = in_array('--apply', $argv, true);

$cats = Category::query()
    ->whereRaw("LOWER(name) = ?", ['kasbon faisal'])
    ->orderBy('id')
    ->get();

echo "DITEMUKAN ".$cats->count()." kategori Kasbon Faisal:\n";
foreach ($cats as $c) {
    $n = (int) DB::table('transactions')->where('category_id', $c->id)->count();
    $sum = (float) DB::table('transactions')->where('category_id', $c->id)->sum('amount');
    echo "  id={$c->id}|branch=".($c->branch_id ?? 'global')."|active=".($c->is_active ? '1' : '0')."|tx={$n}|sum={$sum}\n";
}

if ($cats->count() < 1) {
    echo "Tidak ada kategori untuk dilabur.\n";
    exit(0);
}

$keep = $cats->firstWhere('id', 39) ?? $cats->first();
$losers = $cats->where('id', '!=', $keep->id)->values();

echo "\nKEEP id={$keep->id} → global\n";
foreach ($losers as $l) {
    echo "MERGE id={$l->id} → {$keep->id}\n";
}

if (! $apply) {
    echo "\nDry-run. Jalankan dengan --apply untuk menyimpan.\n";
    exit(0);
}

DB::transaction(function () use ($keep, $losers) {
    foreach ($losers as $loser) {
        $moved = DB::table('transactions')
            ->where('category_id', $loser->id)
            ->update(['category_id' => $keep->id]);
        echo "  transaksi dipindah dari {$loser->id}: {$moved}\n";

        if (Schema::hasTable('cashflow_workbook_lines')) {
            $lines = DB::table('cashflow_workbook_lines')
                ->where('category_id', $loser->id)
                ->update(['category_id' => $keep->id]);
            echo "  baris cashflow dipindah dari {$loser->id}: {$lines}\n";
        }

        Category::query()->whereKey($loser->id)->delete();
        echo "  kategori {$loser->id} dihapus\n";
    }

    $keep->branch_id = null;
    $keep->is_active = true;
    $keep->name = 'Kasbon Faisal';
    $keep->type = 'expense';
    $keep->save();
});

$final = Category::query()->whereRaw("LOWER(name) = ?", ['kasbon faisal'])->get();
echo "\nHASIL:\n";
foreach ($final as $c) {
    $n = (int) DB::table('transactions')->where('category_id', $c->id)->count();
    $sum = (float) DB::table('transactions')->where('category_id', $c->id)->sum('amount');
    echo "  id={$c->id}|branch=".($c->branch_id ?? 'global')."|tx={$n}|sum={$sum}\n";
}
echo "SELESAI\n";
