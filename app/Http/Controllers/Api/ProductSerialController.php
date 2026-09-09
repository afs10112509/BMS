<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductSerial;
use App\Services\AuditLogger;
use App\Services\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductSerialController extends Controller
{
    public function __construct(
        protected AuditLogger $auditLogger,
        protected BranchContext $branchContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = ProductSerial::with(['product:id,sku,name', 'branch:id,name']);

        if ($user->isOwner() && $request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        } elseif (!$user->isOwner()) {
            $query->where('branch_id', $user->branch_id);
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->integer('product_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where('serial_number', 'ilike', "%{$search}%");
        }

        $serials = $query->latest('id')->paginate($request->integer('per_page', 50));

        return response()->json($serials);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'branch_id' => 'nullable|exists:branches,id',
            'purchase_price' => 'numeric|min:0',
            'serials' => 'required|array|min:1',
            'serials.*' => 'required|string|unique:product_serials,serial_number',
        ]);

        $resolved = $this->branchContext->resolve($request->user(), $request->integer('branch_id'));
        if ($resolved instanceof JsonResponse) return $resolved;
        $branchId = $resolved;

        $created = DB::transaction(function () use ($data, $branchId) {
            $items = [];
            foreach ($data['serials'] as $sn) {
                $items[] = ProductSerial::create([
                    'product_id' => $data['product_id'],
                    'branch_id' => $branchId,
                    'serial_number' => trim($sn),
                    'purchase_price' => $data['purchase_price'] ?? 0,
                    'status' => 'in_stock',
                ]);
            }
            return $items;
        });

        return response()->json([
            'message' => count($created) . ' serial number IMEI berhasil ditambahkan.',
            'data' => $created,
        ], 201);
    }
}
