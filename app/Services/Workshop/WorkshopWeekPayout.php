<?php

namespace App\Services\Workshop;

use App\Models\User;
use App\Models\WorkshopJob;
use App\Models\WorkshopWageSetting;
use App\Models\WorkshopWeek;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class WorkshopWeekPayout
{
    public const SNAPSHOT_VERSION = 2;

    /**
     * @return array{start: string, end: string, label: string, pay_monday: string}
     */
    public function weekRangeFromStart(string $anyDateInWeek): array
    {
        $d = Carbon::parse($anyDateInWeek)->startOfDay();
        $start = $d->copy()->startOfWeek(Carbon::MONDAY);
        $end = $start->copy()->endOfWeek(Carbon::SUNDAY);
        $payMonday = $end->copy()->addDay();

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'label' => 'Senin '.$start->format('d/m').' – Minggu '.$end->format('d/m/Y'),
            'pay_monday' => $payMonday->format('d/m/Y'),
        ];
    }

    /**
     * @return array<int, float>
     */
    public function loadTechShareMap(int $branchId, int $year, int $month): array
    {
        $rows = WorkshopWageSetting::query()
            ->where('branch_id', $branchId)
            ->where('year', $year)
            ->where('month', $month)
            ->get(['employee_id', 'tech_share_pct']);

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->employee_id] = (float) $row->tech_share_pct;
        }

        return $map;
    }

    /**
     * @param  array<string|int, mixed>  $raw
     * @return array<int, float>
     */
    public function normalizeShareMap(array $raw): array
    {
        if (isset($raw['technicians']) && is_array($raw['technicians'])) {
            $raw = $raw['technicians'];
        }

        $map = [];
        foreach ($raw as $employeeId => $pct) {
            if (in_array((string) $employeeId, ['technicians', 'totals', 'frozen_at', 'jobs', 'version'], true)) {
                continue;
            }
            $map[(int) $employeeId] = is_array($pct)
                ? (float) ($pct['tech_share_pct'] ?? WorkshopWageSetting::DEFAULT_TECH_SHARE_PCT)
                : (float) $pct;
        }

        return $map;
    }

    /**
     * @return array{
     *   totals: array<string, float|int>,
     *   technicians: list<array<string, mixed>>,
     *   jobs?: list<array<string, mixed>>
     * }|null
     */
    public function summaryFromSnapshot(array $snapshot): ?array
    {
        if (! isset($snapshot['technicians'], $snapshot['totals']) || ! is_array($snapshot['technicians'])) {
            return null;
        }

        $technicians = [];
        foreach ($snapshot['technicians'] as $employeeId => $row) {
            if (! is_array($row)) {
                continue;
            }
            $technicians[] = [
                'employee_id' => (int) $employeeId,
                'name' => $row['name'] ?? '—',
                'position' => $row['position'] ?? null,
                'job_count' => (int) ($row['job_count'] ?? 0),
                'gross' => (float) ($row['gross'] ?? 0),
                'tech_share_pct' => (float) ($row['tech_share_pct'] ?? WorkshopWageSetting::DEFAULT_TECH_SHARE_PCT),
                'net' => (float) ($row['net'] ?? 0),
            ];
        }

        usort($technicians, fn ($a, $b) => strcmp($a['name'], $b['name']));

        $result = [
            'totals' => [
                'job_count' => (int) ($snapshot['totals']['job_count'] ?? 0),
                'gross' => (float) ($snapshot['totals']['gross'] ?? 0),
                'tech_net' => (float) ($snapshot['totals']['tech_net'] ?? 0),
                'shop_share' => (float) ($snapshot['totals']['shop_share'] ?? 0),
            ],
            'technicians' => $technicians,
        ];

        if (isset($snapshot['jobs']) && is_array($snapshot['jobs'])) {
            $result['jobs'] = array_values(array_map(
                fn ($job) => is_array($job) ? $job : [],
                $snapshot['jobs']
            ));
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function jobsFromSnapshot(array $snapshot): ?array
    {
        if (! isset($snapshot['jobs']) || ! is_array($snapshot['jobs'])) {
            return null;
        }

        return array_values(array_filter($snapshot['jobs'], 'is_array'));
    }

    /**
     * @param  array<int, float>  $pctMap
     * @return array{
     *   totals: array<string, float|int>,
     *   technicians: list<array<string, mixed>>,
     *   jobs: list<array<string, mixed>>
     * }
     */
    public function weekSummary(int $branchId, string $from, string $to, array $pctMap): array
    {
        $jobs = WorkshopJob::query()
            ->with(['employee:id,name,position', 'inputter:id,name'])
            ->where('branch_id', $branchId)
            ->whereBetween('job_date', [$from, $to])
            ->orderBy('job_date')
            ->orderBy('id')
            ->get();

        $byEmp = $jobs->groupBy('employee_id');
        $technicians = [];
        $gross = '0.00';
        $net = '0.00';

        foreach ($byEmp as $employeeId => $group) {
            $eid = (int) $employeeId;
            $pct = $this->pctForEmployee($pctMap, $eid);
            $empGross = '0.00';
            foreach ($group as $j) {
                $empGross = Money::add($empGross, $j->amount);
            }
            $empNet = Money::percentOf($empGross, $pct);
            $gross = Money::add($gross, $empGross);
            $net = Money::add($net, $empNet);
            $emp = $group->first()->employee;
            $technicians[] = [
                'employee_id' => $eid,
                'name' => $emp?->name ?? '—',
                'position' => $emp?->position,
                'job_count' => $group->count(),
                'gross' => (float) $empGross,
                'tech_share_pct' => $pct,
                'net' => (float) $empNet,
            ];
        }

        usort($technicians, fn ($a, $b) => strcmp($a['name'], $b['name']));

        $jobPayloads = $jobs->map(fn (WorkshopJob $job) => $this->jobPayload($job))->values()->all();

        return [
            'totals' => [
                'job_count' => $jobs->count(),
                'gross' => (float) $gross,
                'tech_net' => (float) $net,
                'shop_share' => (float) Money::sub($gross, $net),
            ],
            'technicians' => $technicians,
            'jobs' => $jobPayloads,
        ];
    }

    /**
     * Ringkasan minggu: frozen jika paid + snapshot lengkap, else live.
     *
     * @param  array<int, float>  $pctMap
     * @return array{
     *   totals: array<string, float|int>,
     *   technicians: list<array<string, mixed>>,
     *   jobs?: list<array<string, mixed>>
     * }
     */
    public function resolveWeekSummary(?WorkshopWeek $week, int $branchId, string $from, string $to, array $pctMap): array
    {
        if ($week && $week->isPaid()) {
            $snapshot = is_array($week->shares_snapshot) ? $week->shares_snapshot : [];
            $frozen = $this->summaryFromSnapshot($snapshot);
            if ($frozen) {
                return $frozen;
            }

            $map = $this->normalizeShareMap($snapshot);

            return $this->weekSummary($branchId, $from, $to, $map ?: $pctMap);
        }

        return $this->weekSummary($branchId, $from, $to, $pctMap);
    }

    /**
     * @return array{week: WorkshopWeek, summary: array<string, mixed>}
     */
    public function markPaid(int $branchId, string $weekStart, User $actor): array
    {
        $range = $this->weekRangeFromStart($weekStart);
        $year = (int) Carbon::parse($range['start'])->year;
        $month = (int) Carbon::parse($range['start'])->month;
        $pctMap = $this->loadTechShareMap($branchId, $year, $month);
        $summary = $this->weekSummary($branchId, $range['start'], $range['end'], $pctMap);

        $techSnapshot = [];
        foreach ($summary['technicians'] as $tech) {
            $techSnapshot[(string) $tech['employee_id']] = [
                'tech_share_pct' => $tech['tech_share_pct'],
                'gross' => $tech['gross'],
                'net' => $tech['net'],
                'job_count' => $tech['job_count'],
                'name' => $tech['name'],
                'position' => $tech['position'] ?? null,
            ];
        }

        $now = now();
        $sharesSnapshot = [
            'version' => self::SNAPSHOT_VERSION,
            'technicians' => $techSnapshot,
            'totals' => $summary['totals'],
            'jobs' => $summary['jobs'],
            'frozen_at' => $now->toIso8601String(),
        ];

        $week = DB::transaction(function () use ($branchId, $range, $sharesSnapshot, $actor, $now) {
            $existing = WorkshopWeek::query()
                ->where('branch_id', $branchId)
                ->whereDate('week_start', $range['start'])
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->isPaid()) {
                throw new \RuntimeException('Minggu ini sudah ditandai lunas.');
            }

            return WorkshopWeek::query()->updateOrCreate(
                [
                    'branch_id' => $branchId,
                    'week_start' => $range['start'],
                ],
                [
                    'week_end' => $range['end'],
                    'status' => WorkshopWeek::STATUS_PAID,
                    'tech_share_pct_snapshot' => null,
                    'shares_snapshot' => $sharesSnapshot,
                    'paid_at' => $now,
                    'paid_by' => $actor->id,
                ]
            );
        });

        return [
            'week' => $week,
            'summary' => $summary,
            'range' => $range,
        ];
    }

    public function denyIfWeekPaid(int $branchId, string $jobDate): ?JsonResponse
    {
        $range = $this->weekRangeFromStart($jobDate);
        $paid = WorkshopWeek::query()
            ->where('branch_id', $branchId)
            ->whereDate('week_start', $range['start'])
            ->where('status', WorkshopWeek::STATUS_PAID)
            ->exists();

        if ($paid) {
            return response()->json([
                'message' => 'Minggu ini sudah lunas. Buka kunci minggu dulu untuk mengubah data.',
            ], 422);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function jobPayload(WorkshopJob $job): array
    {
        return [
            'id' => $job->id,
            'branch_id' => $job->branch_id,
            'employee_id' => $job->employee_id,
            'employee_name' => $job->employee?->name,
            'job_date' => $job->job_date?->toDateString(),
            'job_type' => $job->job_type,
            'amount' => (float) $job->amount,
            'note' => $job->note,
            'input_by' => $job->input_by,
            'inputter_name' => $job->inputter?->name,
        ];
    }

    /**
     * @param  array<int, float>  $pctMap
     */
    private function pctForEmployee(array $pctMap, int $employeeId): float
    {
        return array_key_exists($employeeId, $pctMap)
            ? (float) $pctMap[$employeeId]
            : WorkshopWageSetting::DEFAULT_TECH_SHARE_PCT;
    }
}
