<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Product;
$prods = Product::withoutGlobalScopes()->get();
foreach ($prods as $p) {
    echo "Product ID: {$p->id} | SKU: {$p->sku} | Branch ID: {$p->branch_id}
";
}
