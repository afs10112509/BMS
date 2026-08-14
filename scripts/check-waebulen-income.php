<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Transaction;

$branch = Branch::query()->where('name', 'ilike', '%waebulen%')->first();
if (! $branch) {
    echo "BRANCH_NOT_FOUND\n";
    exit(1);
}
echo "BRANCH={$branch->id}|{$branch->name}|{$branch->type}\n";

$cats = Category::query()
    ->where('type', 'income')
    ->where(function ($q) use ($branch) {
        $q->whereNull('branch_id')->orWhere('branch_id', $branch->id);
    })
    ->orderBy('name')
    ->get(['id', 'name', 'branch_id', 'is_active']);

foreach ($cats as $c) {
    echo 'CAT='.$c->id.'|'.$c->name.'|branch='.($c->branch_id ?? 'global').'|active='.(($c->is_active !== false) ? '1' : '0')."\n";
}

$accs = Account::query()->orderBy('id')->get(['id', 'name', 'code']);
foreach ($accs as $a) {
    echo "ACC={$a->id}|{$a->name}|{$a->code}\n";
}

$jul = Transaction::withoutGlobalScopes()
    ->where('branch_id', $branch->id)
    ->whereBetween('transaction_date', ['2026-07-01', '2026-07-31'])
    ->whereHas('category', fn ($q) => $q->where('type', 'income'))
    ->count();
echo "INCOME_JUL={$jul}\n";
