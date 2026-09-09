<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$cols = DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = 'stock_batches'");
print_r(array_column($cols, 'column_name'));
