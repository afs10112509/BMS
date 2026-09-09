<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerPoint;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function __construct(
        protected AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Customer::withCount(['points as total_points' => function ($q) {
            $q->select(DB::raw('COALESCE(SUM(points), 0)'));
        }])->latest('id');

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('customer_type')) {
            $query->where('customer_type', $request->string('customer_type')->toString());
        }

        $customers = $query->paginate($request->integer('per_page', 30));

        return response()->json($customers);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:50|unique:customers,phone',
            'customer_type' => 'required|in:umum,reseller,grosir',
            'whatsapp_opt_in' => 'boolean',
        ]);

        $customer = Customer::create([
            'name' => trim($data['name']),
            'phone' => trim($data['phone']),
            'customer_type' => $data['customer_type'],
            'whatsapp_opt_in' => $data['whatsapp_opt_in'] ?? true,
        ]);

        return response()->json([
            'message' => 'Data pelanggan berhasil ditambahkan.',
            'data' => $customer,
        ], 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        $totalPoints = (int) $customer->points()->sum('points');

        return response()->json([
            'data' => $customer->load([
                'points' => fn($q) => $q->latest('id')->limit(20),
                'messages' => fn($q) => $q->latest('id')->limit(20),
            ]),
            'total_points' => $totalPoints,
        ]);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:50|unique:customers,phone,' . $customer->id,
            'customer_type' => 'required|in:umum,reseller,grosir',
            'whatsapp_opt_in' => 'boolean',
        ]);

        $customer->update($data);

        return response()->json([
            'message' => 'Data pelanggan berhasil diperbarui.',
            'data' => $customer,
        ]);
    }

    public function addPoints(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'points' => 'required|integer',
            'type' => 'required|in:earned,redeemed,expired',
            'source_type' => 'required|string',
            'source_id' => 'required|integer',
            'expires_at' => 'nullable|date',
        ]);

        $point = CustomerPoint::create([
            'customer_id' => $customer->id,
            'source_type' => $data['source_type'],
            'source_id' => $data['source_id'],
            'points' => $data['points'],
            'type' => $data['type'],
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        return response()->json([
            'message' => 'Poin pelanggan berhasil dicatat.',
            'data' => $point,
            'current_total_points' => (int) $customer->points()->sum('points'),
        ], 201);
    }
}
