<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Employee::query()
            ->with([
                'branch:id,name,type',
                'branch.branchType:id,code,name,allows_service,status',
                'userAccount:id,employee_id,email,role',
            ])
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

        if ($request->filled('has_position')) {
            $code = mb_strtolower(trim($request->string('has_position')->toString()));
            if (in_array($code, Employee::POSITION_CODES, true)) {
                $query->withPosition($code);
            }
        }

        return response()->json([
            'message' => 'Daftar karyawan berhasil diambil.',
            'data' => $query->get(),
            'meta' => [
                'position_options' => Employee::positionOptions(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'positions' => ['nullable', 'array'],
            'positions.*' => ['string', Rule::in(Employee::POSITION_CODES)],
            'position' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'joined_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $positions = Employee::normalizePositions($data['positions'] ?? []);

        $employee = Employee::query()->create([
            'branch_id' => $data['branch_id'],
            'name' => $data['name'],
            'phone' => $data['phone'],
            'positions' => $positions,
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
            'positions' => ['nullable', 'array'],
            'positions.*' => ['string', Rule::in(Employee::POSITION_CODES)],
            'position' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'joined_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        if (array_key_exists('positions', $data)) {
            $data['positions'] = Employee::normalizePositions($data['positions'] ?? []);
        }

        unset($data['position']);

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

    /** Buat / perbarui akun login karyawan (Owner). */
    public function upsertAccount(Request $request, Employee $employee): JsonResponse
    {
        if (! $request->user()->isOwner()) {
            return response()->json(['message' => 'Hanya Owner yang dapat membuat akun karyawan.'], 403);
        }
        if (! $employee->isActive()) {
            return response()->json(['message' => 'Karyawan nonaktif tidak dapat diberi akun login.'], 422);
        }

        $employee->load('userAccount');
        $data = $request->validate([
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($employee->userAccount?->id),
            ],
            'password' => ['nullable', 'string', 'min:6'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $account = $employee->userAccount;
        if (! $account && empty($data['password'])) {
            return response()->json(['message' => 'Kata sandi wajib diisi untuk akun baru.'], 422);
        }

        if ($account) {
            $account->email = $data['email'];
            $account->name = $data['name'] ?? $employee->name;
            $account->branch_id = $employee->branch_id;
            $account->role = 'employee';
            if (! empty($data['password'])) {
                $account->password = $data['password'];
            }
            $account->save();
            $message = 'Akun login karyawan diperbarui.';
        } else {
            $account = User::query()->create([
                'name' => $data['name'] ?? $employee->name,
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => 'employee',
                'branch_id' => $employee->branch_id,
                'employee_id' => $employee->id,
            ]);
            $message = 'Akun login karyawan dibuat.';
        }

        return response()->json([
            'message' => $message,
            'data' => [
                'employee_id' => $employee->id,
                'user_id' => $account->id,
                'email' => $account->email,
                'has_login' => true,
            ],
        ]);
    }
}
