<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PayrollLocker
{
    public function __construct(
        protected PayrollCalculator $calculator,
    ) {}

    /**
     * Kunci slip gaji: hitung ulang auto-components lalu simpan kolom statis.
     *
     * @param  Collection<int, Employee>  $employees  keyed by id
     */
    public function lockPeriod(Collection $employees, int $year, int $month, User $actor): void
    {
        $autoByEmployee = $this->calculator->computeAutoBatch($employees->values(), $year, $month);
        $now = now();

        DB::transaction(function () use ($employees, $autoByEmployee, $year, $month, $actor, $now) {
            foreach ($employees as $employee) {
                $existing = Payroll::query()
                    ->where('employee_id', $employee->id)
                    ->where('year', $year)
                    ->where('month', $month)
                    ->lockForUpdate()
                    ->first();

                if ($existing && $existing->isLocked()) {
                    continue;
                }

                $auto = $autoByEmployee[$employee->id] ?? $this->calculator->emptyAuto($employee);
                $gapok = $existing !== null
                    ? (float) $existing->gapok
                    : (float) $auto['gapok'];
                $insentifAcc = (float) ($existing?->insentif_acc ?? 0);
                $bonusAbsen = (float) ($existing?->bonus_absen ?? 0);
                $hutang = (float) ($existing?->hutang ?? 0);
                $pengeluaran = (float) ($existing?->pengeluaran ?? 0);
                $total = Payroll::computeTotal(
                    $gapok,
                    $auto['insentif_hp'],
                    $auto['service_incentive'],
                    $insentifAcc,
                    $bonusAbsen,
                    $hutang,
                    $pengeluaran,
                );

                Payroll::query()->updateOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'year' => $year,
                        'month' => $month,
                    ],
                    [
                        'branch_id' => $employee->branch_id,
                        'status' => Payroll::STATUS_LOCKED,
                        'position_snapshot' => $employee->position,
                        'is_promotor' => $auto['is_promotor'],
                        'is_technician' => $auto['is_technician'],
                        'present_days' => $auto['present_days'],
                        'gapok' => $gapok,
                        'closing_qty' => $auto['closing_qty'],
                        'insentif_hp' => $auto['insentif_hp'],
                        'service_profit' => $auto['service_profit'],
                        'service_incentive' => $auto['service_incentive'],
                        'insentif_acc' => $insentifAcc,
                        'bonus_absen' => $bonusAbsen,
                        'hutang' => $hutang,
                        'pengeluaran' => $pengeluaran,
                        'total' => $total,
                        'note' => $existing?->note,
                        'calculated_at' => $now,
                        'locked_at' => $now,
                        'locked_by' => $actor->id,
                        'input_by' => $actor->id,
                    ]
                );
            }
        });
    }

    /**
     * Simpan draf slip (manual fields + snapshot auto terkini).
     *
     * @param  Collection<int, Employee>  $employees  keyed by id
     * @param  list<array<string, mixed>>  $items
     */
    public function saveDrafts(Collection $employees, int $year, int $month, array $items, User $actor): void
    {
        $autoByEmployee = $this->calculator->computeAutoBatch($employees->values(), $year, $month);
        $now = now();

        DB::transaction(function () use ($items, $year, $month, $employees, $autoByEmployee, $actor, $now) {
            foreach ($items as $item) {
                $employeeId = (int) $item['employee_id'];
                /** @var Employee $employee */
                $employee = $employees->get($employeeId);
                $auto = $autoByEmployee[$employeeId] ?? $this->calculator->emptyAuto($employee);

                $gapok = array_key_exists('gapok', $item)
                    ? (float) $item['gapok']
                    : (float) $auto['gapok'];
                $insentifAcc = (float) ($item['insentif_acc'] ?? 0);
                $bonusAbsen = (float) ($item['bonus_absen'] ?? 0);
                $hutang = (float) ($item['hutang'] ?? 0);
                $pengeluaran = (float) ($item['pengeluaran'] ?? 0);
                $total = Payroll::computeTotal(
                    $gapok,
                    $auto['insentif_hp'],
                    $auto['service_incentive'],
                    $insentifAcc,
                    $bonusAbsen,
                    $hutang,
                    $pengeluaran,
                );

                Payroll::query()->updateOrCreate(
                    [
                        'employee_id' => $employeeId,
                        'year' => $year,
                        'month' => $month,
                    ],
                    [
                        'branch_id' => $employee->branch_id,
                        'status' => Payroll::STATUS_DRAFT,
                        'position_snapshot' => $employee->position,
                        'is_promotor' => $auto['is_promotor'],
                        'is_technician' => $auto['is_technician'],
                        'present_days' => $auto['present_days'],
                        'gapok' => $gapok,
                        'closing_qty' => $auto['closing_qty'],
                        'insentif_hp' => $auto['insentif_hp'],
                        'service_profit' => $auto['service_profit'],
                        'service_incentive' => $auto['service_incentive'],
                        'insentif_acc' => $insentifAcc,
                        'bonus_absen' => $bonusAbsen,
                        'hutang' => $hutang,
                        'pengeluaran' => $pengeluaran,
                        'total' => $total,
                        'note' => $item['note'] ?? null,
                        'calculated_at' => $now,
                        'locked_at' => null,
                        'locked_by' => null,
                        'input_by' => $actor->id,
                    ]
                );
            }
        });
    }
}
