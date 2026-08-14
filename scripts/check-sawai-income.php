<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$b=App\Models\Branch::where('name','ilike','%sawai%')->first();
echo "BRANCH=".($b? "{$b->id}|{$b->name}|{$b->type}" : 'NONE')."\n";
if(!$b) exit(1);
$cats=App\Models\Category::query()->where('type','income')->where(function($q) use ($b){$q->whereNull('branch_id')->orWhere('branch_id',$b->id);})->orderBy('name')->get(['id','name','branch_id','is_active']);
foreach($cats as $c) echo "CAT={$c->id}|{$c->name}|".($c->branch_id??'g')."|".(($c->is_active!==false)?'1':'0')."\n";
foreach(App\Models\Account::orderBy('id')->get(['id','name','code']) as $a) echo "ACC={$a->id}|{$a->name}|{$a->code}\n";
$n=App\Models\Transaction::withoutGlobalScopes()->where('branch_id',$b->id)->whereBetween('transaction_date',['2026-07-01','2026-07-31'])->whereHas('category',fn($q)=>$q->where('type','income'))->count();
echo "INCOME_JUL=$n\n";
