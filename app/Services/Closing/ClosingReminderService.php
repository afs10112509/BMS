<?php

namespace App\Services\Closing;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeDailyClosing;
use App\Models\EmployeeMonthlyTarget;
use App\Support\WhatsAppPhone;
use Carbon\Carbon;

class ClosingReminderService
{
    /**
     * Siapkan reminder closing untuk semua karyawan konter aktif (kecuali Owner).
     * Tanggal default pemakaian: kemarin (D-1).
     *
     * @return array{
     *     date: string,
     *     date_label: string,
     *     period_label: string,
     *     is_yesterday: bool,
     *     recipients: list<array<string, mixed>>,
     *     skipped: list<array<string, mixed>>
     * }
     */
    public function build(Carbon $forDate): array
    {
        $forDate = $forDate->copy()->timezone(config('app.timezone'))->startOfDay();
        $year = (int) $forDate->year;
        $month = (int) $forDate->month;
        $daysInMonth = (int) $forDate->daysInMonth;
        $from = $forDate->copy()->startOfMonth()->toDateString();
        $to = $forDate->copy()->endOfMonth()->toDateString();
        $date = $forDate->toDateString();

        $isYesterday = $forDate->isSameDay(now()->timezone(config('app.timezone'))->subDay()->startOfDay());
        $dateLabel = $forDate->locale('id')->translatedFormat('l, j F Y');
        $periodLabel = $forDate->locale('id')->translatedFormat('F Y');

        $employees = Employee::query()
            ->with('branch:id,name,type')
            ->where('status', 'active')
            ->whereHas('branch', function ($q) {
                $q->where('type', Branch::TYPE_KONTER);
            })
            ->withoutOwner()
            ->orderBy('name')
            ->get();

        $employeeIds = $employees->pluck('id')->all();

        $monthClosings = EmployeeDailyClosing::query()
            ->whereIn('employee_id', $employeeIds ?: [0])
            ->whereBetween('closing_date', [$from, $to])
            ->get()
            ->groupBy('employee_id');

        $targets = EmployeeMonthlyTarget::query()
            ->whereIn('employee_id', $employeeIds ?: [0])
            ->where('year', $year)
            ->where('month', $month)
            ->get()
            ->keyBy('employee_id');

        $recipients = [];
        $skipped = [];

        foreach ($employees as $employee) {
            $dayRows = $monthClosings->get($employee->id, collect());
            $yesterdayRow = $dayRows->first(function ($row) use ($date) {
                return $row->closing_date->toDateString() === $date;
            });
            $yesterdayQty = $yesterdayRow ? (int) $yesterdayRow->qty : null;
            $missing = $yesterdayRow === null;
            $monthQty = (int) $dayRows->sum('qty');

            $saved = $targets->get($employee->id);
            $target = $saved === null ? $daysInMonth : (int) $saved->target;
            $pct = $target > 0 ? round(($monthQty / $target) * 100, 1) : null;
            $tercapai = $target > 0 ? $monthQty >= $target : $monthQty > 0;

            $phone = WhatsAppPhone::normalize($employee->phone);
            $payload = [
                'employee_id' => $employee->id,
                'name' => $employee->name,
                'branch_id' => $employee->branch_id,
                'branch_name' => $employee->branch?->name,
                'phone' => $phone,
                'yesterday_qty' => $yesterdayQty,
                'missing' => $missing,
                'month_qty' => $monthQty,
                'target' => $target,
                'pct' => $pct,
                'tercapai' => $tercapai,
            ];
            $payload['message'] = $this->composeMessage($payload, $dateLabel, $periodLabel, $isYesterday);

            if ($phone === null) {
                $skipped[] = $payload + ['reason' => 'no_phone'];
                continue;
            }

            $recipients[] = $payload;
        }

        return [
            'date' => $date,
            'date_label' => $dateLabel,
            'period_label' => $periodLabel,
            'is_yesterday' => $isYesterday,
            'recipients' => $recipients,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function composeMessage(array $row, string $dateLabel, string $periodLabel, bool $isYesterday): string
    {
        $when = $isYesterday ? "kemarin ({$dateLabel})" : $dateLabel;
        $qtyLabel = ! empty($row['missing']) ? 'belum diinput' : (string) ($row['yesterday_qty'] ?? 0);
        $pct = $row['pct'] != null ? $row['pct'].'%' : '—';
        $status = ! empty($row['tercapai']) ? 'Tercapai' : 'Belum tercapai';
        $closingWord = $isYesterday ? 'kemarin' : 'tanggal itu';

        $lines = [
            '*Reminder Closing — BMS*',
            'Halo '.($row['name'] ?? '—'),
            'Cabang: '.($row['branch_name'] ?? '—'),
            '',
            "Closing {$when}: {$qtyLabel}",
            "Bulan {$periodLabel}: ".($row['month_qty'] ?? 0).' / '.($row['target'] ?? 0)." ({$pct})",
            "Status: {$status}",
            '',
        ];

        if (! empty($row['missing'])) {
            $lines[] = "Mohon segera input closing {$closingWord} di BMS.";
        } else {
            $lines[] = "Terima kasih, closing {$closingWord} sudah tercatat.";
        }

        return implode("\n", $lines);
    }
}
