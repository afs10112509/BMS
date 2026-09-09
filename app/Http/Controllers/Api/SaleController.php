<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Services\AuditLogger;
use App\Services\AutoJournalService;
use App\Services\BranchContext;
use App\Services\FifoStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function __construct(
        protected AuditLogger $auditLogger,
        protected BranchContext $branchContext,
        protected FifoStockService $fifoStockService,
        protected AutoJournalService $autoJournalService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Sale::query()
            ->with(['items.product:id,sku,name', 'createdBy:id,name', 'branch:id,name'])
            ->latest('sale_date')
            ->latest('id');

        if ($user->isOwner() && $request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('sale_date', '>=', $request->date('date_from')->toDateString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('sale_date', '<=', $request->date('date_to')->toDateString());
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->string('payment_method')->toString());
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'ilike', "%{$search}%")
                  ->orWhere('customer_name', 'ilike', "%{$search}%");
            });
        }

        $sales = $query->paginate($request->integer('per_page', 30));

        return response()->json($sales);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sale_date' => 'required|date',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:255',
            'payment_method' => 'required|in:cash,transfer,qris,debit,credit',
            'discount' => 'numeric|min:0',
            'tax' => 'numeric|min:0',
            'notes' => 'nullable|string',
            'trade_in' => 'nullable|array',
            'trade_in.traded_product_name' => 'required_with:trade_in|string|max:255',
            'trade_in.traded_serial_number' => 'nullable|string|max:255',
            'trade_in.appraised_value' => 'required_with:trade_in|numeric|min:0',
            'trade_in.notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'numeric|min:0',
        ]);

        $resolved = $this->branchContext->resolve($request->user(), $request->integer('branch_id'));
        if ($resolved instanceof JsonResponse) return $resolved;
        $branchId = $resolved;
        $user = $request->user();

        $sale = DB::transaction(function () use ($data, $branchId, $user) {
            $invoiceNumber = Sale::generateInvoiceNumber($branchId);

            $subtotal = 0;
            $saleItems = [];

            // Validasi stok dan siapkan item
            foreach ($data['items'] as $item) {
                $product = Product::withoutGlobalScopes()
                    ->where('id', $item['product_id'])
                    ->where('branch_id', $branchId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($product->stock_quantity < $item['quantity']) {
                    throw new \RuntimeException(
                        "Stok {$product->name} tidak cukup. Tersedia: {$product->stock_quantity}, diminta: {$item['quantity']}"
                    );
                }

                $itemDiscount = $item['discount'] ?? 0;
                $itemSubtotal = ($item['unit_price'] * $item['quantity']) - $itemDiscount;
                $subtotal += $itemSubtotal;

                $saleItems[] = [
                    'product' => $product,
                    'data' => [
                        'product_id' => $product->id,
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'discount' => $itemDiscount,
                        'subtotal' => $itemSubtotal,
                    ],
                ];
            }

            $discount = $data['discount'] ?? 0;
            $tax = $data['tax'] ?? 0;
            $total = $subtotal - $discount + $tax;

            // Buat sale
            $sale = Sale::create([
                'branch_id' => $branchId,
                'invoice_number' => $invoiceNumber,
                'sale_date' => $data['sale_date'],
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'total' => $total,
                'payment_method' => $data['payment_method'],
                'status' => 'completed',
                'created_by' => $user->id,
                'notes' => $data['notes'] ?? null,
            ]);

            // Jika ada tukar tambah (Trade In)
            if (!empty($data['trade_in']['traded_product_name'])) {
                \App\Models\TradeIn::create([
                    'sale_id' => $sale->id,
                    'traded_product_name' => $data['trade_in']['traded_product_name'],
                    'traded_serial_number' => $data['trade_in']['traded_serial_number'] ?? null,
                    'appraised_value' => $data['trade_in']['appraised_value'] ?? 0,
                    'notes' => $data['trade_in']['notes'] ?? null,
                ]);
            }

            $totalHpp = 0;

            // Buat sale items & kurangi stok (FIFO)
            foreach ($saleItems as $saleItem) {
                $sale->items()->create($saleItem['data']);

                $product = $saleItem['product'];
                $stockBefore = $product->stock_quantity;
                $stockAfter = $stockBefore - $saleItem['data']['quantity'];

                $product->update(['stock_quantity' => $stockAfter]);

                // FIFO deduction
                $fifoResult = $this->fifoStockService->deductFifoStock(
                    $product,
                    $branchId,
                    $saleItem['data']['quantity']
                );
                $totalHpp += $fifoResult['total_hpp'];

                StockMovement::create([
                    'product_id' => $product->id,
                    'branch_id' => $branchId,
                    'type' => 'out',
                    'quantity' => $saleItem['data']['quantity'],
                    'stock_before' => $stockBefore,
                    'stock_after' => $stockAfter,
                    'reference_type' => 'Sale',
                    'reference_id' => $sale->id,
                    'notes' => "Penjualan #{$sale->invoice_number} (FIFO HPP: Rp " . number_format($fifoResult['total_hpp'], 0, ',', '.') . ")",
                    'created_by' => $user->id,
                ]);
            }

            // Auto Journal SAK EMKM
            try {
                $cashAcc = Account::where('code', 'cash')->first() ?? Account::first();
                $salesAcc = Account::where('code', '4-1000')->first();
                $hppAcc = Account::where('code', '5-1000')->first();
                $invAcc = Account::where('code', '1-1200')->first();

                if ($cashAcc && $salesAcc && $hppAcc && $invAcc) {
                    $this->autoJournalService->recordSaleJournal(
                        branchId: $branchId,
                        saleDate: $sale->sale_date,
                        invoiceNumber: $sale->invoice_number,
                        totalAmount: $sale->total,
                        totalHpp: $totalHpp,
                        cashAccountId: $cashAcc->id,
                        salesAccountId: $salesAcc->id,
                        hppAccountId: $hppAcc->id,
                        inventoryAccountId: $invAcc->id,
                        saleId: $sale->id,
                        userId: $user->id
                    );
                }
            } catch (\Throwable $e) {
                // Log atau ignore jika akun COA belum lengkap
            }

            return $sale;
        });

        $this->auditLogger->log($user, 'CREATE', $sale, null, $sale->toArray());

        return response()->json([
            'message' => 'Penjualan berhasil dicatat.',
            'data' => $sale->load(['items.product:id,sku,name', 'createdBy:id,name']),
        ], 201);
    }

    public function show(Sale $sale): JsonResponse
    {
        return response()->json([
            'data' => $sale->load([
                'items.product:id,sku,name,brand,model',
                'createdBy:id,name',
                'branch:id,name',
            ]),
        ]);
    }

    public function cancel(Request $request, Sale $sale): JsonResponse
    {
        if ($sale->status === 'cancelled') {
            return response()->json(['message' => 'Penjualan sudah dibatalkan.'], 422);
        }

        $before = $sale->toArray();
        $user = $request->user();

        DB::transaction(function () use ($sale, $user) {
            // Kembalikan stok untuk setiap item
            foreach ($sale->items as $item) {
                $product = Product::withoutGlobalScopes()->lockForUpdate()->find($item->product_id);

                if ($product) {
                    $stockBefore = $product->stock_quantity;
                    $stockAfter = $stockBefore + $item->quantity;

                    $product->update(['stock_quantity' => $stockAfter]);

                    StockMovement::create([
                        'product_id' => $product->id,
                        'branch_id' => $sale->branch_id,
                        'type' => 'in',
                        'quantity' => $item->quantity,
                        'stock_before' => $stockBefore,
                        'stock_after' => $stockAfter,
                        'reference_type' => 'Sale',
                        'reference_id' => $sale->id,
                        'notes' => "Pembatalan #{$sale->invoice_number}",
                        'created_by' => $user->id,
                    ]);
                }
            }

            $sale->update(['status' => 'cancelled']);
        });

        $this->auditLogger->log($user, 'UPDATE', $sale, $before, $sale->fresh()->toArray());

        return response()->json([
            'message' => 'Penjualan berhasil dibatalkan. Stok dikembalikan.',
            'data' => $sale->fresh()->load('items.product:id,sku,name'),
        ]);
    }

    public function dailySummary(Request $request): JsonResponse
    {
        $request->validate([
            'date' => 'required|date',
        ]);

        $user = $request->user();
        $date = $request->date('date')->toDateString();

        $query = Sale::query()
            ->whereDate('sale_date', $date)
            ->where('status', 'completed');

        if ($user->isOwner() && $request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        $summary = $query
            ->selectRaw("payment_method, COUNT(*) as total_sales, SUM(total) as total_amount")
            ->groupBy('payment_method')
            ->get();

        $grandTotal = $summary->sum('total_amount');
        $totalSales = $summary->sum('total_sales');

        return response()->json([
            'date' => $date,
            'by_payment_method' => $summary,
            'grand_total' => $grandTotal,
            'total_sales' => $totalSales,
        ]);
    }
}
