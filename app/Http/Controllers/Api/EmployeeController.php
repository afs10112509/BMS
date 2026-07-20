<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Employee::query()
            ->with(['branch:id,name,type', 'branch.branchType:id,code,name,allows_service,status'])
            ->join('branches', 'branches.id', '=', 'employees.branch_id')
            ->orderBy('branches.name')
            ->orderBy('employees.name')
            ->select('employees.*');

        if ($user->isAdmin()) {
            $query->where('employees.branch_id', $user->branch_id)
                ->where('employees.status', 'active');
        } elseif ($user->isOwner()) {
            if ($request->filled('branch_id')) {
                $query->where('employees.branch_id', $request->integer('branch_id'));
            }

            if ($request->filled('status')) {
                $query->where('employees.status', $request->string('status')->toString());
            }

            if ($request->filled('q')) {
                $q = trim($request->string('q')->toString());
                if ($q !== '') {
                    $query->where(function ($builder) use ($q) {
                        $builder->where('employees.name', 'ilike', "%{$q}%")
                            ->orWhere('employees.phone', 'ilike', "%{$q}%")
                            ->orWhere('employees.position', 'ilike', "%{$q}%");
                    });
                }
            }
        } else {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        return response()->json([
            'message' => 'Daftar karyawan berhasil diambil.',
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'position' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'joined_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $employee = Employee::query()->create([
            'branch_id' => $data['branch_id'],
            'name' => $data['name'],
            'phone' => $data['phone'],
            'position' => $data['position'] ?? null,
            'status' => $data['status'] ?? 'active',
            'joined_at' => $data['joined_at'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json([
            'message' => 'Karyawan berhasil ditambahkan.',
            'data' => $employee->load(['branch:id,name,type', 'branch.branchType:id,code,name,allows_service,status']),
        ], 201);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', 'max:50'],
            'position' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'joined_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $employee->fill($data);
        $employee->save();

        return response()->json([
            'message' => 'Karyawan berhasil diperbarui.',
            'data' => $employee->fresh()->load(['branch:id,name,type', 'branch.branchType:id,code,name,allows_service,status']),
        ]);
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $employee->delete();

        return response()->json([
            'message' => 'Karyawan berhasil dihapus.',
        ]);
    }
}
