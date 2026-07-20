<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeDailyClosing;
use App\Models\EmployeeMonthlyTarget;
use App\Models\InterBranchTransfer;
use App\Models\Payroll;
use App\Models\PeriodLock;
use App\Models\Reconciliation;
use App\Models\ServiceRecord;
use App\Models\Transaction;
use App\Models\WorkshopJob;
use App\Models\WorkshopWageSetting;
use App\Models\WorkshopWeek;
use App\Services\BranchBalanceCalculator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    public function __construct(
        protected BranchBalanceCalculator $balanceCalculator,
    ) {}

    public function owner(Request $request): JsonResponse
    {
        $branchId = $request->filled('branch_id')
            ? $request->integer('branch_id')
            : null;

        $year = $request->integer('year', (int) now()->year);
        $month = $request->integer('month', (int) now()->month);

        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            return response()->json([
                'message' => 'Bulan atau tahun tidak valid.',
            ], 422);
        }

        $periodStart = Carbon::create($year, $month, 1)->startOfDay();
        $from = $periodStart->toDateString();
        $to = $periodStart->copy()->endOfMonth()->toDateString();

        $prevStart = $periodStart->copy()->subMonthNoOverflow();
        $prevFrom = $prevStart->toDateString();
        $prevTo = $prevStart->copy()->endOfMonth()->toDateString();

        $agregat = $this->balanceCalculator->branchComparison($from, $to, $branchId);
        $agregatPrev = $this->balanceCalculator->branchComparison($prevFrom, $prevTo, $branchId);
        $periode = $this->sumAgregat($agregat);
        $periodePrev = $this->sumAgregat($agregatPrev);

        $service = $this->serviceSummary($branchId, $from, $to);
        $servicePrev = $this->serviceSummary($branchId, $prevFrom, $prevTo);

        $pendingTransfers = InterBranchTransfer::query()
            ->with([
                'fromBranch.branchType',
                'toBranch.branchType',
                'account',
                'requester:id,name',
            ])
            ->where('status', 'pending')
            ->when($branchId, function ($q) use ($branchId) {
                $q->where(function ($inner) use ($branchId) {
                    $inner->where('from_branch_id', $branchId)
                        ->orWhere('to_branch_id', $branchId);
                });
            })
            ->latest()
            ->get();

        $differenceAlerts = Reconciliation::query()
            ->with(['branch.branchType', 'account:id,name,code', 'user:id,name'])
            ->where('difference', '!=', 0)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('reconciliation_date', '>=', $from)
            ->whereDate('reconciliation_date', '<=', $to)
            ->latest('reconciliation_date')
            ->limit(20)
            ->get();

        $kategori = $this->balanceCalculator->totalsByCategory($branchId, $from, $to);
        $scopeBranch = $branchId ? Branch::query()->with('branchType')->find($branchId) : null;
        $isWorkshopScope = $scopeBranch?->isWorkshop() ?? false;
        $isKonterScope = $scopeBranch ? ! $isWorkshopScope : null;

        $data = [
            'branch_id' => $branchId,
            'year' => $year,
            'month' => $month,
            'date_from' => $from,
            'date_to' => $to,
            'prev_date_from' => $prevFrom,
            'prev_date_to' => $prevTo,
            'scope' => [
                'is_workshop' => $isWorkshopScope,
                'is_konter' => $isKonterScope,
                'allows_service' => $scopeBranch ? $scopeBranch->allowsService() : true,
            ],
            'agregat_cabang' => $agregat,
            'saldo_per_cabang' => $this->balanceCalculator->balancesByBranch($branchId, $to),
            'saldo_per_kategori' => $kategori,
            'saldo_per_kategori_top' => $this->topCategories($kategori, 5),
            'kategori_branch_id' => $branchId,
            'transfer_pending' => $pendingTransfers,
            'alert_selisih_rekonsiliasi' => $differenceAlerts,
            'periode' => $periode,
            'periode_prev' => $periodePrev,
            'periode_change' => [
                'omzet_pct' => $this->pctChange($periode['omzet'], $periodePrev['omzet']),
                'beban_pct' => $this->pctChange($periode['beban'], $periodePrev['beban']),
                'profit_pct' => $this->pctChange($periode['profit'], $periodePrev['profit']),
            ],
            'service' => $service,
            'service_prev' => $servicePrev,
            'service_change' => [
                'harga_pct' => $this->pctChange($service['total_harga'], $servicePrev['total_harga']),
                'profit_pct' => $this->pctChange($service['total_profit'], $servicePrev['total_profit']),
            ],
            'payroll' => $this->payrollSummary($branchId, $year, $month),
            'closing' => ($isKonterScope === false) ? null : $this->closingSummary($branchId, $year, $month),
            'attendance_today' => $this->attendanceTodaySummary($branchId),
            'workshop_week' => ($isKonterScope === true) ? null : $this->workshopWeekSummary($branchId),
            'arus_kas_harian' => null,
            'saldo_per_akun' => null,
        ];

        if ($branchId) {
            $data['arus_kas_harian'] = $this->balanceCalculator->dailyCashflow($branchId, 7, $from, $to);
            $data['saldo_per_akun'] = $this->balanceCalculator->balancesByAccount($branchId, $to);
            $data['saldo_kas'] = $this->balanceCalculator->systemBalance($branchId, $to);
        }

        return response()->json([
            'message' => 'Dasbor pemilik berhasil diambil.',
            'data' => $data,
        ]);
    }

    public function branch(Request $request): JsonResponse
    {
        $user = $request->user();
        $branchId = $user->isOwner()
            ? $request->integer('branch_id') ?: $user->branch_id
            : $user->branch_id;

        if (! $branchId) {
            return response()->json([
                'message' => 'Cabang wajib dipilih.',
            ], 422);
        }

        if ($user->isAdmin() && (int) $branchId !== (int) $user->branch_id) {
            return response()->json([
                'message' => 'Anda tidak boleh melihat dasbor cabang lain.',
            ], 403);
        }

        $period = now()->format('Y-m');
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();
        $year = (int) now()->year;
        $month = (int) now()->month;

        $asOf = $request->filled('as_of')
            ? $request->date('as_of')->toDateString()
            : null;

        $branch = Branch::query()->with('branchType')->find($branchId);
        $isWorkshop = $branch?->isWorkshop() ?? false;

        $agregat = $this->balanceCalculator->branchComparison($from, $to, $branchId);
        $periode = $this->sumAgregat($agregat);
        $kategori = $this->balanceCalculator->totalsByCategory($branchId, $from, $to);
        $service = $isWorkshop ? null : $this->serviceSummary($branchId, $from, $to);
        $accounts = $this->balanceCalculator->balancesByAccount($branchId, $asOf);

        return response()->json([
            'message' => 'Dasbor cabang berhasil diambil.',
            'data' => [
                'saldo_kas' => $this->balanceCalculator->systemBalance($branchId, $asOf),
                'saldo_per_akun' => $accounts,
                'saldo_per_kategori' => $kategori,
                'saldo_per_kategori_top' => $this->topCategories($kategori, 5),
                'arus_kas_harian' => $this->balanceCalculator->dailyCashflow($branchId),
                'periode' => $periode,
                'periode_label' => now()->format('Y-m'),
                'service' => $service,
                'closing' => $isWorkshop ? null : $this->closingSummary($branchId, $year, $month),
                'attendance_today' => $this->attendanceTodaySummary($branchId),
                'workshop_week' => $isWorkshop ? $this->workshopWeekSummary($branchId) : null,
                'recon_status' => $this->reconStatusSummary($accounts),
                'transaksi_terakhir' => Transaction::query()
                    ->with(['category', 'account'])
                    ->where('branch_id', $branchId)
                    ->latest('transaction_date')
                    ->latest('id')
                    ->limit(5)
                    ->get(),
                'rekonsiliasi_hari_ini' => Reconciliation::query()
                    ->with('account:id,name,code')
                    ->where('branch_id', $branchId)
                    ->whereDate('reconciliation_date', now()->toDateString())
                    ->orderBy('account_id')
                    ->get(),
                'kunci_periode' => PeriodLock::query()
                    ->where('branch_id', $branchId)
                    ->where('period', $period)
                    ->first(),
                'as_of' => $asOf,
                'scope' => [
                    'is_workshop' => $isWorkshop,
                    'allows_service' => $branch?->allowsService() ?? true,
                ],
            ],
        ]);
    }

    /**
     * @param  list<array{pemasukan:float|int,pengeluaran:float|int,saldo?:float|int}>  $rows
     * @return array{omzet:float,beban:float,profit:float}
     */
    protected function sumAgregat(array $rows): array
    {
        $omzet = (float) collect($rows)->sum('pemasukan');
        $beban = (float) collect($rows)->sum('pengeluaran');

        return [
            'omzet' => $omzet,
            'beban' => $beban,
            'profit' => $omzet - $beban,
        ];
    }

    /**
     * @return array{
     *   jumlah:int,
     *   total_modal:float,
     *   total_harga:float,
     *   total_profit:float,
     *   per_cabang:list<array{branch_id:int,nama_cabang:string,jumlah:int,total_harga:float,total_profit:float}>
     * }
     */
    protected function serviceSummary(?int $branchId, string $from, string $to): array
    {
        $query = ServiceRecord::query()
            ->with('branch:id,name')
            ->whereDate('service_date', '>=', $from)
            ->whereDate('service_date', '<=', $to);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        /** @var Collection<int, ServiceRecord> $rows */
        $rows = $query->get();

        $perCabang = $rows
            ->groupBy('branch_id')
            ->map(function (Collection $group, $id) {
                $first = $group->first();

                return [
                    'branch_id' => (int) $id,
                    'nama_cabang' => $first?->branch?->name ?? (string) $id,
                    'jumlah' => $group->count(),
                    'total_harga' => (float) $group->sum('price'),
                    'total_profit' => (float) $group->sum('profit'),
                ];
            })
            ->sortByDesc('total_harga')
            ->values()
            ->all();

        return [
            'jumlah' => $rows->count(),
            'total_modal' => (float) $rows->sum('cost'),
            'total_harga' => (float) $rows->sum('price'),
            'total_profit' => (float) $rows->sum('profit'),
            'per_cabang' => $perCabang,
        ];
    }

    /**
     * @return array{total:float,draft:int,locked:int,karyawan:int,year:int,month:int}
     */
    protected function payrollSummary(?int $branchId, int $year, int $month): array
    {
        $query = Payroll::query()
            ->where('year', $year)
            ->where('month', $month);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        } else {
            $konterIds = Branch::query()
                ->with('branchType')
                ->get()
                ->filter(fn (Branch $b) => ! $b->isWorkshop())
                ->pluck('id');
            $query->whereIn('branch_id', $konterIds);
        }

        $rows = $query->get(['status', 'total']);

        return [
            'year' => $year,
            'month' => $month,
            'total' => round((float) $rows->sum('total'), 2),
            'draft' => $rows->where('status', Payroll::STATUS_DRAFT)->count(),
            'locked' => $rows->where('status', Payroll::STATUS_LOCKED)->count(),
            'karyawan' => $rows->count(),
        ];
    }

    /**
     * @return array{qty:int,target:int,pct:?float,year:int,month:int}
     */
    protected function closingSummary(?int $branchId, int $year, int $month): array
    {
        $employeeIds = Employee::query()
            ->where('status', 'active')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when(! $branchId, function ($q) {
                $konterIds = Branch::query()
                    ->with('branchType')
                    ->get()
                    ->filter(fn (Branch $b) => ! $b->isWorkshop())
                    ->pluck('id');
                $q->whereIn('branch_id', $konterIds);
            })
            ->where(function ($q) {
                $q->whereNull('position')
                    ->orWhereRaw('LOWER(TRIM(position)) NOT IN (?, ?)', ['owner', 'pemilik']);
            })
            ->pluck('id');

        $from = Carbon::create($year, $month, 1)->toDateString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        $qty = (int) EmployeeDailyClosing::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('closing_date', '>=', $from)
            ->whereDate('closing_date', '<=', $to)
            ->sum('qty');

        $target = (int) EmployeeMonthlyTarget::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('year', $year)
            ->where('month', $month)
            ->sum('target');

        $pct = $target > 0 ? round(($qty / $target) * 100, 1) : null;

        return [
            'year' => $year,
            'month' => $month,
            'qty' => $qty,
            'target' => $target,
            'pct' => $pct,
        ];
    }

    /**
     * @return array{date:string,present:int,leave:int,sick:int,absent:int,unmarked:int,total_employees:int}
     */
    protected function attendanceTodaySummary(?int $branchId): array
    {
        $date = now()->toDateString();
        $employees = Employee::query()
            ->where('status', 'active')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get(['id']);

        $byStatus = EmployeeAttendance::query()
            ->whereIn('employee_id', $employees->pluck('id'))
            ->whereDate('attendance_date', $date)
            ->get()
            ->groupBy('status');

        $marked = $byStatus->flatten(1)->count();
        $total = $employees->count();

        return [
            'date' => $date,
            'present' => ($byStatus->get(EmployeeAttendance::STATUS_PRESENT) ?? collect())->count(),
            'leave' => ($byStatus->get(EmployeeAttendance::STATUS_LEAVE) ?? collect())->count(),
            'sick' => ($byStatus->get(EmployeeAttendance::STATUS_SICK) ?? collect())->count(),
            'absent' => ($byStatus->get(EmployeeAttendance::STATUS_ABSENT) ?? collect())->count(),
            'unmarked' => max(0, $total - $marked),
            'total_employees' => $total,
        ];
    }

    /**
     * @return array{
     *   week_start:string,
     *   week_end:string,
     *   label:string,
     *   status:string,
     *   job_count:int,
     *   gross:float,
     *   tech_net:float,
     *   shop_share:float
     * }|null
     */
    protected function workshopWeekSummary(?int $branchId): ?array
    {
        $branches = Branch::query()
            ->with('branchType')
            ->when($branchId, fn ($q) => $q->where('id', $branchId))
            ->get()
            ->filter(fn (Branch $b) => $b->isWorkshop())
            ->values();

        if ($branches->isEmpty()) {
            return null;
        }

        $start = now()->startOfWeek(Carbon::MONDAY)->toDateString();
        $end = now()->endOfWeek(Carbon::SUNDAY)->toDateString();
        $year = (int) now()->year;
        $month = (int) now()->month;

        $jobCount = 0;
        $gross = 0.0;
        $techNet = 0.0;
        $statuses = [];

        foreach ($branches as $branch) {
            $pctMap = WorkshopWageSetting::query()
                ->where('branch_id', $branch->id)
                ->where('year', $year)
                ->where('month', $month)
                ->pluck('tech_share_pct', 'employee_id')
                ->map(fn ($v) => (float) $v)
                ->all();

            $jobs = WorkshopJob::query()
                ->where('branch_id', $branch->id)
                ->whereBetween('job_date', [$start, $end])
                ->get(['employee_id', 'amount']);

            $jobCount += $jobs->count();

            foreach ($jobs->groupBy('employee_id') as $employeeId => $group) {
                $empGross = (float) $group->sum('amount');
                $pct = $pctMap[(int) $employeeId] ?? WorkshopWageSetting::DEFAULT_TECH_SHARE_PCT;
                $gross += $empGross;
                $techNet += $empGross * ($pct / 100);
            }

            $week = WorkshopWeek::query()
                ->where('branch_id', $branch->id)
                ->whereDate('week_start', $start)
                ->first();
            $statuses[] = $week?->status ?? WorkshopWeek::STATUS_OPEN;
        }

        $status = in_array(WorkshopWeek::STATUS_OPEN, $statuses, true)
            ? WorkshopWeek::STATUS_OPEN
            : WorkshopWeek::STATUS_PAID;

        $gross = round($gross, 2);
        $techNet = round($techNet, 2);

        return [
            'week_start' => $start,
            'week_end' => $end,
            'label' => 'Senin '.Carbon::parse($start)->format('d/m').' – Minggu '.Carbon::parse($end)->format('d/m/Y'),
            'status' => $status,
            'job_count' => $jobCount,
            'gross' => $gross,
            'tech_net' => $techNet,
            'shop_share' => round($gross - $techNet, 2),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $accounts
     * @return array{checked_today:int,total_accounts:int,unchecked:int,stale_accounts:int}
     */
    protected function reconStatusSummary(array $accounts): array
    {
        $today = now()->toDateString();
        $checkedToday = 0;
        $stale = 0;

        foreach ($accounts as $a) {
            $last = isset($a['terakhir_dicek']) ? (string) $a['terakhir_dicek'] : null;
            if ($last && substr($last, 0, 10) === $today) {
                $checkedToday++;
            } elseif (! $last || Carbon::parse($last)->lt(now()->subDays(2)->startOfDay())) {
                $stale++;
            }
        }

        $total = count($accounts);

        return [
            'checked_today' => $checkedToday,
            'total_accounts' => $total,
            'unchecked' => max(0, $total - $checkedToday),
            'stale_accounts' => $stale,
        ];
    }

    /**
     * @param  array{
     *   pemasukan:list<array{category_id:int,nama:string,jumlah:int,total:float}>,
     *   pengeluaran:list<array{category_id:int,nama:string,jumlah:int,total:float}>,
     *   total_pemasukan:float,
     *   total_pengeluaran:float,
     *   selisih:float
     * }  $kategori
     * @return array{
     *   pemasukan:list<array{category_id:int,nama:string,jumlah:int,total:float}>,
     *   pengeluaran:list<array{category_id:int,nama:string,jumlah:int,total:float}>,
     *   total_pemasukan:float,
     *   total_pengeluaran:float,
     *   selisih:float
     * }
     */
    protected function topCategories(array $kategori, int $limit = 5): array
    {
        $pemasukan = collect($kategori['pemasukan'] ?? [])
            ->sortByDesc('total')
            ->take($limit)
            ->values()
            ->all();
        $pengeluaran = collect($kategori['pengeluaran'] ?? [])
            ->sortByDesc('total')
            ->take($limit)
            ->values()
            ->all();

        return [
            'pemasukan' => $pemasukan,
            'pengeluaran' => $pengeluaran,
            'total_pemasukan' => (float) ($kategori['total_pemasukan'] ?? 0),
            'total_pengeluaran' => (float) ($kategori['total_pengeluaran'] ?? 0),
            'selisih' => (float) ($kategori['selisih'] ?? 0),
        ];
    }

    protected function pctChange(float $current, float $previous): ?float
    {
        if (abs($previous) < 0.009) {
            return abs($current) < 0.009 ? 0.0 : null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }
}
