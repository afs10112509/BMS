<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\AuditLogger;
use App\Services\BranchContext;
use App\Services\FifoStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function __construct(
        protected AuditLogger $auditLogger,
        protected BranchContext $branchContext,
        protected FifoStockService $fifoStockService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Product::query()
            ->with(['supplier:id,name', 'branch:id,name'])
            ->latest('updated_at');

        if ($user->isOwner() && $request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        if ($request->filled('type')) {
            $query->ofType($request->string('type')->toString());
        }

        if ($request->filled('brand')) {
            $query->where('brand', 'ilike', '%' . $request->string('brand')->toString() . '%');
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->integer('supplier_id'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('sku', 'ilike', "%{$search}%")
                  ->orWhere('brand', 'ilike', "%{$search}%")
                  ->orWhere('model', 'ilike', "%{$search}%");
            });
        }

        if ($request->boolean('low_stock')) {
            $query->lowStock();
        }

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        } else {
            $query->active();
        }

        $products = $query->paginate($request->integer('per_page', 50));

        return response()->json($products);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sku' => 'nullable|string|max:255|unique:products,sku',
            'barcode' => 'nullable|string|max:255|unique:products,barcode',
            'base_unit' => 'nullable|string|max:50',
            'requires_serial' => 'boolean',
            'name' => 'required|string|max:255',
            'type' => 'required|in:phone,accessory,spare_part,other',
            'description' => 'nullable|string',
            'brand' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'cost_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'stock_quantity' => 'integer|min:0',
            'min_stock' => 'integer|min:0',
            'max_stock' => 'nullable|integer|min:0',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'image_url' => 'nullable|string',
            'allow_open_price' => 'nullable|boolean',
            'allow_open_discount' => 'nullable|boolean',
            'show_stock_reminder' => 'nullable|boolean',
        ]);

        $resolved = $this->branchContext->resolve($request->user(), $request->integer('branch_id'));
        if ($resolved instanceof JsonResponse) return $resolved;
        $branchId = $resolved;
        $data['branch_id'] = $branchId;

        // Auto-generate SKU if not provided
        if (empty($data['sku'])) {
            $typePrefix = match ($data['type']) {
                'phone' => 'HP',
                'accessory' => 'AK',
                'spare_part' => 'SP',
                default => 'OT',
            };
            $prefix = "BR{$branchId}-{$typePrefix}";
            $lastProduct = Product::withoutGlobalScopes()
                ->where('sku', 'like', "{$prefix}-%")
                ->orderByDesc('id')
                ->first();

            $seq = 1;
            if ($lastProduct) {
                $parts = explode('-', $lastProduct->sku);
                $seq = ((int) end($parts)) + 1;
            }

            $data['sku'] = "{$prefix}-" . str_pad($seq, 4, '0', STR_PAD_LEFT);
        }

        $product = Product::create($data);

        // Catat stok awal & Batch FIFO
        if (($data['stock_quantity'] ?? 0) > 0) {
            $this->fifoStockService->addStockBatch(
                $product,
                $branchId,
                $product->stock_quantity,
                $product->cost_price
            );

            StockMovement::create([
                'product_id' => $product->id,
                'branch_id' => $branchId,
                'type' => 'in',
                'quantity' => $product->stock_quantity,
                'stock_before' => 0,
                'stock_after' => $product->stock_quantity,
                'notes' => 'Stok awal master produk',
                'created_by' => $request->user()->id,
            ]);
        }

        $this->auditLogger->log($request->user(), 'CREATE', $product, null, $product->toArray());

        return response()->json([
            'message' => 'Produk berhasil ditambahkan.',
            'data' => $product->load('supplier:id,name'),
        ], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $before = $product->toArray();

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|in:phone,accessory,spare_part,other',
            'description' => 'nullable|string',
            'brand' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'cost_price' => 'sometimes|numeric|min:0',
            'selling_price' => 'sometimes|numeric|min:0',
            'min_stock' => 'sometimes|integer|min:0',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'is_active' => 'sometimes|boolean',
        ]);

        $product->update($data);

        $this->auditLogger->log($request->user(), 'UPDATE', $product, $before, $product->fresh()->toArray());

        return response()->json([
            'message' => 'Produk berhasil diperbarui.',
            'data' => $product->fresh()->load('supplier:id,name'),
        ]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $before = $product->toArray();
        $product->update(['is_active' => false]);

        $this->auditLogger->log($request->user(), 'UPDATE', $product, $before, $product->fresh()->toArray());

        return response()->json([
            'message' => 'Produk berhasil dinonaktifkan.',
        ]);
    }

    public function adjustStock(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|in:in,out,adjustment',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        $stockBefore = $product->stock_quantity;

        if ($data['type'] === 'out' && $data['quantity'] > $stockBefore) {
            return response()->json([
                'message' => "Stok tidak mencukupi. Tersedia: {$stockBefore}",
            ], 422);
        }

        $stockAfter = match ($data['type']) {
            'in' => $stockBefore + $data['quantity'],
            'out' => $stockBefore - $data['quantity'],
            'adjustment' => $data['quantity'],
        };

        DB::transaction(function () use ($product, $data, $stockBefore, $stockAfter, $request) {
            $product->update(['stock_quantity' => $stockAfter]);

            StockMovement::create([
                'product_id' => $product->id,
                'branch_id' => $product->branch_id,
                'type' => $data['type'],
                'quantity' => $data['type'] === 'adjustment' ? abs($stockAfter - $stockBefore) : $data['quantity'],
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'notes' => $data['notes'] ?? 'Penyesuaian manual',
                'created_by' => $request->user()->id,
            ]);
        });

        $this->auditLogger->log($request->user(), 'UPDATE', $product,
            ['stock_quantity' => $stockBefore],
            ['stock_quantity' => $stockAfter]
        );

        return response()->json([
            'message' => 'Stok berhasil disesuaikan.',
            'data' => [
                'product_id' => $product->id,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
            ],
        ]);
    }

    public function lowStock(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Product::query()
            ->with(['supplier:id,name', 'branch:id,name'])
            ->active()
            ->lowStock()
            ->orderBy('stock_quantity');

        if ($user->isOwner() && $request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        $products = $query->get();

        return response()->json([
            'data' => $products,
            'total' => $products->count(),
        ]);
    }
}
