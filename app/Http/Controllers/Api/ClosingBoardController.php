<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ClosingPeriodLock;
use App\Models\Employee;
use App\Models\EmployeeDailyClosing;
use App\Models\EmployeeMonthlyTarget;
use App\Services\AuditLogger;
use App\Services\PayrollLockChecker;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClosingBoardController extends Controller
{
    public function __construct(
        protected PayrollLockChecker $payrollLockChecker,
        protected AuditLogger $auditLogger,
    ) {}

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

        $branchId = null;
        if ($user->isAdmin()) {
            if (! $user->branch_id) {
                return response()->json(['message' => 'Admin tidak memiliki cabang.'], 422);
            }
            $branch = Branch::query()->find($user->branch_id);
            if ($branch?->isWorkshop()) {
                return response()->json(['message' => 'Modul closingan hanya untuk cabang konter.'], 403);
            }
            $branchId = (int) $user->branch_id;
        } elseif ($user->isOwner()) {
            $branchId = ! empty($data['branch_id']) ? (int) $data['branch_id'] : null;
        } else {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $employeesQuery = Employee::query()
            ->with('branch:id,name,type')
            ->where('status', 'active')
            ->whereHas('branch', function ($q) {
                $q->where('type', Branch::TYPE_KONTER);
            })
            // PIC boleh ikut closingan; hanya Owner yang disembunyikan.
            ->withoutOwner()
            ->orderBy('name');

        if ($branchId) {
            $employeesQuery->where('branch_id', $branchId);
        }

        $employees = $employeesQuery->get();
        $employeeIds = $employees->pluck('id')->all();

        $targets = EmployeeMonthlyTarget::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('year', $year)
            ->where('month', $month)
            ->get()
            ->keyBy('employee_id');

        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

        $closings = EmployeeDailyClosing::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('closing_date', [$from, $to])
            ->get()
            ->groupBy('employee_id');

        $rows = $employees->map(function (Employee $employee) use ($targets, $closings, $daysInMonth) {
            $daily = array_fill(1, $daysInMonth, 0);
            $total = 0;

            foreach ($closings->get($employee->id, collect()) as $closing) {
                $day = (int) $closing->closing_date->format('j');
                if ($day >= 1 && $day <= $daysInMonth) {
                    $daily[$day] = (int) $closing->qty;
                    $total += (int) $closing->qty;
                }
            }

            $saved = $targets->get($employee->id);
            $isDefaultTarget = $saved === null;
            // Default target = jumlah hari bulan; tetap bisa diubah & disimpan.
            $target = $isDefaultTarget ? $daysInMonth : (int) $saved->target;
            $pct = $target > 0 ? round(($total / $target) * 100, 2) : null;

            return [
                'employee_id' => $employee->id,
                'name' => $employee->name,
                'branch_id' => $employee->branch_id,
                'branch_name' => $employee->branch?->name,
                'daily' => $daily,
                'total' => $total,
                'target' => $target,
                'target_is_default' => $isDefaultTarget,
                'pct' => $pct,
            ];
        })->values();

        $branchIds = $rows->pluck('branch_id')->filter()->unique()->values()->all();
        $locks = ClosingPeriodLock::query()
            ->whereIn('branch_id', $branchIds ?: [0])
            ->where('year', $year)
            ->where('month', $month)
            ->where('is_locked', true)
            ->get()
            ->keyBy('branch_id');

        // Group by branch for owner/admin view + total harian per cabang
        $byBranch = $rows->groupBy('branch_name')->map(function ($group, $branchName) use ($daysInMonth, $locks) {
            $dailyTotals = array_fill(1, $daysInMonth, 0);
            foreach ($group as $row) {
                for ($d = 1; $d <= $daysInMonth; $d++) {
                    $dailyTotals[$d] += (int) ($row['daily'][$d] ?? 0);
                }
            }

            $gid = (int) ($group->first()['branch_id'] ?? 0);
            $lock = $locks->get($gid);

            return [
                'branch_id' => $gid ?: null,
                'branch_name' => $branchName ?: '—',
                'branch_total' => $group->sum('total'),
                'branch_target' => $group->sum('target'),
                'daily_totals' => $dailyTotals,
                'is_locked' => (bool) $lock,
                'locked_at' => $lock?->locked_at?->timezone(config('app.timezone'))->format('d/m/Y H:i'),
                'rows' => $group->values(),
            ];
        })->values();

        $grandDaily = array_fill(1, $daysInMonth, 0);
        foreach ($byBranch as $group) {
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $grandDaily[$d] += (int) ($group['daily_totals'][$d] ?? 0);
            }
        }

        $isLocked = false;
        $lockedAt = null;
        if ($branchId) {
            $lock = $locks->get($branchId);
            $isLocked = (bool) $lock;
            $lockedAt = $lock?->locked_at?->timezone(config('app.timezone'))->format('d/m/Y H:i');
        } elseif ($user->isAdmin() && $user->branch_id) {
            $lock = $locks->get((int) $user->branch_id);
            $isLocked = (bool) $lock;
            $lockedAt = $lock?->locked_at?->timezone(config('app.timezone'))->format('d/m/Y H:i');
        }

        return response()->json([
            'message' => 'Papan closingan berhasil diambil.',
            'meta' => [
                'year' => $year,
                'month' => $month,
                'days_in_month' => $daysInMonth,
                'branch_id' => $branchId,
                'grand_total' => $rows->sum('total'),
                'grand_target' => $rows->sum('target'),
                'daily_totals' => $grandDaily,
                'is_locked' => $isLocked,
                'locked_at' => $lockedAt,
            ],
            'data' => $rows,
            'groups' => $byBranch,
        ]);
    }

    public function lock(Request $request): JsonResponse
    {
        return $this->setLock($request, locked: true);
    }

    public function unlock(Request $request): JsonResponse
    {
        return $this->setLock($request, locked: false);
    }

    protected function setLock(Request $request, bool $locked): JsonResponse
    {
        $user = $request->user();
        if (! $user->isOwner()) {
            return response()->json([
                'message' => 'Hanya Owner yang dapat mengunci/membuka target closingan.',
            ], 403);
        }

        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $branch = Branch::query()->with('branchType')->findOrFail((int) $data['branch_id']);
        if ($branch->isWorkshop()) {
            return response()->json([
                'message' => 'Modul closingan hanya untuk cabang konter.',
            ], 422);
        }

        $year = (int) $data['year'];
        $month = (int) $data['month'];

        $row = ClosingPeriodLock::query()->updateOrCreate(
            [
                'branch_id' => (int) $branch->id,
                'year' => $year,
                'month' => $month,
            ],
            [
                'is_locked' => $locked,
                'locked_by' => $locked ? $user->id : null,
                'locked_at' => $locked ? now() : null,
            ]
        );

        $this->auditLogger->log(
            $user,
            'UPDATE',
            $row,
            null,
            [
                'branch_id' => (int) $branch->id,
                'year' => $year,
                'month' => $month,
                'is_locked' => $locked,
            ],
            (int) $branch->id,
        );

        return response()->json([
            'message' => $locked
                ? 'Target closingan dikunci. Admin cabang tidak dapat mengubah data.'
                : 'Kunci target closingan dibuka.',
            'data' => [
                'branch_id' => (int) $branch->id,
                'branch_name' => $branch->name,
                'year' => $year,
                'month' => $month,
                'is_locked' => (bool) $row->is_locked,
                'locked_at' => $row->locked_at?->timezone(config('app.timezone'))->format('d/m/Y H:i'),
            ],
        ]);
    }

    public function upsertTarget(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'target' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);

        $employee = Employee::query()->with('branch')->findOrFail($data['employee_id']);
        if ($denied = $this->authorizeEmployee($user, $employee)) {
            return $denied;
        }

        if ($denied = $this->denyIfClosingLocked($user, (int) $employee->branch_id, (int) $data['year'], (int) $data['month'])) {
            return $denied;
        }

        $this->payrollLockChecker->assertEmployeePeriodOpen(
            $employee->id,
            (int) $data['year'],
            (int) $data['month'],
        );

        $row = EmployeeMonthlyTarget::query()->updateOrCreate(
            [
                'employee_id' => $employee->id,
                'year' => (int) $data['year'],
                'month' => (int) $data['month'],
            ],
            [
                'target' => (int) $data['target'],
                'set_by' => $user->id,
            ]
        );

        $this->auditLogger->log(
            $user,
            $row->wasRecentlyCreated ? 'CREATE' : 'UPDATE',
            $row,
            null,
            [
                'employee_id' => $employee->id,
                'employee_name' => $employee->name,
                'branch_id' => $employee->branch_id,
                'year' => (int) $data['year'],
                'month' => (int) $data['month'],
                'target' => (int) $data['target'],
            ],
            (int) $employee->branch_id,
        );

        return response()->json([
            'message' => 'Target berhasil disimpan.',
            'data' => $row,
        ]);
    }

    public function upsertDaily(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'closing_date' => ['required', 'date'],
            'qty' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        $employee = Employee::query()->with('branch')->findOrFail($data['employee_id']);
        if ($denied = $this->authorizeEmployee($user, $employee)) {
            return $denied;
        }

        $date = Carbon::parse($data['closing_date'])->toDateString();
        $carbon = Carbon::parse($date);
        if ($denied = $this->denyIfClosingLocked($user, (int) $employee->branch_id, (int) $carbon->year, (int) $carbon->month)) {
            return $denied;
        }

        $this->payrollLockChecker->assertEmployeeDateOpen($employee->id, $date);
        $qty = (int) $data['qty'];

        if ($qty === 0) {
            $existing = EmployeeDailyClosing::query()
                ->where('employee_id', $employee->id)
                ->whereDate('closing_date', $date)
                ->first();
            if ($existing) {
                $old = $existing->toArray();
                $old['employee_name'] = $employee->name;
                $existing->delete();
                $this->auditLogger->logTable(
                    $user,
                    'DELETE',
                    'employee_daily_closings',
                    (int) ($old['id'] ?? 0),
                    $old,
                    null,
                    (int) $employee->branch_id,
                );
            }

            return response()->json([
                'message' => 'Closingan dihapus (qty 0).',
                'data' => null,
            ]);
        }

        $existing = EmployeeDailyClosing::query()
            ->where('employee_id', $employee->id)
            ->whereDate('closing_date', $date)
            ->first();

        $row = EmployeeDailyClosing::query()->updateOrCreate(
            [
                'employee_id' => $employee->id,
                'closing_date' => $date,
            ],
            [
                'qty' => $qty,
                'input_by' => $user->id,
            ]
        );

        $this->auditLogger->log(
            $user,
            $existing ? 'UPDATE' : 'CREATE',
            $row,
            $existing?->toArray(),
            [
                'employee_id' => $employee->id,
                'employee_name' => $employee->name,
                'branch_id' => $employee->branch_id,
                'closing_date' => $date,
                'qty' => $qty,
            ],
            (int) $employee->branch_id,
        );

        return response()->json([
            'message' => 'Closingan berhasil disimpan.',
            'data' => $row,
        ]);
    }

    protected function denyIfClosingLocked($user, int $branchId, int $year, int $month): ?JsonResponse
    {
        // Owner tetap boleh mengubah meski terkunci.
        if ($user->isOwner()) {
            return null;
        }

        if (! $branchId) {
            return null;
        }

        if (ClosingPeriodLock::isBranchPeriodLocked($branchId, $year, $month)) {
            return response()->json([
                'message' => 'Aksi ditolak: Target closingan periode ini telah dikunci oleh Owner.',
            ], 403);
        }

        return null;
    }

    private function authorizeEmployee($user, Employee $employee): ?JsonResponse
    {
        if ($user->isOwner()) {
            if ($employee->branch?->isWorkshop()) {
                return response()->json(['message' => 'Modul closingan hanya untuk cabang konter.'], 422);
            }

            return null;
        }

        if ($user->isAdmin()) {
            if ((int) $employee->branch_id !== (int) $user->branch_id) {
                return response()->json(['message' => 'Anda tidak boleh mengubah karyawan cabang lain.'], 403);
            }
            if ($employee->branch?->isWorkshop()) {
                return response()->json(['message' => 'Modul closingan hanya untuk cabang konter.'], 403);
            }

            return null;
        }

        return response()->json(['message' => 'Akses ditolak.'], 403);
    }
}
