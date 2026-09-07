<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function __construct(
        protected AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Supplier::query()->withCount('products');

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('contact_person', 'ilike', "%{$search}%");
            });
        }

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        $suppliers = $query->orderBy('name')->paginate($request->integer('per_page', 50));

        return response()->json($suppliers);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $supplier = Supplier::create($data);

        $this->auditLogger->log($request->user(), 'CREATE', $supplier, null, $supplier->toArray());

        return response()->json([
            'message' => 'Supplier berhasil ditambahkan.',
            'data' => $supplier,
        ], 201);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $before = $supplier->toArray();

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'notes' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $supplier->update($data);

        $this->auditLogger->log($request->user(), 'UPDATE', $supplier, $before, $supplier->fresh()->toArray());

        return response()->json([
            'message' => 'Supplier berhasil diperbarui.',
            'data' => $supplier->fresh(),
        ]);
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $before = $supplier->toArray();
        $supplier->update(['is_active' => false]);

        $this->auditLogger->log($request->user(), 'UPDATE', $supplier, $before, $supplier->fresh()->toArray());

        return response()->json([
            'message' => 'Supplier berhasil dinonaktifkan.',
        ]);
    }
}
