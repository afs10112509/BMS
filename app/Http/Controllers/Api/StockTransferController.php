<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Services\AuditLogger;
use App\Services\BranchContext;
use App\Services\FifoStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockTransferController extends Controller
{
    public function __construct(
        protected AuditLogger $auditLogger,
        protected BranchContext $branchContext,
        protected FifoStockService $fifoStockService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = StockTransfer::with([
            'fromBranch:id,name',
            'toBranch:id,name',
            'requester:id,name',
            'approver:id,name',
            'receiver:id,name',
            'items.product:id,sku,name',
            'items.serial:id,serial_number',
        ])->latest('id');

        if (!$user->isOwner()) {
            $query->where(function ($q) use ($user) {
                $q->where('from_branch_id', $user->branch_id)
                  ->orWhere('to_branch_id', $user->branch_id);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $transfers = $query->paginate($request->integer('per_page', 30));

        return response()->json($transfers);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_branch_id' => 'required|exists:branches,id',
            'to_branch_id' => 'required|exists:branches,id|different:from_branch_id',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.product_serial_id' => 'nullable|exists:product_serials,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
        ]);

        $user = $request->user();

        $transfer = DB::transaction(function () use ($data, $user) {
            $transfer = StockTransfer::create([
                'from_branch_id' => $data['from_branch_id'],
                'to_branch_id' => $data['to_branch_id'],
                'status' => 'diajukan',
                'requested_by' => $user->id,
                'requested_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $transfer->items()->create([
                    'product_id' => $item['product_id'],
                    'product_serial_id' => $item['product_serial_id'] ?? null,
                    'quantity' => $item['quantity'],
                ]);
            }

            return $transfer;
        });

        return response()->json([
            'message' => 'Pengajuan transfer stok berhasil dibuat.',
            'data' => $transfer->load(['fromBranch', 'toBranch', 'items.product']),
        ], 201);
    }

    public function approve(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        if ($stockTransfer->status !== 'diajukan') {
            return response()->json(['message' => 'Transfer stok hanya bisa disetujui dari status diajukan.'], 422);
        }

        $user = $request->user();

        DB::transaction(function () use ($stockTransfer, $user) {
            foreach ($stockTransfer->items as $item) {
                $product = $item->product;
                
                // Potong stok dari asal (FIFO)
                $fifoResult = $this->fifoStockService->deductFifoStock(
                    $product,
                    $stockTransfer->from_branch_id,
                    $item->quantity
                );

                if ($item->product_serial_id) {
                    ProductSerial::where('id', $item->product_serial_id)->update(['status' => 'transferred']);
                }

                StockMovement::create([
                    'product_id' => $product->id,
                    'branch_id' => $stockTransfer->from_branch_id,
                    'type' => 'out',
                    'quantity' => (int)$item->quantity,
                    'stock_before' => (int)$product->stock_quantity,
                    'stock_after' => max(0, (int)($product->stock_quantity - $item->quantity)),
                    'reference_type' => 'StockTransfer',
                    'reference_id' => $stockTransfer->id,
                    'notes' => "Transfer Keluar ke Cabang #{$stockTransfer->to_branch_id}",
                    'created_by' => $user->id,
                ]);
            }

            $stockTransfer->update([
                'status' => 'dikirim',
                'approved_by' => $user->id,
            ]);
        });

        return response()->json([
            'message' => 'Transfer stok disetujui & barang dalam status dikirim.',
            'data' => $stockTransfer->fresh(['fromBranch', 'toBranch', 'items.product']),
        ]);
    }

    public function receive(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        if ($stockTransfer->status !== 'dikirim') {
            return response()->json(['message' => 'Transfer stok hanya bisa diterima jika status dikirim.'], 422);
        }

        $user = $request->user();

        DB::transaction(function () use ($stockTransfer, $user) {
            foreach ($stockTransfer->items as $item) {
                $product = $item->product;
                $costPrice = $product->cost_price ?? 0;

                // Tambah stok ke cabang tujuan (FIFO Batch)
                $this->fifoStockService->addStockBatch(
                    $product,
                    $stockTransfer->to_branch_id,
                    $item->quantity,
                    $costPrice
                );

                if ($item->product_serial_id) {
                    ProductSerial::where('id', $item->product_serial_id)->update([
                        'branch_id' => $stockTransfer->to_branch_id,
                        'status' => 'in_stock',
                    ]);
                }

                StockMovement::create([
                    'product_id' => $product->id,
                    'branch_id' => $stockTransfer->to_branch_id,
                    'type' => 'in',
                    'quantity' => (int)$item->quantity,
                    'stock_before' => (int)$product->stock_quantity,
                    'stock_after' => (int)($product->stock_quantity + $item->quantity),
                    'reference_type' => 'StockTransfer',
                    'reference_id' => $stockTransfer->id,
                    'notes' => "Transfer Masuk dari Cabang #{$stockTransfer->from_branch_id}",
                    'created_by' => $user->id,
                ]);
            }

            $stockTransfer->update([
                'status' => 'diterima',
                'received_by' => $user->id,
                'received_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Transfer stok berhasil diterima & stok cabang tujuan bertambah.',
            'data' => $stockTransfer->fresh(['fromBranch', 'toBranch', 'items.product']),
        ]);
    }

    public function reject(Request $request, StockTransfer $stockTransfer): JsonResponse
    {
        if (!in_array($stockTransfer->status, ['diajukan', 'dikirim'])) {
            return response()->json(['message' => 'Transfer stok yang sudah diterima/ditolak tidak dapat diubah.'], 422);
        }

        $user = $request->user();

        $stockTransfer->update([
            'status' => 'ditolak',
            'approved_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Transfer stok berhasil ditolak.',
            'data' => $stockTransfer,
        ]);
    }
}
