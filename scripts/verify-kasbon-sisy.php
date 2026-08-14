<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

foreach (App\Models\Category::query()->where('name', 'ilike', '%sisy%')->get(['id', 'name', 'branch_id']) as $x) {
    echo "CAT={$x->id}|{$x->name}|branch=".($x->branch_id ?? 'g')."\n";
}
$n = App\Models\Transaction::withoutGlobalScopes()
    ->where('description', 'ilike', '%Kasbon Sisy%')
    ->count();
echo "TX_SISY={$n}\n";
$uki = App\Models\Transaction::withoutGlobalScopes()
    ->where('description', 'ilike', '%sumber: Uki%')
    ->whereHas('category', fn ($q) => $q->where('name', 'ilike', '%Zulkifli%'))
    ->count();
echo "TX_UKI_AS_ZULKIFLI={$uki}\n";
