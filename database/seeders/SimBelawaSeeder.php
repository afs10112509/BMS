<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Services\FifoStockService;
use Illuminate\Database\Seeder;

class SimBelawaSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();
        if ($branches->isEmpty()) return;

        $fifoService = app(FifoStockService::class);

        foreach ($branches as $branch) {
            // 1. HP Samsung Galaxy A54
            $p1 = Product::firstOrCreate(
                ['sku' => 'HP-SAM-A54-' . $branch->id],
                [
                    'branch_id' => $branch->id,
                    'name' => 'Samsung Galaxy A54 8/256GB',
                    'type' => 'phone',
                    'barcode' => '8991001' . $branch->id,
                    'base_unit' => 'pcs',
                    'requires_serial' => true,
                    'cost_price' => 4200000,
                    'selling_price' => 4800000,
                    'stock_quantity' => 5,
                    'min_stock' => 2,
                ]
            );

            $fifoService->addStockBatch($p1, $branch->id, 5, 4200000);

            for ($i = 1; $i <= 5; $i++) {
                ProductSerial::firstOrCreate(
                    ['serial_number' => "358990100{$branch->id}00{$i}"],
                    [
                        'product_id' => $p1->id,
                        'branch_id' => $branch->id,
                        'purchase_price' => 4200000,
                        'status' => 'in_stock',
                    ]
                );
            }

            // 2. Aksesoris Charger Fast Charging
            $p2 = Product::firstOrCreate(
                ['sku' => 'ACC-CHG-25W-' . $branch->id],
                [
                    'branch_id' => $branch->id,
                    'name' => 'Charger Type-C 25W Original',
                    'type' => 'accessory',
                    'barcode' => '8992002' . $branch->id,
                    'base_unit' => 'pcs',
                    'requires_serial' => false,
                    'cost_price' => 85000,
                    'selling_price' => 150000,
                    'stock_quantity' => 20,
                    'min_stock' => 5,
                ]
            );

            $fifoService->addStockBatch($p2, $branch->id, 20, 85000);
        }

        // Demo Customers
        Customer::firstOrCreate(
            ['phone' => '082321117794'],
            [
                'name' => 'Faisal (Owner)',
                'customer_type' => 'reseller',
                'whatsapp_opt_in' => true,
            ]
        );

        Customer::firstOrCreate(
            ['phone' => '081299887766'],
            [
                'name' => 'Budi Santoso',
                'customer_type' => 'umum',
                'whatsapp_opt_in' => true,
            ]
        );
    }
}
