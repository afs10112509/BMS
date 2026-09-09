<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Product;
use App\Models\StockBatch;
use App\Models\ProductStock;
use App\Models\Branch;

$branch = Branch::first() ?? Branch::create(['name' => 'Toko Sawai Utama', 'code' => 'SWI', 'address' => 'Lililef Sawai']);

$items = [
    [
        'name' => 'Samsung Galaxy A55 5G 8/256GB',
        'type' => 'phone',
        'base_unit' => 'unit',
        'sku' => 'HP-SAM-A55-8256',
        'barcode' => '8806095123456',
        'brand' => 'Samsung',
        'model' => 'Galaxy A55 5G',
        'cost_price' => 5200000,
        'selling_price' => 5999000,
        'stock_quantity' => 12,
        'min_stock' => 3,
        'requires_serial' => true,
    ],
    [
        'name' => 'iPhone 15 Pro Max 256GB Natural Titanium',
        'type' => 'phone',
        'base_unit' => 'unit',
        'sku' => 'HP-APL-IP15PM-256',
        'barcode' => '195949012345',
        'brand' => 'Apple',
        'model' => 'iPhone 15 Pro Max',
        'cost_price' => 20500000,
        'selling_price' => 22499000,
        'stock_quantity' => 5,
        'min_stock' => 2,
        'requires_serial' => true,
    ],
    [
        'name' => 'Charger Anker 20W USB-C PowerPort III',
        'type' => 'accessory',
        'base_unit' => 'pcs',
        'sku' => 'AKS-ANK-CHG20W',
        'barcode' => '848061012399',
        'brand' => 'Anker',
        'model' => 'PowerPort III 20W',
        'cost_price' => 110000,
        'selling_price' => 175000,
        'stock_quantity' => 45,
        'min_stock' => 10,
        'requires_serial' => false,
    ],
    [
        'name' => 'Kabel Data Baseus Cafule Type-C to C 100W 1m',
        'type' => 'accessory',
        'base_unit' => 'pcs',
        'sku' => 'AKS-BAS-KBL100W',
        'barcode' => '6953156201234',
        'brand' => 'Baseus',
        'model' => 'Cafule 100W',
        'cost_price' => 35000,
        'selling_price' => 65000,
        'stock_quantity' => 80,
        'min_stock' => 15,
        'requires_serial' => false,
    ],
    [
        'name' => 'Tempered Glass Vivan Full Glue iPhone 13/14',
        'type' => 'accessory',
        'base_unit' => 'pcs',
        'sku' => 'AKS-VIV-TGIP13',
        'barcode' => '899701234501',
        'brand' => 'Vivan',
        'model' => 'Full Glue 9H',
        'cost_price' => 15000,
        'selling_price' => 35000,
        'stock_quantity' => 150,
        'min_stock' => 20,
        'requires_serial' => false,
    ],
    [
        'name' => 'Redmi Note 13 4G 8/256GB Black',
        'type' => 'phone',
        'base_unit' => 'unit',
        'sku' => 'HP-XIA-RN13-8256',
        'barcode' => '6941812345678',
        'brand' => 'Xiaomi',
        'model' => 'Redmi Note 13',
        'cost_price' => 2450000,
        'selling_price' => 2799000,
        'stock_quantity' => 18,
        'min_stock' => 5,
        'requires_serial' => true,
    ],
    [
        'name' => 'LCD Touchscreen Original Realme C35 Fullset',
        'type' => 'spare_part',
        'base_unit' => 'pcs',
        'sku' => 'SPT-RLM-LCDC35',
        'barcode' => '990000123401',
        'brand' => 'Realme',
        'model' => 'LCD C35',
        'cost_price' => 180000,
        'selling_price' => 280000,
        'stock_quantity' => 8,
        'min_stock' => 3,
        'requires_serial' => false,
    ],
    [
        'name' => 'Baterai Hippo Double Power Samsung A50 / A50s',
        'type' => 'spare_part',
        'base_unit' => 'pcs',
        'sku' => 'SPT-HIP-BAT-A50',
        'barcode' => '899100123456',
        'brand' => 'Hippo',
        'model' => 'Double Power 4500mAh',
        'cost_price' => 75000,
        'selling_price' => 135000,
        'stock_quantity' => 14,
        'min_stock' => 4,
        'requires_serial' => false,
    ],
    [
        'name' => 'Headset Bluetooth TWS Lenovo Thinkplus LP40 LivePods',
        'type' => 'accessory',
        'base_unit' => 'pcs',
        'sku' => 'AKS-LNV-LP40',
        'barcode' => '6971234567890',
        'brand' => 'Lenovo',
        'model' => 'LP40 LivePods',
        'cost_price' => 85000,
        'selling_price' => 145000,
        'stock_quantity' => 30,
        'min_stock' => 8,
        'requires_serial' => false,
    ],
    [
        'name' => 'Voucher Pulsa / Kuota XL 30GB Open Price',
        'type' => 'other',
        'base_unit' => 'pcs',
        'sku' => 'PLS-XL-30GB',
        'barcode' => '899990011223',
        'brand' => 'XL Axiata',
        'model' => 'Paket Data 30GB',
        'cost_price' => 50000,
        'selling_price' => 58000,
        'stock_quantity' => 100,
        'min_stock' => 10,
        'requires_serial' => false,
        'allow_open_price' => true,
    ],
];

$count = 0;
foreach ($items as $data) {
    $data['branch_id'] = $branch->id;
    $p = Product::updateOrCreate(['sku' => $data['sku']], $data);
    
    // Seed FIFO batch
    StockBatch::updateOrCreate(
        ['product_id' => $p->id, 'branch_id' => $branch->id],
        [
            'quantity_in' => $p->stock_quantity,
            'quantity_remaining' => $p->stock_quantity,
            'purchase_price' => $p->cost_price,
            'received_at' => now(),
        ]
    );

    // Seed Product Stock per branch
    ProductStock::updateOrCreate(
        ['product_id' => $p->id, 'branch_id' => $branch->id],
        [
            'stock' => $p->stock_quantity,
            'min_stock' => $p->min_stock ?? 2,
        ]
    );

    $count++;
}

echo "Successfully seeded {$count} products!
";
