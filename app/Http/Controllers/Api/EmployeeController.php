<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Employee;
use App\Models\User;
use App\Services\Payroll\KasbonCategoryLinker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function __construct(
        protected \App\Services\AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Employee::query()
            ->with([
                'branch:id,name,type',
                'branch.branchType:id,code,name,allows_service,status',
                'userAccount:id,employee_id,email,role',
                'kasbonCategory:id,name,type,branch_id',
            ])
            ->join('branches', 'branches.id', '=', 'employees.branch_id')
            ->orderBy('branches.name')
            ->orderBy('employees.name')
            ->select('employees.*');

        if ($user->isAdmin()) {
            if (! $user->branch_id) {
                return response()->json(['message' => 'Admin cabang belum terikat ke cabang.'], 403);
            }
            $query->where('employees.branch_id', $user->branch_id);
        } elseif ($user->isOwner()) {
            if ($request->filled('branch_id')) {
                $query->where('employees.branch_id', $request->integer('branch_id'));
            }
        } else {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        if ($user->isOwner() || $user->isAdmin()) {
            if ($request->filled('status')) {
                $query->where('employees.status', $request->string('status')->toString());
            }

            if ($request->filled('q')) {
                $q = trim($request->string('q')->toString());
                if ($q !== '') {
                    $query->where(function ($builder) use ($q) {
                        $builder->where('employees.name', 'ilike', "%{$q}%")
                            ->orWhere('employees.nickname', 'ilike', "%{$q}%")
                            ->orWhere('employees.nik', 'ilike', "%{$q}%")
                            ->orWhere('employees.phone', 'ilike', "%{$q}%")
                            ->orWhere('employees.position', 'ilike', "%{$q}%")
                            ->orWhere('employees.bank_name', 'ilike', "%{$q}%")
                            ->orWhere('employees.bank_account_name', 'ilike', "%{$q}%")
                            ->orWhere('employees.bank_account_number', 'ilike', "%{$q}%")
                            ->orWhere('employees.emergency_contact', 'ilike', "%{$q}%")
                            ->orWhere('employees.address', 'ilike', "%{$q}%");
                    });
                }
            }
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
                'gender_options' => Employee::genderOptions(),
                'kasbon_categories' => Category::query()
                    ->where('type', 'expense')
                    ->whereRaw("LOWER(name) LIKE 'kasbon%'")
                    ->orderBy('name')
                    ->get(['id', 'name', 'type', 'branch_id']),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate($this->employeeRules(false), $this->employeeRuleMessages());

        if ($user->isAdmin()) {
            if (! $user->branch_id) {
                return response()->json(['message' => 'Admin cabang belum terikat ke cabang.'], 403);
            }
            if ((int) $data['branch_id'] !== (int) $user->branch_id) {
                return response()->json([
                    'message' => 'Admin cabang hanya boleh menambah karyawan di cabangnya sendiri.',
                ], 403);
            }
            $data['branch_id'] = (int) $user->branch_id;
        } elseif (! $user->isOwner()) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $positions = Employee::normalizePositions($data['positions'] ?? []);
        $kasbonCategoryId = $this->resolveKasbonCategoryId($data, ignoreEmployeeId: null);

        $employee = Employee::query()->create([
            'branch_id' => $data['branch_id'],
            'name' => $data['name'],
            'nickname' => $data['nickname'] ?? null,
            'nik' => $data['nik'] ?? null,
            'gender' => $data['gender'] ?? null,
            'phone' => $data['phone'],
            'birth_place' => $data['birth_place'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'bank_account_name' => $data['bank_account_name'] ?? null,
            'bank_account_number' => $data['bank_account_number'] ?? null,
            'emergency_contact' => $data['emergency_contact'] ?? null,
            'address' => $data['address'] ?? null,
            'positions' => $positions,
            'status' => $data['status'] ?? 'active',
            'joined_at' => $data['joined_at'] ?? null,
            'notes' => $data['notes'] ?? null,
            'kasbon_category_id' => $kasbonCategoryId,
        ]);

        $this->auditLogger->log($user, 'CREATE', $employee, null, $employee->toArray());

        return response()->json([
            'message' => 'Karyawan berhasil ditambahkan.',
            'data' => $employee->load(['branch:id,name,type', 'branch.branchType:id,code,name,allows_service,status', 'kasbonCategory:id,name,type,branch_id']),
        ], 201);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            if (! $user->branch_id || (int) $employee->branch_id !== (int) $user->branch_id) {
                return response()->json([
                    'message' => 'Anda tidak boleh mengubah karyawan cabang lain.',
                ], 403);
            }
        } elseif (! $user->isOwner()) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $data = $request->validate($this->employeeRules(true), $this->employeeRuleMessages());

        if ($user->isAdmin()) {
            // Admin tidak boleh memindahkan karyawan ke cabang lain.
            $data['branch_id'] = (int) $user->branch_id;
        }

        if (array_key_exists('positions', $data)) {
            $data['positions'] = Employee::normalizePositions($data['positions'] ?? []);
        }

        unset($data['position']);
        if (array_key_exists('kasbon_category_id', $data)) {
            $raw = $data['kasbon_category_id'];
            $data['kasbon_category_id'] = ($raw === null || $raw === '') ? null : (int) $raw;
        }

        $old = $employee->toArray();
        $employee->fill($data);
        $employee->save();

        $this->auditLogger->log($user, 'UPDATE', $employee, $old, $employee->fresh()->toArray());

        return response()->json([
            'message' => 'Karyawan berhasil diperbarui.',
            'data' => $employee->fresh()->load(['branch:id,name,type', 'branch.branchType:id,code,name,allows_service,status', 'kasbonCategory:id,name,type,branch_id']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function employeeRules(bool $updating): array
    {
        return [
            'branch_id' => [$updating ? 'sometimes' : 'required', 'integer', 'exists:branches,id'],
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'],
            'nickname' => ['nullable', 'string', 'max:100'],
            'nik' => ['nullable', 'string', 'max:32'],
            'gender' => ['nullable', 'string', Rule::in(Employee::GENDER_CODES)],
            'phone' => [$updating ? 'sometimes' : 'required', 'string', 'max:50'],
            'birth_place' => ['nullable', 'string', 'max:120'],
            'birth_date' => ['nullable', 'date'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:100'],
            'emergency_contact' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:1000'],
            'positions' => ['nullable', 'array'],
            'positions.*' => ['string', Rule::in(Employee::POSITION_CODES)],
            'position' => ['nullable', 'string', 'max:100'],
            'status' => [$updating ? 'sometimes' : 'nullable', Rule::in(['active', 'inactive'])],
            'joined_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'kasbon_category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where(function ($q) {
                    $q->where('type', 'expense')->whereRaw("LOWER(name) LIKE 'kasbon%'");
                }),
                $this->kasbonCategoryUniqueRule($updating),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function employeeRuleMessages(): array
    {
        return [
            'kasbon_category_id.exists' => 'Kategori kasbon tidak valid. Pilih kategori pengeluaran yang namanya diawali Kasbon.',
            'kasbon_category_id.unique' => 'Kategori kasbon ini sudah terikat ke karyawan lain.',
        ];
    }

    protected function kasbonCategoryUniqueRule(bool $updating): \Illuminate\Validation\Rules\Unique
    {
        $rule = Rule::unique('employees', 'kasbon_category_id');
        if (! $updating) {
            return $rule;
        }

        $employee = request()->route('employee');
        if ($employee instanceof Employee) {
            return $rule->ignore($employee->id);
        }

        return $rule;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resolveKasbonCategoryId(array $data, ?int $ignoreEmployeeId): ?int
    {
        if (array_key_exists('kasbon_category_id', $data) && $data['kasbon_category_id'] !== null && $data['kasbon_category_id'] !== '') {
            return (int) $data['kasbon_category_id'];
        }

        $taken = Employee::query()
            ->whereNotNull('kasbon_category_id')
            ->when($ignoreEmployeeId, fn ($q) => $q->where('id', '!=', $ignoreEmployeeId))
            ->pluck('kasbon_category_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $cats = Category::query()
            ->where('type', 'expense')
            ->whereRaw("LOWER(name) LIKE 'kasbon%'")
            ->get(['id', 'name', 'type']);

        return KasbonCategoryLinker::suggest((string) ($data['name'] ?? ''), $cats, $taken);
    }

    public function destroy(Request $request, Employee $employee): JsonResponse
    {
        if (! $request->user()->isOwner()) {
            return response()->json(['message' => 'Hanya Owner yang dapat menghapus karyawan.'], 403);
        }

        $old = $employee->toArray();
        $branchId = (int) $employee->branch_id;
        $employee->delete();
        $this->auditLogger->logTable(
            $request->user(),
            'DELETE',
            'employees',
            (int) ($old['id'] ?? 0),
            $old,
            null,
            $branchId,
        );

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
            $old = [
                'email' => $account->email,
                'employee_name' => $employee->name,
                'employee_id' => $employee->id,
            ];
            $account->email = $data['email'];
            $account->name = $data['name'] ?? $employee->name;
            $account->branch_id = $employee->branch_id;
            $account->role = 'employee';
            if (! empty($data['password'])) {
                $account->password = $data['password'];
            }
            $account->save();
            $this->auditLogger->log(
                $request->user(),
                'UPDATE',
                $account,
                $old,
                [
                    'email' => $account->email,
                    'employee_name' => $employee->name,
                    'employee_id' => $employee->id,
                    'branch_id' => $employee->branch_id,
                ],
                (int) $employee->branch_id,
            );
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
            $this->auditLogger->log(
                $request->user(),
                'CREATE',
                $account,
                null,
                [
                    'email' => $account->email,
                    'employee_name' => $employee->name,
                    'employee_id' => $employee->id,
                    'branch_id' => $employee->branch_id,
                ],
                (int) $employee->branch_id,
            );
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
