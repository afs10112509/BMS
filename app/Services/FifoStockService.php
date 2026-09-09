<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\ProductStock;
use App\Models\StockBatch;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FifoStockService
{
    /**
     * Potong stok dari stock_batches sesuai prinsip FIFO.
     * Mengembalikan total HPP (Harga Pokok Penjualan) dan rincian batch yang terpakai.
     */
    public function deductFifoStock(Product $product, int $branchId, float $qty, ?string $serialNumber = null): array
    {
        $remainingQtyNeeded = $qty;
        $totalHpp = 0;
        $usedBatches = [];

        // Ambil batch stok yang masih sisa (FIFO: received_at ASC)
        $batches = StockBatch::where('product_id', $product->id)
            ->where('branch_id', $branchId)
            ->where('quantity_remaining', '>', 0)
            ->orderBy('received_at', 'asc')
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            if ($remainingQtyNeeded <= 0) break;

            $takeQty = min($batch->quantity_remaining, $remainingQtyNeeded);
            $batchHpp = $takeQty * $batch->purchase_price;

            $batch->quantity_remaining -= $takeQty;
            $batch->save();

            $totalHpp += $batchHpp;
            $remainingQtyNeeded -= $takeQty;

            $usedBatches[] = [
                'stock_batch_id' => $batch->id,
                'quantity' => $takeQty,
                'purchase_price' => $batch->purchase_price,
                'subtotal_hpp' => $batchHpp,
            ];
        }

        // Jika batch stok FIFO kurang/belum diinput, gunakan cost_price default dari master produk
        if ($remainingQtyNeeded > 0) {
            $defaultCostPrice = $product->cost_price ?? 0;
            $fallbackHpp = $remainingQtyNeeded * $defaultCostPrice;
            $totalHpp += $fallbackHpp;

            $usedBatches[] = [
                'stock_batch_id' => null,
                'quantity' => $remainingQtyNeeded,
                'purchase_price' => $defaultCostPrice,
                'subtotal_hpp' => $fallbackHpp,
            ];
        }

        // Update agregat product_stocks
        $productStock = ProductStock::firstOrCreate(
            ['product_id' => $product->id, 'branch_id' => $branchId],
            ['quantity' => $product->stock_quantity ?? 0]
        );
        $productStock->decrement('quantity', $qty);

        // Jika barang ber-IMEI, update status serial
        if ($serialNumber) {
            $serial = ProductSerial::where('product_id', $product->id)
                ->where('branch_id', $branchId)
                ->where('serial_number', $serialNumber)
                ->first();

            if ($serial) {
                $serial->update(['status' => 'sold']);
            }
        }

        return [
            'total_hpp' => $totalHpp,
            'used_batches' => $usedBatches,
        ];
    }

    /**
     * Tambah stok batch baru saat barang masuk/pembelian.
     */
    public function addStockBatch(Product $product, int $branchId, float $qty, float $purchasePrice): StockBatch
    {
        $batch = StockBatch::create([
            'product_id' => $product->id,
            'branch_id' => $branchId,
            'purchase_price' => $purchasePrice,
            'quantity_in' => $qty,
            'quantity_remaining' => $qty,
            'received_at' => now(),
        ]);

        $productStock = ProductStock::firstOrCreate(
            ['product_id' => $product->id, 'branch_id' => $branchId],
            ['quantity' => 0]
        );
        $productStock->increment('quantity', $qty);

        return $batch;
    }
}
