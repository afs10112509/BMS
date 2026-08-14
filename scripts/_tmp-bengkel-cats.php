<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$b = App\Models\Branch::query()->where('name','ilike','%bengkel%')->with('branchType')->first();
echo 'BRANCH='.($b ? $b->id.'|'.$b->name.'|allows='.($b->allows_service ? '1' : '0') : 'NULL').PHP_EOL;
foreach (App\Models\Category::query()->where('type','income')->where(function($q) use ($b) { $q->whereNull('branch_id')->orWhere('branch_id', $b?->id); })->orderBy('name')->get(['id','name','branch_id']) as $c) {
  echo $c->id.'|'.$c->name.'|branch='.($c->branch_id ?? 'global').PHP_EOL;
}
