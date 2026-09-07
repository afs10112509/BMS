<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$rows = App\Models\Employee::query()->where('name','ilike','%salman%')->orWhere('name','ilike','%salm%')->get(['id','branch_id','name','status','positions']);
foreach ($rows as $r) echo json_encode($r->toArray(), JSON_UNESCAPED_UNICODE).PHP_EOL;
if ($rows->isEmpty()) echo "NONE\n";
$all = App\Models\Employee::query()->where('branch_id',3)->get(['id','name','status']);
echo "BRANCH3_COUNT=".$all->count().PHP_EOL;
