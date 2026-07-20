<?php

namespace App\Services;

use App\Models\Payroll;
use Carbon\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Melarang mutasi absensi / closingan / catatan servis
 * jika slip gaji karyawan untuk periode tersebut sudah locked.
 */
class PayrollLockChecker
{
    public function assertEmployeePeriodOpen(int $employeeId, int $year, int $month): void
    {
        $locked = Payroll::query()
            ->where('employee_id', $employeeId)
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', Payroll::STATUS_LOCKED)
            ->exists();

        if ($locked) {
            throw new HttpException(
                403,
                'Aksi ditolak: slip gaji karyawan untuk periode ini sudah dikunci. Buka kunci gaji dulu untuk mengubah data.'
            );
        }
    }

    public function assertEmployeeDateOpen(int $employeeId, string $date): void
    {
        $carbon = Carbon::parse($date);
        $this->assertEmployeePeriodOpen($employeeId, (int) $carbon->year, (int) $carbon->month);
    }

    /**
     * @param  list<int>  $employeeIds
     */
    public function assertEmployeesDateOpen(array $employeeIds, string $date): void
    {
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        if ($employeeIds === []) {
            return;
        }

        $carbon = Carbon::parse($date);
        $year = (int) $carbon->year;
        $month = (int) $carbon->month;

        $lockedIds = Payroll::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', Payroll::STATUS_LOCKED)
            ->pluck('employee_id')
            ->all();

        if ($lockedIds !== []) {
            throw new HttpException(
                403,
                'Aksi ditolak: ada karyawan yang slip gajinya sudah dikunci untuk periode ini. Buka kunci gaji dulu untuk mengubah data.'
            );
        }
    }
}
