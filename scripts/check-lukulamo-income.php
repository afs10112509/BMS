<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$b=App\Models\Branch::where('name','ilike','%lukulamo%')->first();
echo "BRANCH=".($b?"{$b->id}|{$b->name}|{$b->type}":'NONE')."\n";
if(!$b) exit(1);
foreach(App\Models\Category::query()->where('type','income')->where(function($q) use ($b){$q->whereNull('branch_id')->orWhere('branch_id',$b->id);})->orderBy('name')->get(['id','name','branch_id']) as $c) {
  echo "CAT={$c->id}|{$c->name}|".($c->branch_id??'g')."\n";
}
foreach(App\Models\Account::orderBy('id')->get(['id','name','code']) as $a) echo "ACC={$a->id}|{$a->name}|{$a->code}\n";
