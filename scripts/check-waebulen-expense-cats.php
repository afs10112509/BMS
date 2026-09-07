<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$bid=App\Models\Branch::where('name','ilike','%waebulen%')->value('id');
echo "BRANCH=$bid\n";
$cats=App\Models\Category::query()->where('type','expense')->where(function($q) use ($bid){$q->whereNull('branch_id')->orWhere('branch_id',$bid);})->orderBy('name')->get(['id','name','branch_id','is_active']);
foreach($cats as $c) echo $c->id.'|'.$c->name.'|'.($c->branch_id??'g').'|'.(($c->is_active!==false)?'1':'0').PHP_EOL;
