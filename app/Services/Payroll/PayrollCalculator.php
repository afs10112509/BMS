<?php

namespace App\Services\Payroll;

use App\Models\Category;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeDailyClosing;
use App\Models\Payroll;
use App\Models\ProfitShare;
use App\Models\ServiceRecord;
use App\Models\Transaction;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PayrollCalculator
{
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

        $kasbonByEmployee = $this->computeKasbonBatch($employees, $year, $month);

        $result = [];
        foreach ($employees as $employee) {
            $isPromotor = $employee->isPromotor();
            $isTechnician = $employee->isTechnician();
            $isPic = $employee->hasPosition(Employee::POS_PIC);
            $days = (int) ($presentDays[$employee->id] ?? 0);
            $qty = (int) ($closingQty[$employee->id] ?? 0);
            $profit = Money::of($serviceProfit[$employee->id] ?? 0);
            // Promotor & teknisi: gapok 0 (tidak dikali kehadiran); teknisi dapat insentif service.
            $gapok = ($isPromotor || $isTechnician)
                ? '0.00'
                : Money::mul($days, Payroll::GAPOK_RATE);
            $insentifHp = Money::mul($qty, Payroll::HP_RATE);
            $serviceIncentive = $isTechnician
                ? Money::percentOf($profit, 50)
                : '0.00';

            $result[$employee->id] = [
                'is_promotor' => $isPromotor,
                'is_technician' => $isTechnician,
                'is_pic' => $isPic,
                'present_days' => $days,
                'gapok' => $gapok,
                'closing_qty' => $qty,
                'insentif_hp' => $insentifHp,
                'service_profit' => $profit,
                'service_incentive' => $serviceIncentive,
                'kasbon' => $kasbonByEmployee[$employee->id] ?? '0.00',
            ];
        }

        return $result;
    }

    /**
     * Usulan insentif PIC dari modul Bagi Hasil (per cabang × bulan).
     * 1 PIC di cabang → dapat penuh pic_amount.
     * Beberapa PIC → cocokkan nama dengan pic_name; jika tidak cocok → 0 (isi manual).
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, float> employee_id => amount
     */
    public function computePicFromProfitShareBatch(Collection $employees, int $year, int $month): array
    {
        $out = [];
        foreach ($employees as $employee) {
            $out[(int) $employee->id] = 0.0;
        }
        if ($employees->isEmpty()) {
            return $out;
        }

        $shares = ProfitShare::query()
            ->where('year', $year)
            ->where('month', $month)
            ->whereIn('branch_id', $employees->pluck('branch_id')->unique()->filter()->all())
            ->get(['branch_id', 'pic_name', 'pic_amount', 'pic_employee_id'])
            ->keyBy('branch_id');

        if ($shares->isEmpty()) {
            return $out;
        }

        $picsByBranch = $employees
            ->filter(fn (Employee $e) => $e->hasPosition(Employee::POS_PIC))
            ->groupBy('branch_id');

        foreach ($employees as $employee) {
            $share = $shares->get($employee->branch_id);
            if (! $share) {
                continue;
            }
            $amount = (float) $share->pic_amount;
            if ($amount <= 0) {
                continue;
            }

            if ($share->pic_employee_id) {
                if ((int) $employee->id === (int) $share->pic_employee_id) {
                    $out[(int) $employee->id] = $amount;
                }
                continue;
            }

            if (! $employee->hasPosition(Employee::POS_PIC)) {
                continue;
            }

            $pics = $picsByBranch->get($employee->branch_id, collect());
            if ($pics->count() <= 1) {
                $out[(int) $employee->id] = $amount;
                continue;
            }

            $picName = mb_strtolower(trim((string) $share->pic_name));
            $empName = mb_strtolower(trim((string) $employee->name));
            if ($picName !== '' && (
                $empName === $picName
                || str_contains($empName, $picName)
                || str_contains($picName, $empName)
            )) {
                $out[(int) $employee->id] = $amount;
            }
        }

        return $out;
    }

    /**
     * Total transaksi kategori Kasbon* per karyawan (semua cabang) dalam bulan.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, string> employee_id => amount
     */
    public function computeKasbonBatch(Collection $employees, int $year, int $month): array
    {
        $out = [];
        foreach ($employees as $employee) {
            $out[(int) $employee->id] = '0.00';
        }
        if ($employees->isEmpty()) {
            return $out;
        }

        [$from, $to] = $this->periodRange($year, $month);

        $linkedIds = $employees
            ->pluck('kasbon_category_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $cats = Category::query()
            ->where(function ($q) use ($linkedIds) {
                $q->where(function ($inner) {
                    $inner->where('type', 'expense')
                        ->where(function ($active) {
                            $active->whereNull('is_active')->orWhere('is_active', true);
                        })
                        ->whereRaw("LOWER(name) LIKE 'kasbon%'");
                });
                if ($linkedIds !== []) {
                    $q->orWhereIn('id', $linkedIds);
                }
            })
            ->get(['id', 'name']);

        if ($cats->isEmpty()) {
            return $out;
        }

        $sums = Transaction::withoutGlobalScopes()
            ->select('category_id', DB::raw('COALESCE(SUM(amount), 0) as total'))
            ->whereIn('category_id', $cats->pluck('id'))
            ->whereDate('transaction_date', '>=', $from)
            ->whereDate('transaction_date', '<=', $to)
            ->groupBy('category_id')
            ->pluck('total', 'category_id');

        /** @var array<string, list<int>> $suffixToCatIds */
        $suffixToCatIds = [];
        foreach ($cats as $cat) {
            $suffix = KasbonCategoryLinker::suffix((string) $cat->name);
            if ($suffix === '') {
                continue;
            }
            $suffixToCatIds[$suffix] ??= [];
            $suffixToCatIds[$suffix][] = (int) $cat->id;
        }

        foreach ($employees as $employee) {
            $linkedId = (int) ($employee->kasbon_category_id ?? 0);
            if ($linkedId > 0) {
                $out[(int) $employee->id] = Money::of($sums[$linkedId] ?? 0);
                continue;
            }

            $keys = KasbonCategoryLinker::employeeKeys((string) $employee->name);
            $total = 0.0;
            $usedCatIds = [];
            foreach ($keys as $key) {
                foreach ($suffixToCatIds[$key] ?? [] as $catId) {
                    if (isset($usedCatIds[$catId])) {
                        continue;
                    }
                    $usedCatIds[$catId] = true;
                    $total += (float) ($sums[$catId] ?? 0);
                }
            }
            $out[(int) $employee->id] = Money::of($total);
        }

        return $out;
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
            'is_pic' => $employee->hasPosition(Employee::POS_PIC),
            'present_days' => 0,
            'gapok' => '0.00',
            'closing_qty' => 0,
            'insentif_hp' => '0.00',
            'service_profit' => '0.00',
            'service_incentive' => '0.00',
            'kasbon' => '0.00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rowFromPayroll(Payroll $payroll, Employee $employee): array
    {
        $isPic = (bool) ($payroll->is_pic ?? $employee->hasPosition(Employee::POS_PIC));

        return [
            'payroll_id' => $payroll->id,
            'employee_id' => $employee->id,
            'name' => $employee->name,
            ...self::employeeSlipFields($employee),
            'branch_id' => $payroll->branch_id,
            'branch_name' => $employee->branch?->name,
            'position' => $payroll->position_snapshot ?? $employee->position,
            'is_promotor' => (bool) $payroll->is_promotor,
            'is_technician' => (bool) $payroll->is_technician,
            'is_pic' => $isPic,
            'status' => $payroll->status,
            'present_days' => (int) $payroll->present_days,
            'gapok' => (float) $payroll->gapok,
            'insentif_pic' => $isPic ? (float) $payroll->insentif_pic : 0.0,
            'insentif_pic_auto' => 0.0,
            'closing_qty' => (int) $payroll->closing_qty,
            'insentif_hp' => (float) $payroll->insentif_hp,
            'service_profit' => (float) $payroll->service_profit,
            'service_incentive' => (float) $payroll->service_incentive,
            'insentif_acc' => (float) $payroll->insentif_acc,
            'bonus_absen' => (float) $payroll->bonus_absen,
            'hutang' => (float) $payroll->hutang,
            'pengeluaran' => (float) $payroll->pengeluaran,
            'kasbon' => (float) $payroll->pengeluaran,
            'kasbon_auto' => 0.0,
            'total' => (float) $payroll->total,
            'note' => $payroll->note,
            'year' => (int) $payroll->year,
            'month' => (int) $payroll->month,
            'locked_at' => $payroll->locked_at?->toIso8601String(),
            'is_paid' => $payroll->isPaid(),
            'paid_at' => $payroll->paid_at?->toIso8601String(),
        ];
    }

    /**
     * Field kontak & rekening untuk slip WA / detail gaji.
     *
     * @return array{phone: ?string, bank_name: ?string, bank_account_name: ?string, bank_account_number: ?string}
     */
    public static function employeeSlipFields(Employee $employee): array
    {
        return [
            'phone' => $employee->phone,
            'bank_name' => $employee->bank_name,
            'bank_account_name' => $employee->bank_account_name,
            'bank_account_number' => $employee->bank_account_number,
        ];
    }
}
