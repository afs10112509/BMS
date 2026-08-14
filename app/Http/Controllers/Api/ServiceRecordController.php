<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\ServiceRecord;
use App\Services\PayrollLockChecker;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServiceRecordController extends Controller
{
    public function __construct(
        protected PayrollLockChecker $payrollLockChecker,
    ) {}

    public function technicians(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            if ($this->isWorkshopBranch($user->branch_id)) {
                return response()->json([
                    'message' => 'Tipe cabang ini tidak menggunakan modul Service.',
                    'data' => [],
                ], 403);
            }
            $branchId = (int) $user->branch_id;
        } elseif ($user->isOwner()) {
            $branchId = $request->filled('branch_id') ? $request->integer('branch_id') : null;
            if ($branchId && $this->isWorkshopBranch($branchId)) {
                return response()->json([
                    'message' => 'Modul catatan servis hanya untuk cabang konter.',
                    'data' => [],
                ], 422);
            }
        } else {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $konterIds = $this->konterBranchIds();

        $query = Employee::query()
            ->where('employees.status', 'active')
            ->where(function ($q) {
                $q->withPosition(Employee::POS_TEKNISI)
                    ->orWhere('employees.position', 'ilike', '%teknisi%');
            })
            ->orderBy('employees.name')
            ->select([
                'employees.id',
                'employees.branch_id',
                'employees.name',
                'employees.phone',
                'employees.position',
                'employees.positions',
                'employees.status',
            ]);

        if ($branchId) {
            $query->where('employees.branch_id', $branchId);
        } else {
            $query->whereIn('employees.branch_id', $konterIds ?: [0]);
        }

        $rows = $query->get();

        return response()->json([
            'message' => 'Daftar teknisi berhasil diambil.',
            'data' => $rows,
            'meta' => [
                'branch_id' => $branchId,
                'count' => $rows->count(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = ServiceRecord::query()
            ->with([
                'branch:id,name,type',
                'branch.branchType:id,code,name,allows_service,status',
                'employee:id,name,phone',
                'user:id,name',
            ])
            ->latest('service_date')
            ->latest('id');

        if ($user->isAdmin()) {
            if ($this->isWorkshopBranch($user->branch_id)) {
                return response()->json([
                    'message' => 'Tipe cabang ini tidak menggunakan modul Service.',
                    'data' => [],
                ], 403);
            }
            $query->where('branch_id', $user->branch_id);
        } elseif ($user->isOwner()) {
            if ($request->filled('branch_id')) {
                $branchId = $request->integer('branch_id');
                if ($this->isWorkshopBranch($branchId)) {
                    return response()->json([
                        'message' => 'Modul catatan servis hanya untuk cabang konter.',
                    ], 422);
                }
                $query->where('branch_id', $branchId);
            } else {
                $query->whereIn('branch_id', $this->konterBranchIds() ?: [0]);
            }
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('service_date', '>=', $request->date('date_from')->toDateString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('service_date', '<=', $request->date('date_to')->toDateString());
        }

        if ($request->filled('q')) {
            $q = trim($request->string('q')->toString());
            if ($q !== '') {
                $query->where(function ($builder) use ($q) {
                    $builder->where('brand', 'ilike', "%{$q}%")
                        ->orWhere('device_type', 'ilike', "%{$q}%")
                        ->orWhere('damage', 'ilike', "%{$q}%");
                });
            }
        }

        // Ringkasan dari seluruh hasil filter (bukan hanya halaman aktif).
        $summary = [
            'jumlah' => (clone $query)->count(),
            'total_modal' => (float) (clone $query)->sum('cost'),
            'total_harga' => (float) (clone $query)->sum('price'),
            'total_profit' => (float) (clone $query)->sum('profit'),
        ];

        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $rows = $query->paginate($perPage);

        return response()->json([
            'message' => 'Daftar catatan servis berhasil diambil.',
            'data' => $rows,
            'summary' => $summary,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $blocked = $this->assertCanMutate($user);
        if ($blocked) {
            return $blocked;
        }

        $data = $this->validated($request);
        $branchId = (int) $user->branch_id;

        if ($this->isWorkshopBranch($branchId)) {
            return response()->json([
                'message' => 'Tipe cabang ini tidak dapat menginput catatan servis.',
            ], 403);
        }

        $employee = Employee::query()->findOrFail($data['employee_id']);
        if ((int) $employee->branch_id !== $branchId) {
            return response()->json([
                'message' => 'Teknisi harus dari cabang Anda.',
            ], 422);
        }

        if ($employee->status !== 'active') {
            return response()->json([
                'message' => 'Teknisi harus berstatus aktif.',
            ], 422);
        }

        if (! $employee->isTechnician()) {
            return response()->json([
                'message' => 'Karyawan harus memiliki jabatan Teknisi.',
            ], 422);
        }

        $this->payrollLockChecker->assertEmployeeDateOpen(
            $employee->id,
            Carbon::parse($data['service_date'])->toDateString(),
        );

        $record = ServiceRecord::query()->create([
            'branch_id' => $branchId,
            'employee_id' => $employee->id,
            'user_id' => $user->id,
            'service_date' => $data['service_date'],
            'brand' => $data['brand'],
            'device_type' => $data['device_type'],
            'damage' => $data['damage'],
            'cost' => $data['cost'],
            'price' => $data['price'],
            'profit' => ServiceRecord::calcProfit($data['price'], $data['cost']),
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json([
            'message' => 'Catatan servis berhasil disimpan.',
            'data' => $record->load([
                'branch:id,name,type',
                'branch.branchType:id,code,name,allows_service,status',
                'employee:id,name,phone',
                'user:id,name',
            ]),
        ], 201);
    }

    public function storeBatch(Request $request): JsonResponse
    {
        $user = $request->user();
        $blocked = $this->assertCanMutate($user);
        if ($blocked) {
            return $blocked;
        }

        $data = $request->validate([
            'service_date' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.employee_id' => ['required', 'integer', 'exists:employees,id'],
            'items.*.brand' => ['required', 'string', 'max:100'],
            'items.*.device_type' => ['required', 'string', 'max:100'],
            'items.*.damage' => ['required', 'string', 'max:150'],
            'items.*.cost' => ['required', 'numeric', 'gte:0'],
            'items.*.price' => ['required', 'numeric', 'gte:0'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        $branchId = (int) $user->branch_id;

        if ($this->isWorkshopBranch($branchId)) {
            return response()->json([
                'message' => 'Tipe cabang ini tidak dapat menginput catatan servis.',
            ], 403);
        }

        $serviceDate = Carbon::parse($data['service_date'])->toDateString();
        $employeeIds = collect($data['items'])->pluck('employee_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        $employees = Employee::query()
            ->whereIn('id', $employeeIds)
            ->get()
            ->keyBy('id');

        foreach ($data['items'] as $index => $item) {
            $row = $index + 1;
            $employee = $employees->get((int) $item['employee_id']);
            if (! $employee) {
                return response()->json([
                    'message' => "Teknisi tidak ditemukan (baris {$row}).",
                ], 422);
            }
            if ((int) $employee->branch_id !== $branchId) {
                return response()->json([
                    'message' => "Teknisi harus dari cabang Anda (baris {$row}).",
                ], 422);
            }
            if ($employee->status !== 'active') {
                return response()->json([
                    'message' => "Teknisi harus berstatus aktif (baris {$row}).",
                ], 422);
            }
            if (! $employee->isTechnician()) {
                return response()->json([
                    'message' => "Karyawan harus memiliki jabatan Teknisi (baris {$row}).",
                ], 422);
            }
        }

        $this->payrollLockChecker->assertEmployeesDateOpen($employeeIds, $serviceDate);

        $created = DB::transaction(function () use ($data, $branchId, $user, $serviceDate) {
            $rows = [];
            foreach ($data['items'] as $item) {
                $cost = $item['cost'];
                $price = $item['price'];
                $record = ServiceRecord::query()->create([
                    'branch_id' => $branchId,
                    'employee_id' => (int) $item['employee_id'],
                    'user_id' => $user->id,
                    'service_date' => $serviceDate,
                    'brand' => trim($item['brand']),
                    'device_type' => trim($item['device_type']),
                    'damage' => trim($item['damage']),
                    'cost' => $cost,
                    'price' => $price,
                    'profit' => ServiceRecord::calcProfit($price, $cost),
                    'notes' => $item['notes'] ?? null,
                ]);
                $rows[] = $record->load([
                    'branch:id,name,type',
                    'branch.branchType:id,code,name,allows_service,status',
                    'employee:id,name,phone',
                    'user:id,name',
                ]);
            }

            return $rows;
        });

        return response()->json([
            'message' => count($created).' catatan servis berhasil disimpan.',
            'meta' => [
                'branch_id' => $branchId,
                'service_date' => $serviceDate,
                'created_count' => count($created),
                'total_cost' => round(collect($created)->sum(fn ($r) => (float) $r->cost), 2),
                'total_price' => round(collect($created)->sum(fn ($r) => (float) $r->price), 2),
                'total_profit' => round(collect($created)->sum(fn ($r) => (float) $r->profit), 2),
            ],
            'data' => $created,
        ], 201);
    }

    public function update(Request $request, ServiceRecord $serviceRecord): JsonResponse
    {
        $user = $request->user();
        $blocked = $this->assertCanMutate($user);
        if ($blocked) {
            return $blocked;
        }

        if ((int) $serviceRecord->branch_id !== (int) $user->branch_id) {
            return response()->json([
                'message' => 'Anda hanya dapat mengubah catatan servis cabang sendiri.',
            ], 403);
        }

        $data = $this->validated($request, true);

        $oldEmployeeId = (int) $serviceRecord->employee_id;
        $newEmployeeId = isset($data['employee_id']) ? (int) $data['employee_id'] : $oldEmployeeId;
        $oldDate = $serviceRecord->service_date?->toDateString()
            ?? Carbon::parse($serviceRecord->service_date)->toDateString();
        $newDate = isset($data['service_date'])
            ? Carbon::parse($data['service_date'])->toDateString()
            : $oldDate;

        $this->payrollLockChecker->assertEmployeeDateOpen($oldEmployeeId, $oldDate);
        if ($newEmployeeId !== $oldEmployeeId || $newDate !== $oldDate) {
            $this->payrollLockChecker->assertEmployeeDateOpen($newEmployeeId, $newDate);
        }

        if (isset($data['employee_id'])) {
            $employee = Employee::query()->findOrFail($data['employee_id']);
            if ((int) $employee->branch_id !== (int) $user->branch_id) {
                return response()->json([
                    'message' => 'Teknisi harus dari cabang Anda.',
                ], 422);
            }
            if ($employee->status !== 'active' && (int) $employee->id !== (int) $serviceRecord->employee_id) {
                return response()->json([
                    'message' => 'Teknisi harus berstatus aktif.',
                ], 422);
            }
            if (! $employee->isTechnician()) {
                return response()->json([
                    'message' => 'Karyawan harus memiliki jabatan Teknisi.',
                ], 422);
            }
        }

        $cost = $data['cost'] ?? $serviceRecord->cost;
        $price = $data['price'] ?? $serviceRecord->price;

        $serviceRecord->fill($data);
        $serviceRecord->profit = ServiceRecord::calcProfit($price, $cost);
        $serviceRecord->save();

        return response()->json([
            'message' => 'Catatan servis berhasil diperbarui.',
            'data' => $serviceRecord->fresh()->load([
                'branch:id,name,type',
                'branch.branchType:id,code,name,allows_service,status',
                'employee:id,name,phone',
                'user:id,name',
            ]),
        ]);
    }

    public function destroy(Request $request, ServiceRecord $serviceRecord): JsonResponse
    {
        $user = $request->user();
        $blocked = $this->assertCanMutate($user);
        if ($blocked) {
            return $blocked;
        }

        if ((int) $serviceRecord->branch_id !== (int) $user->branch_id) {
            return response()->json([
                'message' => 'Anda hanya dapat menghapus catatan servis cabang sendiri.',
            ], 403);
        }

        $serviceDate = $serviceRecord->service_date?->toDateString()
            ?? Carbon::parse($serviceRecord->service_date)->toDateString();
        $this->payrollLockChecker->assertEmployeeDateOpen(
            (int) $serviceRecord->employee_id,
            $serviceDate,
        );

        $serviceRecord->delete();

        return response()->json([
            'message' => 'Catatan servis berhasil dihapus.',
        ]);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, bool $partial = false): array
    {
        $rules = [
            'employee_id' => [$partial ? 'sometimes' : 'required', 'integer', 'exists:employees,id'],
            'service_date' => [$partial ? 'sometimes' : 'required', 'date'],
            'brand' => [$partial ? 'sometimes' : 'required', 'string', 'max:100'],
            'device_type' => [$partial ? 'sometimes' : 'required', 'string', 'max:100'],
            'damage' => [$partial ? 'sometimes' : 'required', 'string', 'max:150'],
            'cost' => [$partial ? 'sometimes' : 'required', 'numeric', 'gte:0'],
            'price' => [$partial ? 'sometimes' : 'required', 'numeric', 'gte:0'],
            'notes' => ['nullable', 'string'],
        ];

        return $request->validate($rules);
    }

    protected function assertCanMutate($user): ?JsonResponse
    {
        if ($user->isOwner()) {
            return response()->json([
                'message' => 'Owner hanya dapat memantau catatan servis, tidak dapat menginput.',
            ], 403);
        }

        if (! $user->isAdmin()) {
            return response()->json([
                'message' => 'Akses ditolak.',
            ], 403);
        }

        return null;
    }

    protected function isWorkshopBranch(?int $branchId): bool
    {
        if (! $branchId) {
            return false;
        }

        $branch = Branch::query()->with('branchType')->find($branchId);

        return $branch ? $branch->isWorkshop() : false;
    }

    /**
     * @return list<int>
     */
    protected function konterBranchIds(): array
    {
        return Branch::query()
            ->with('branchType')
            ->get()
            ->filter(fn (Branch $b) => ! $b->isWorkshop())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
