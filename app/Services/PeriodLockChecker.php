<?php

namespace App\Services;

use App\Models\PeriodLock;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PeriodLockChecker
{
    public function assertPeriodOpen(int $branchId, string $date): void
    {
        $period = substr($date, 0, 7); // YYYY-MM

        $locked = PeriodLock::query()
            ->where('branch_id', $branchId)
            ->where('period', $period)
            ->where('is_locked', true)
            ->exists();

        if ($locked) {
            throw new HttpException(
                403,
                'Aksi Ditolak: Periode pembukuan telah dikunci oleh Owner.'
            );
        }
    }
}
