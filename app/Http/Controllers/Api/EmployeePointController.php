<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeePoint;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeePointController extends Controller
{
    public function __construct(
        protected AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = EmployeePoint::with(['employee:id,name,branch_id', 'employee.branch:id,name'])->latest('id');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        $points = $query->paginate($request->integer('per_page', 30));

        return response()->json($points);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'transaction_amount' => 'required|numeric|min:0',
            'source_type' => 'required|string', // e.g. transaction, service
            'source_id' => 'required|integer',
            'note' => 'nullable|string',
        ]);

        // PRD Rule: Setiap Rp 10.000 transaksi/servis = 1 poin (1 poin = Rp 1.000)
        $earnedPoints = (int) floor($data['transaction_amount'] / 10000);

        if ($earnedPoints <= 0) {
            return response()->json([
                'message' => 'Nilai transaksi kurang dari Rp 10.000, poin tidak dihasilkan.',
            ], 422);
        }

        $point = EmployeePoint::create([
            'employee_id' => $data['employee_id'],
            'source_type' => $data['source_type'],
            'source_id' => $data['source_id'],
            'points' => $earnedPoints,
            'type' => 'earned',
            'note' => $data['note'] ?? "Bonus poin dari transaksi Rp " . number_format($data['transaction_amount'], 0, ',', '.'),
        ]);

        $totalPoints = (int) EmployeePoint::where('employee_id', $data['employee_id'])->sum('points');
        $bonusNominal = $totalPoints * 1000;

        return response()->json([
            'message' => "{$earnedPoints} Poin berhasil ditambahkan ke karyawan.",
            'data' => $point,
            'summary' => [
                'total_points' => $totalPoints,
                'equivalent_bonus_rupiah' => $bonusNominal,
            ],
        ], 201);
    }

    public function summary(Request $request): JsonResponse
    {
        $employees = Employee::with('branch:id,name')
            ->get()
            ->map(function ($emp) {
                $totalPoints = (int) EmployeePoint::where('employee_id', $emp->id)->sum('points');
                return [
                    'employee_id' => $emp->id,
                    'name' => $emp->name,
                    'position' => $emp->position,
                    'branch' => $emp->branch?->name,
                    'total_points' => $totalPoints,
                    'bonus_rupiah' => $totalPoints * 1000,
                ];
            });

        return response()->json(['data' => $employees]);
    }
}
