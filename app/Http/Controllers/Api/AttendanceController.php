<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Services\AuditLogger;
use App\Services\PayrollLockChecker;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AttendanceController extends Controller
{
    public function __construct(
        protected PayrollLockChecker $payrollLockChecker,
        protected AuditLogger $auditLogger,
    ) {}

    public function daily(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'date' => ['required', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $date = Carbon::parse($data['date'])->toDateString();
        $branchId = $this->resolveBranchId($user, $data['branch_id'] ?? null, requireBranch: false);
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        $employees = $this->eligibleEmployeesQuery($branchId)->get();
        $byEmployee = EmployeeAttendance::query()
            ->whereIn('employee_id', $employees->pluck('id'))
            ->whereDate('attendance_date', $date)
            ->get()
            ->keyBy('employee_id');

        $rows = $employees->map(function (Employee $employee) use ($byEmployee) {
            $att = $byEmployee->get($employee->id);

            return [
                'employee_id' => $employee->id,
                'name' => $employee->name,
                'branch_id' => $employee->branch_id,
                'branch_name' => $employee->branch?->name,
                'status' => $att?->status,
                'note' => $att?->note,
            ];
        })->values();

        return response()->json([
            'message' => 'Absensi harian berhasil diambil.',
            'meta' => [
                'date' => $date,
                'branch_id' => $branchId,
            ],
            'data' => $rows,
        ]);
    }

    public function upsertDaily(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'date' => ['required', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.employee_id' => ['required', 'integer', 'exists:employees,id'],
            'items.*.status' => ['required', Rule::in(EmployeeAttendance::STATUSES)],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        $date = Carbon::parse($data['date'])->toDateString();
        $branchId = $this->resolveBranchId($user, $data['branch_id'] ?? null, requireBranch: false);
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        $allowedIds = $this->eligibleEmployeesQuery($branchId)->pluck('id')->all();
        $allowedSet = array_fill_keys($allowedIds, true);

        foreach ($data['items'] as $item) {
            if (! isset($allowedSet[(int) $item['employee_id']])) {
                return response()->json([
                    'message' => 'Ada karyawan yang tidak boleh diabsen untuk cabang/tanggal ini.',
                ], 422);
            }
        }

        $this->payrollLockChecker->assertEmployeesDateOpen(
            collect($data['items'])->pluck('employee_id')->all(),
            $date,
        );

        DB::transaction(function () use ($data, $date, $user) {
            foreach ($data['items'] as $item) {
                EmployeeAttendance::query()->updateOrCreate(
                    [
                        'employee_id' => (int) $item['employee_id'],
                        'attendance_date' => $date,
                    ],
                    [
                        'status' => $item['status'],
                        'note' => $item['note'] ?? null,
                        'input_by' => $user->id,
                    ]
                );
            }
        });

        $counts = [
            'present' => 0,
            'leave' => 0,
            'sick' => 0,
            'absent' => 0,
        ];
        foreach ($data['items'] as $item) {
            $counts[$item['status']]++;
        }

        $this->auditLogger->logTable(
            $user,
            'UPDATE',
            'employee_attendances',
            (int) ($branchId ?: 0),
            null,
            [
                'batch' => true,
                'branch_id' => $branchId,
                'date' => $date,
                'employee_count' => count($data['items']),
                'counts' => $counts,
            ],
            $branchId ? (int) $branchId : null,
        );

        return response()->json([
            'message' => 'Absensi harian berhasil disimpan.',
            'meta' => [
                'date' => $date,
                'branch_id' => $branchId,
                'counts' => $counts,
            ],
        ]);
    }

    public function upsertCell(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isOwner()) {
            return response()->json([
                'message' => 'Hanya Owner yang dapat mengubah absensi dari board bulanan.',
            ], 403);
        }

        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'date' => ['required', 'date'],
            'status' => ['nullable', Rule::in(EmployeeAttendance::STATUSES)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $date = Carbon::parse($data['date'])->toDateString();
        $employeeId = (int) $data['employee_id'];

        $allowed = $this->eligibleEmployeesQuery(null)->where('employees.id', $employeeId)->exists();
        if (! $allowed) {
            return response()->json([
                'message' => 'Karyawan tidak valid untuk absensi.',
            ], 422);
        }

        $this->payrollLockChecker->assertEmployeeDateOpen($employeeId, $date);

        $employee = Employee::query()->find($employeeId);
        $branchId = $employee?->branch_id ? (int) $employee->branch_id : null;
        $empName = $employee?->name ?? ('#'.$employeeId);

        $status = $data['status'] ?? null;
        if ($status === null || $status === '') {
            $existing = EmployeeAttendance::query()
                ->where('employee_id', $employeeId)
                ->whereDate('attendance_date', $date)
                ->first();
            if ($existing) {
                $old = $existing->toArray();
                $old['employee_name'] = $empName;
                $existing->delete();
                $this->auditLogger->logTable(
                    $user,
                    'DELETE',
                    'employee_attendances',
                    (int) ($old['id'] ?? 0),
                    $old,
                    null,
                    $branchId,
                );
            }

            return response()->json([
                'message' => 'Absensi tanggal tersebut dihapus.',
                'data' => [
                    'employee_id' => $employeeId,
                    'date' => $date,
                    'status' => null,
                ],
            ]);
        }

        $existing = EmployeeAttendance::query()
            ->where('employee_id', $employeeId)
            ->whereDate('attendance_date', $date)
            ->first();
        $old = $existing?->toArray();
        if ($old) {
            $old['employee_name'] = $empName;
        }

        $att = EmployeeAttendance::query()->updateOrCreate(
            [
                'employee_id' => $employeeId,
                'attendance_date' => $date,
            ],
            [
                'status' => $status,
                'note' => $data['note'] ?? null,
                'input_by' => $user->id,
            ]
        );

        $new = $att->toArray();
        $new['employee_name'] = $empName;
        $new['branch_id'] = $branchId;
        $this->auditLogger->log(
            $user,
            $existing ? 'UPDATE' : 'CREATE',
            $att,
            $old,
            $new,
            $branchId,
        );

        return response()->json([
            'message' => 'Absensi berhasil diperbarui.',
            'data' => [
                'employee_id' => $employeeId,
                'date' => $date,
                'status' => $att->status,
            ],
        ]);
    }

    public function board(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $year = (int) $data['year'];
        $month = (int) $data['month'];
        $daysInMonth = Carbon::create($year, $month, 1)->daysInMonth;

        $branchId = $this->resolveBranchId($user, $data['branch_id'] ?? null, requireBranch: false);
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        $employees = $this->eligibleEmployeesQuery($branchId)->get();
        $employeeIds = $employees->pluck('id')->all();

        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

        $attendances = EmployeeAttendance::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('attendance_date', [$from, $to])
            ->get()
            ->groupBy('employee_id');

        $rows = $employees->map(function (Employee $employee) use ($attendances, $daysInMonth) {
            $daily = array_fill(1, $daysInMonth, null);
            $counts = ['present' => 0, 'leave' => 0, 'sick' => 0, 'absent' => 0];

            foreach ($attendances->get($employee->id, collect()) as $att) {
                $day = (int) $att->attendance_date->format('j');
                if ($day >= 1 && $day <= $daysInMonth) {
                    $daily[$day] = $att->status;
                    if (isset($counts[$att->status])) {
                        $counts[$att->status]++;
                    }
                }
            }

            return [
                'employee_id' => $employee->id,
                'name' => $employee->name,
                'branch_id' => $employee->branch_id,
                'branch_name' => $employee->branch?->name,
                'daily' => $daily,
                'counts' => $counts,
            ];
        })->values();

        $byBranch = $rows->groupBy('branch_name')->map(function ($group, $branchName) {
            return [
                'branch_name' => $branchName ?: '—',
                'counts' => [
                    'present' => $group->sum(fn ($r) => $r['counts']['present']),
                    'leave' => $group->sum(fn ($r) => $r['counts']['leave']),
                    'sick' => $group->sum(fn ($r) => $r['counts']['sick']),
                    'absent' => $group->sum(fn ($r) => $r['counts']['absent']),
                ],
                'rows' => $group->values(),
            ];
        })->values();

        return response()->json([
            'message' => 'Rekap absensi berhasil diambil.',
            'meta' => [
                'year' => $year,
                'month' => $month,
                'days_in_month' => $daysInMonth,
                'branch_id' => $branchId,
                'counts' => [
                    'present' => $rows->sum(fn ($r) => $r['counts']['present']),
                    'leave' => $rows->sum(fn ($r) => $r['counts']['leave']),
                    'sick' => $rows->sum(fn ($r) => $r['counts']['sick']),
                    'absent' => $rows->sum(fn ($r) => $r['counts']['absent']),
                ],
            ],
            'data' => $rows,
            'groups' => $byBranch,
        ]);
    }

    /**
     * @return int|JsonResponse|null
     */
    private function resolveBranchId($user, ?int $requestedBranchId, bool $requireBranch)
    {
        return app(\App\Services\BranchContext::class)
            ->resolve($user, $requestedBranchId, $requireBranch);
    }

    private function eligibleEmployeesQuery(?int $branchId)
    {
        $query = Employee::query()
            ->with('branch:id,name,type')
            ->join('branches', 'branches.id', '=', 'employees.branch_id')
            ->where('employees.status', 'active')
            // PIC boleh diabsen; hanya Owner yang disembunyikan.
            ->withoutOwner()
            ->orderBy('branches.name')
            ->orderBy('employees.name')
            ->select('employees.*');

        if ($branchId) {
            $query->where('employees.branch_id', $branchId);
        }

        return $query;
    }
}
