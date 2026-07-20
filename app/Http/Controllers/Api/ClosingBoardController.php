<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeDailyClosing;
use App\Models\EmployeeMonthlyTarget;
use App\Services\PayrollLockChecker;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClosingBoardController extends Controller
{
    public function __construct(
        protected PayrollLockChecker $payrollLockChecker,
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
            // Closingan non-promotor: jangan tampilkan jabatan owner/pemilik.
            ->where(function ($q) {
                $q->whereNull('position')
                    ->orWhereRaw('LOWER(TRIM(position)) NOT IN (?, ?)', ['owner', 'pemilik']);
            })
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

            $target = (int) ($targets->get($employee->id)?->target ?? 0);
            $pct = $target > 0 ? round(($total / $target) * 100, 2) : null;

            return [
                'employee_id' => $employee->id,
                'name' => $employee->name,
                'branch_id' => $employee->branch_id,
                'branch_name' => $employee->branch?->name,
                'daily' => $daily,
                'total' => $total,
                'target' => $target,
                'pct' => $pct,
            ];
        })->values();

        // Group by branch for owner/admin view + total harian per cabang
        $byBranch = $rows->groupBy('branch_name')->map(function ($group, $branchName) use ($daysInMonth) {
            $dailyTotals = array_fill(1, $daysInMonth, 0);
            foreach ($group as $row) {
                for ($d = 1; $d <= $daysInMonth; $d++) {
                    $dailyTotals[$d] += (int) ($row['daily'][$d] ?? 0);
                }
            }

            return [
                'branch_name' => $branchName ?: '—',
                'branch_total' => $group->sum('total'),
                'branch_target' => $group->sum('target'),
                'daily_totals' => $dailyTotals,
                'rows' => $group->values(),
            ];
        })->values();

        $grandDaily = array_fill(1, $daysInMonth, 0);
        foreach ($byBranch as $group) {
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $grandDaily[$d] += (int) ($group['daily_totals'][$d] ?? 0);
            }
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
            ],
            'data' => $rows,
            'groups' => $byBranch,
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
        $this->payrollLockChecker->assertEmployeeDateOpen($employee->id, $date);
        $qty = (int) $data['qty'];

        if ($qty === 0) {
            EmployeeDailyClosing::query()
                ->where('employee_id', $employee->id)
                ->whereDate('closing_date', $date)
                ->delete();

            return response()->json([
                'message' => 'Closingan dihapus (qty 0).',
                'data' => null,
            ]);
        }

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

        return response()->json([
            'message' => 'Closingan berhasil disimpan.',
            'data' => $row,
        ]);
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
