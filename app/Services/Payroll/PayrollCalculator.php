<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeDailyClosing;
use App\Models\Payroll;
use App\Models\ServiceRecord;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PayrollCalculator
{
    /**
     * @param  Collection<int, Employee>  $employees
     * @return array<int, array<string, mixed>>
     */
    public function computeAutoBatch(Collection $employees, int $year, int $month): array
    {
        if ($employees->isEmpty()) {
            return [];
        }

        [$from, $to] = $this->periodRange($year, $month);
        $ids = $employees->pluck('id')->all();

        $presentDays = EmployeeAttendance::query()
            ->selectRaw('employee_id, COUNT(*) as cnt')
            ->whereIn('employee_id', $ids)
            ->where('status', EmployeeAttendance::STATUS_PRESENT)
            ->whereBetween('attendance_date', [$from, $to])
            ->groupBy('employee_id')
            ->pluck('cnt', 'employee_id');

        $closingQty = EmployeeDailyClosing::query()
            ->selectRaw('employee_id, COALESCE(SUM(qty), 0) as total_qty')
            ->whereIn('employee_id', $ids)
            ->whereBetween('closing_date', [$from, $to])
            ->groupBy('employee_id')
            ->pluck('total_qty', 'employee_id');

        $serviceProfit = ServiceRecord::query()
            ->selectRaw('employee_id, COALESCE(SUM(profit), 0) as total_profit')
            ->whereIn('employee_id', $ids)
            ->whereBetween('service_date', [$from, $to])
            ->groupBy('employee_id')
            ->pluck('total_profit', 'employee_id');

        $result = [];
        foreach ($employees as $employee) {
            $isPromotor = $employee->isPromotor();
            $isTechnician = $employee->isTechnician();
            $days = (int) ($presentDays[$employee->id] ?? 0);
            $qty = (int) ($closingQty[$employee->id] ?? 0);
            $profit = Money::of($serviceProfit[$employee->id] ?? 0);
            $gapok = $isPromotor
                ? '0.00'
                : Money::mul($days, Payroll::GAPOK_RATE);
            $insentifHp = Money::mul($qty, Payroll::HP_RATE);
            $serviceIncentive = $isTechnician
                ? Money::percentOf($profit, 50)
                : '0.00';

            $result[$employee->id] = [
                'is_promotor' => $isPromotor,
                'is_technician' => $isTechnician,
                'present_days' => $days,
                'gapok' => $gapok,
                'closing_qty' => $qty,
                'insentif_hp' => $insentifHp,
                'service_profit' => $profit,
                'service_incentive' => $serviceIncentive,
            ];
        }

        return $result;
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function periodRange(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1);
        $from = $start->toDateString();
        $to = $start->copy()->endOfMonth()->toDateString();

        return [$from, $to];
    }

    /**
     * @return array<string, mixed>
     */
    public function emptyAuto(Employee $employee): array
    {
        return [
            'is_promotor' => $employee->isPromotor(),
            'is_technician' => $employee->isTechnician(),
            'present_days' => 0,
            'gapok' => '0.00',
            'closing_qty' => 0,
            'insentif_hp' => '0.00',
            'service_profit' => '0.00',
            'service_incentive' => '0.00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rowFromPayroll(Payroll $payroll, Employee $employee): array
    {
        return [
            'payroll_id' => $payroll->id,
            'employee_id' => $employee->id,
            'name' => $employee->name,
            'phone' => $employee->phone,
            'branch_id' => $payroll->branch_id,
            'branch_name' => $employee->branch?->name,
            'position' => $payroll->position_snapshot ?? $employee->position,
            'is_promotor' => (bool) $payroll->is_promotor,
            'is_technician' => (bool) $payroll->is_technician,
            'status' => $payroll->status,
            'present_days' => (int) $payroll->present_days,
            'gapok' => (float) $payroll->gapok,
            'closing_qty' => (int) $payroll->closing_qty,
            'insentif_hp' => (float) $payroll->insentif_hp,
            'service_profit' => (float) $payroll->service_profit,
            'service_incentive' => (float) $payroll->service_incentive,
            'insentif_acc' => (float) $payroll->insentif_acc,
            'bonus_absen' => (float) $payroll->bonus_absen,
            'hutang' => (float) $payroll->hutang,
            'pengeluaran' => (float) $payroll->pengeluaran,
            'total' => (float) $payroll->total,
            'note' => $payroll->note,
            'year' => (int) $payroll->year,
            'month' => (int) $payroll->month,
            'locked_at' => $payroll->locked_at?->toIso8601String(),
        ];
    }
}
