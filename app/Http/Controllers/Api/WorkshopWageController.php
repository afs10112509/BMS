<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\WorkshopJob;
use App\Models\WorkshopWageSetting;
use App\Models\WorkshopWeek;
use App\Services\BranchContext;
use App\Services\Workshop\WorkshopWeekPayout;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkshopWageController extends Controller
{
    public function __construct(
        protected BranchContext $branchContext,
        protected WorkshopWeekPayout $payout,
    ) {}

    public function getSettings(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorize('viewAny', WorkshopJob::class);
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $this->resolveWorkshopBranchId($user, $data['branch_id'] ?? null, requireBranch: true);
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        $year = (int) $data['year'];
        $month = (int) $data['month'];
        $pctMap = $this->payout->loadTechShareMap($branchId, $year, $month);
        $technicians = $this->eligibleTechniciansQuery($branchId)->get(['id', 'name', 'position']);

        $rows = $technicians->map(function (Employee $emp) use ($pctMap) {
            $has = array_key_exists($emp->id, $pctMap);
            $pct = $has ? $pctMap[$emp->id] : WorkshopWageSetting::DEFAULT_TECH_SHARE_PCT;

            return [
                'employee_id' => $emp->id,
                'name' => $emp->name,
                'position' => $emp->position,
                'tech_share_pct' => $pct,
                'is_default' => ! $has,
            ];
        })->values();

        return response()->json([
            'message' => 'Pengaturan upah per teknisi berhasil diambil.',
            'meta' => [
                'branch_id' => $branchId,
                'year' => $year,
                'month' => $month,
                'default_tech_share_pct' => WorkshopWageSetting::DEFAULT_TECH_SHARE_PCT,
            ],
            'data' => $rows,
        ]);
    }

    public function upsertSettings(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.employee_id' => ['required', 'integer', 'exists:employees,id'],
            'items.*.tech_share_pct' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $branchId = $this->resolveWorkshopBranchId($user, $data['branch_id'] ?? null, requireBranch: true);
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        $year = (int) $data['year'];
        $month = (int) $data['month'];
        $allowed = $this->eligibleTechniciansQuery($branchId)->pluck('id')->all();
        $allowedSet = array_fill_keys($allowed, true);

        foreach ($data['items'] as $item) {
            if (! isset($allowedSet[(int) $item['employee_id']])) {
                return response()->json([
                    'message' => 'Ada teknisi yang tidak valid untuk cabang bengkel ini.',
                ], 422);
            }
        }

        DB::transaction(function () use ($data, $branchId, $year, $month, $user) {
            foreach ($data['items'] as $item) {
                WorkshopWageSetting::query()->updateOrCreate(
                    [
                        'branch_id' => $branchId,
                        'employee_id' => (int) $item['employee_id'],
                        'year' => $year,
                        'month' => $month,
                    ],
                    [
                        'tech_share_pct' => round((float) $item['tech_share_pct'], 2),
                        'set_by' => $user->id,
                    ]
                );
            }
        });

        return $this->getSettings($request);
    }

    public function jobs(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorize('viewAny', WorkshopJob::class);
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $this->resolveWorkshopBranchId($user, $data['branch_id'] ?? null, requireBranch: true);
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        $query = WorkshopJob::query()
            ->with(['employee:id,name,position', 'inputter:id,name'])
            ->where('branch_id', $branchId)
            ->orderByDesc('job_date')
            ->orderByDesc('id');

        if (! empty($data['date'])) {
            $query->whereDate('job_date', Carbon::parse($data['date'])->toDateString());
        } else {
            if (! empty($data['date_from'])) {
                $query->whereDate('job_date', '>=', Carbon::parse($data['date_from'])->toDateString());
            }
            if (! empty($data['date_to'])) {
                $query->whereDate('job_date', '<=', Carbon::parse($data['date_to'])->toDateString());
            }
        }

        $jobs = $query->limit(500)->get();
        $rows = $jobs->map(fn (WorkshopJob $job) => $this->payout->jobPayload($job));

        return response()->json([
            'message' => 'Daftar kerja bengkel berhasil diambil.',
            'meta' => [
                'branch_id' => $branchId,
                'total_amount' => round($jobs->sum(fn ($j) => (float) $j->amount), 2),
            ],
            'data' => $rows,
        ]);
    }

    public function storeJob(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorize('create', WorkshopJob::class);
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'job_date' => ['required', 'date'],
            'job_type' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $branchId = $this->resolveWorkshopBranchId($user, $data['branch_id'] ?? null, requireBranch: true);
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        $jobDate = Carbon::parse($data['job_date'])->toDateString();
        if ($deny = $this->payout->denyIfWeekPaid($branchId, $jobDate)) {
            return $deny;
        }

        $employee = $this->eligibleTechniciansQuery($branchId)
            ->where('employees.id', (int) $data['employee_id'])
            ->first();
        if (! $employee) {
            return response()->json(['message' => 'Teknisi tidak valid untuk cabang bengkel ini.'], 422);
        }

        $job = WorkshopJob::query()->create([
            'branch_id' => $branchId,
            'employee_id' => $employee->id,
            'job_date' => $jobDate,
            'job_type' => trim($data['job_type']),
            'amount' => round((float) $data['amount'], 2),
            'note' => $data['note'] ?? null,
            'input_by' => $user->id,
        ]);
        $job->load(['employee:id,name,position', 'inputter:id,name']);

        return response()->json([
            'message' => 'Kerja bengkel berhasil ditambah.',
            'data' => $this->payout->jobPayload($job),
        ], 201);
    }

    public function updateJob(Request $request, WorkshopJob $workshopJob): JsonResponse
    {
        $user = $request->user();
        $this->authorize('update', $workshopJob);

        $access = $this->authorizeJobBranch($user, $workshopJob);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        if ($deny = $this->payout->denyIfWeekPaid($workshopJob->branch_id, $workshopJob->job_date->toDateString())) {
            return $deny;
        }

        $data = $request->validate([
            'employee_id' => ['sometimes', 'integer', 'exists:employees,id'],
            'job_date' => ['sometimes', 'date'],
            'job_type' => ['sometimes', 'string', 'max:100'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $jobDate = isset($data['job_date'])
            ? Carbon::parse($data['job_date'])->toDateString()
            : $workshopJob->job_date->toDateString();

        if ($jobDate !== $workshopJob->job_date->toDateString()) {
            if ($deny = $this->payout->denyIfWeekPaid($workshopJob->branch_id, $jobDate)) {
                return $deny;
            }
        }

        if (isset($data['employee_id'])) {
            $employee = $this->eligibleTechniciansQuery($workshopJob->branch_id)
                ->where('employees.id', (int) $data['employee_id'])
                ->first();
            if (! $employee) {
                return response()->json(['message' => 'Teknisi tidak valid untuk cabang bengkel ini.'], 422);
            }
            $workshopJob->employee_id = $employee->id;
        }

        if (isset($data['job_date'])) {
            $workshopJob->job_date = $jobDate;
        }
        if (isset($data['job_type'])) {
            $workshopJob->job_type = trim($data['job_type']);
        }
        if (array_key_exists('amount', $data)) {
            $workshopJob->amount = round((float) $data['amount'], 2);
        }
        if (array_key_exists('note', $data)) {
            $workshopJob->note = $data['note'];
        }
        $workshopJob->input_by = $user->id;
        $workshopJob->save();
        $workshopJob->load(['employee:id,name,position', 'inputter:id,name']);

        return response()->json([
            'message' => 'Kerja bengkel diperbarui.',
            'data' => $this->payout->jobPayload($workshopJob),
        ]);
    }

    public function destroyJob(Request $request, WorkshopJob $workshopJob): JsonResponse
    {
        $user = $request->user();
        $this->authorize('delete', $workshopJob);

        $access = $this->authorizeJobBranch($user, $workshopJob);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        if ($deny = $this->payout->denyIfWeekPaid($workshopJob->branch_id, $workshopJob->job_date->toDateString())) {
            return $deny;
        }

        $workshopJob->delete();

        return response()->json(['message' => 'Kerja bengkel dihapus.']);
    }

    public function weeks(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $this->resolveWorkshopBranchId($user, $data['branch_id'] ?? null, requireBranch: true);
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        $year = (int) $data['year'];
        $month = (int) $data['month'];
        $weekStarts = $this->weekStartsTouchingMonth($year, $month);
        $pctMap = $this->payout->loadTechShareMap($branchId, $year, $month);

        $stored = WorkshopWeek::query()
            ->where('branch_id', $branchId)
            ->whereIn('week_start', $weekStarts)
            ->get()
            ->keyBy(fn (WorkshopWeek $w) => $w->week_start->toDateString());

        $rows = collect($weekStarts)->map(function (string $start) use ($branchId, $stored, $pctMap) {
            $range = $this->payout->weekRangeFromStart($start);
            $week = $stored->get($start);
            $summary = $this->payout->resolveWeekSummary(
                $week,
                $branchId,
                $range['start'],
                $range['end'],
                $pctMap,
            );

            return [
                'week_id' => $week?->id,
                'branch_id' => $branchId,
                'week_start' => $range['start'],
                'week_end' => $range['end'],
                'label' => $range['label'],
                'pay_hint' => 'Dibayar Senin '.$range['pay_monday'],
                'status' => $week?->status ?? WorkshopWeek::STATUS_OPEN,
                'totals' => $summary['totals'],
                'technicians' => $summary['technicians'],
                'paid_at' => $week?->paid_at?->toIso8601String(),
            ];
        })->values();

        $previous = $this->previousWeekStart();

        return response()->json([
            'message' => 'Rekap mingguan upah bengkel berhasil diambil.',
            'meta' => [
                'branch_id' => $branchId,
                'year' => $year,
                'month' => $month,
                'default_tech_share_pct' => WorkshopWageSetting::DEFAULT_TECH_SHARE_PCT,
                'previous_week_start' => $previous,
            ],
            'data' => $rows,
        ]);
    }

    public function weekDetail(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'week_start' => ['required', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $this->resolveWorkshopBranchId($user, $data['branch_id'] ?? null, requireBranch: true);
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        $range = $this->payout->weekRangeFromStart(Carbon::parse($data['week_start'])->toDateString());
        $week = WorkshopWeek::query()
            ->where('branch_id', $branchId)
            ->whereDate('week_start', $range['start'])
            ->first();

        $year = (int) Carbon::parse($range['start'])->year;
        $month = (int) Carbon::parse($range['start'])->month;
        $pctMap = $this->payout->loadTechShareMap($branchId, $year, $month);
        $summary = $this->payout->resolveWeekSummary(
            $week,
            $branchId,
            $range['start'],
            $range['end'],
            $pctMap,
        );

        $snapshot = is_array($week?->shares_snapshot) ? $week->shares_snapshot : [];
        $frozenJobs = $week && $week->isPaid()
            ? $this->payout->jobsFromSnapshot($snapshot)
            : null;

        if ($frozenJobs !== null) {
            $jobs = $frozenJobs;
        } else {
            $jobs = WorkshopJob::query()
                ->with(['employee:id,name,position', 'inputter:id,name'])
                ->where('branch_id', $branchId)
                ->whereBetween('job_date', [$range['start'], $range['end']])
                ->orderBy('job_date')
                ->orderBy('id')
                ->get()
                ->map(fn (WorkshopJob $job) => $this->payout->jobPayload($job))
                ->values()
                ->all();
        }

        return response()->json([
            'message' => 'Detail minggu upah bengkel berhasil diambil.',
            'data' => [
                'week_id' => $week?->id,
                'branch_id' => $branchId,
                'week_start' => $range['start'],
                'week_end' => $range['end'],
                'label' => $range['label'],
                'pay_hint' => 'Dibayar Senin '.$range['pay_monday'],
                'status' => $week?->status ?? WorkshopWeek::STATUS_OPEN,
                'totals' => $summary['totals'],
                'technicians' => $summary['technicians'],
                'jobs' => $jobs,
                'paid_at' => $week?->paid_at?->toIso8601String(),
                'shares_snapshot' => $week?->shares_snapshot,
            ],
        ]);
    }

    public function payWeek(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'week_start' => ['required', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $this->resolveWorkshopBranchId($user, $data['branch_id'] ?? null, requireBranch: true);
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        try {
            $result = $this->payout->markPaid(
                $branchId,
                Carbon::parse($data['week_start'])->toDateString(),
                $user,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $week = $result['week'];
        $range = $result['range'];
        $summary = $result['summary'];

        return response()->json([
            'message' => 'Minggu upah bengkel ditandai lunas.',
            'data' => [
                'week_id' => $week->id,
                'week_start' => $range['start'],
                'week_end' => $range['end'],
                'status' => WorkshopWeek::STATUS_PAID,
                'shares_snapshot' => $week->shares_snapshot,
                'totals' => $summary['totals'],
            ],
        ]);
    }

    public function reopenWeek(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isOwner()) {
            return response()->json(['message' => 'Hanya owner yang boleh membuka kembali minggu lunas.'], 403);
        }

        $data = $request->validate([
            'week_start' => ['required', 'date'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $this->resolveWorkshopBranchId($user, (int) $data['branch_id'], requireBranch: true);
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        $range = $this->payout->weekRangeFromStart(Carbon::parse($data['week_start'])->toDateString());
        $week = WorkshopWeek::query()
            ->where('branch_id', $branchId)
            ->whereDate('week_start', $range['start'])
            ->first();

        if (! $week || ! $week->isPaid()) {
            return response()->json(['message' => 'Tidak ada minggu lunas untuk dibuka.'], 422);
        }

        $week->update([
            'status' => WorkshopWeek::STATUS_OPEN,
            'tech_share_pct_snapshot' => null,
            'shares_snapshot' => null,
            'paid_at' => null,
            'paid_by' => null,
        ]);

        return response()->json([
            'message' => 'Minggu upah dibuka kembali.',
            'data' => [
                'week_id' => $week->id,
                'week_start' => $range['start'],
                'week_end' => $range['end'],
                'status' => WorkshopWeek::STATUS_OPEN,
            ],
        ]);
    }

    public function technicians(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $this->resolveWorkshopBranchId($user, $data['branch_id'] ?? null, requireBranch: true);
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        $rows = $this->eligibleTechniciansQuery($branchId)->get(['id', 'name', 'position', 'branch_id']);

        return response()->json([
            'message' => 'Daftar teknisi bengkel berhasil diambil.',
            'data' => $rows,
        ]);
    }

    public function jobTypes(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $this->resolveWorkshopBranchId($user, $data['branch_id'] ?? null, requireBranch: true);
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        $defaults = ['ONGKER', 'GANTI OLI', 'GANTI BAN DALAM', 'GANTI KAMPAS', 'GANTI LAHAR', 'TUBLES'];
        $fromDb = WorkshopJob::query()
            ->where('branch_id', $branchId)
            ->select('job_type')
            ->distinct()
            ->orderBy('job_type')
            ->pluck('job_type')
            ->all();

        $merged = collect(array_merge($defaults, $fromDb))
            ->map(fn ($t) => trim((string) $t))
            ->filter()
            ->unique(fn ($t) => mb_strtoupper($t))
            ->values()
            ->all();

        return response()->json([
            'message' => 'Jenis kerja berhasil diambil.',
            'data' => $merged,
        ]);
    }

    /**
     * @return list<string>
     */
    private function weekStartsTouchingMonth(int $year, int $month): array
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $cursor = $monthStart->copy()->startOfWeek(Carbon::MONDAY);
        $ends = $monthEnd->copy()->startOfWeek(Carbon::MONDAY);
        $out = [];
        while ($cursor->lte($ends)) {
            $out[] = $cursor->toDateString();
            $cursor->addWeek();
        }

        return $out;
    }

    private function previousWeekStart(): string
    {
        return Carbon::now()->startOfWeek(Carbon::MONDAY)->subWeek()->toDateString();
    }

    /**
     * @return int|JsonResponse
     */
    private function resolveWorkshopBranchId($user, ?int $requestedBranchId, bool $requireBranch)
    {
        return $this->branchContext->resolveWorkshop($user, $requestedBranchId, $requireBranch);
    }

    private function authorizeJobBranch($user, WorkshopJob $job): ?JsonResponse
    {
        // Global Scope sudah menyembunyikan job cabang lain (404).
        // Defense-in-depth: pastikan tipe bengkel + ownership.
        $branchId = $this->resolveWorkshopBranchId($user, $job->branch_id, requireBranch: true);
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }
        if ((int) $job->branch_id !== (int) $branchId) {
            return response()->json(['message' => 'Akses ditolak untuk cabang ini.'], 403);
        }

        return null;
    }

    private function eligibleTechniciansQuery(int $branchId)
    {
        return Employee::query()
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->withoutManagement()
            ->orderBy('name');
    }
}
