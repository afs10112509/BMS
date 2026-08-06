<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeDailyClosing;
use App\Models\EmployeeMonthlyTarget;
use App\Models\InterBranchTransfer;
use App\Models\Payroll;
use App\Models\Reconciliation;
use App\Models\ServiceRecord;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WorkshopJob;
use App\Models\WorkshopWageSetting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ReportBuilder
{
    /**
     * @return array{branch_id:?int,date_from:string,date_to:string,type:?string,category_id:?int,account_id:?int,q:?string}
     */
    public function normalizeFilters(User $user, array $input): array
    {
        $dateFrom = $input['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $input['date_to'] ?? now()->toDateString();

        if ($dateFrom > $dateTo) {
            throw ValidationException::withMessages([
                'date_from' => 'Tanggal mulai tidak boleh setelah tanggal akhir.',
            ]);
        }

        $branchId = null;
        if ($user->isAdmin()) {
            $branchId = (int) $user->branch_id;
        } elseif (! empty($input['branch_id'])) {
            $branchId = (int) $input['branch_id'];
        }

        $type = $input['type'] ?? null;
        if ($type !== null && $type !== '' && ! in_array($type, ['income', 'expense'], true)) {
            $type = null;
        }

        return [
            'branch_id' => $branchId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'type' => $type ?: null,
            'category_id' => ! empty($input['category_id']) ? (int) $input['category_id'] : null,
            'account_id' => ! empty($input['account_id']) ? (int) $input['account_id'] : null,
            'q' => isset($input['q']) ? trim((string) $input['q']) : null,
        ];
    }

    public function meta(User $user, array $filters, string $reportType): array
    {
        $branchName = 'Semua Cabang';
        if ($filters['branch_id']) {
            $branchName = Branch::query()->where('id', $filters['branch_id'])->value('name') ?: '-';
        }

        $tipeLabel = 'Semua';
        if (($filters['type'] ?? null) === 'income') {
            $tipeLabel = 'Pemasukan';
        } elseif (($filters['type'] ?? null) === 'expense') {
            $tipeLabel = 'Pengeluaran';
        }

        $kategoriLabel = 'Semua kategori';
        if (! empty($filters['category_id'])) {
            $kategoriLabel = Category::query()->where('id', $filters['category_id'])->value('name') ?: '-';
        }

        $akunLabel = 'Semua akun';
        if (! empty($filters['account_id'])) {
            $akunLabel = Account::query()->where('id', $filters['account_id'])->value('name') ?: '-';
        }

        return [
            'jenis' => $reportType,
            'judul' => $this->title($reportType),
            'cabang' => $branchName,
            'periode' => $this->formatDateId($filters['date_from']).' s/d '.$this->formatDateId($filters['date_to']),
            'periode_raw' => $filters['date_from'].' s/d '.$filters['date_to'],
            'tipe' => $tipeLabel,
            'kategori' => $kategoriLabel,
            'akun' => $akunLabel,
            'pencarian' => ! empty($filters['q']) ? $filters['q'] : '-',
            'dibuat_oleh' => $user->name,
            'dibuat_pada' => now()->timezone(config('app.timezone'))->format('d/m/Y H:i'),
        ];
    }

    protected function formatDateId(string $date): string
    {
        try {
            return \Carbon\Carbon::parse($date)->locale('id')->translatedFormat('d M Y');
        } catch (\Throwable) {
            return $date;
        }
    }

    public function title(string $reportType): string
    {
        return match ($reportType) {
            'ringkasan' => 'Laporan Ringkasan Periode',
            'kategori' => 'Laporan Per Kategori',
            'akun' => 'Laporan Saldo per Akun',
            'transaksi' => 'Laporan Detail Transaksi',
            'transfer' => 'Laporan Transfer Antar Cabang',
            'servis' => 'Laporan Catatan Servis',
            'absensi' => 'Laporan Absensi',
            'gaji' => 'Laporan Gaji Konter',
            'upah' => 'Laporan Upah Kerja Bengkel',
            'closing' => 'Laporan Target Closingan',
            'rekonsiliasi' => 'Laporan Rekonsiliasi',
            default => 'Laporan',
        };
    }

    public function build(string $reportType, array $filters): array
    {
        return match ($reportType) {
            'ringkasan' => $this->ringkasan($filters),
            'kategori' => $this->kategori($filters),
            'akun' => $this->akun($filters),
            'transaksi' => $this->transaksi($filters),
            'transfer' => $this->transfer($filters),
            'servis' => $this->servis($filters),
            'absensi' => $this->absensi($filters),
            'gaji' => $this->gaji($filters),
            'upah' => $this->upah($filters),
            'closing' => $this->closing($filters),
            'rekonsiliasi' => $this->rekonsiliasi($filters),
            default => throw ValidationException::withMessages([
                'type' => 'Jenis laporan tidak dikenal.',
            ]),
        };
    }

    public function ringkasan(array $filters): array
    {
        $base = $this->transactionQuery($filters);

        $income = (clone $base)->where('categories.type', 'income')->sum('transactions.amount');
        $expense = (clone $base)->where('categories.type', 'expense')->sum('transactions.amount');
        $incomeCount = (clone $base)->where('categories.type', 'income')->count();
        $expenseCount = (clone $base)->where('categories.type', 'expense')->count();

        $byDay = (clone $base)
            ->selectRaw('transactions.transaction_date, categories.type, SUM(transactions.amount) as total')
            ->groupBy('transactions.transaction_date', 'categories.type')
            ->orderBy('transactions.transaction_date')
            ->get()
            ->groupBy(fn ($r) => (string) $r->transaction_date)
            ->map(function (Collection $rows, $date) {
                $income = (float) ($rows->firstWhere('type', 'income')->total ?? 0);
                $expense = (float) ($rows->firstWhere('type', 'expense')->total ?? 0);

                return [
                    'tanggal' => $date,
                    'pemasukan' => $income,
                    'pengeluaran' => $expense,
                    'selisih' => $income - $expense,
                ];
            })
            ->values()
            ->all();

        return [
            'ringkasan' => [
                'pemasukan' => (float) $income,
                'pengeluaran' => (float) $expense,
                'selisih' => (float) $income - (float) $expense,
                'jumlah_pemasukan' => $incomeCount,
                'jumlah_pengeluaran' => $expenseCount,
            ],
            'harian' => $byDay,
        ];
    }

    public function kategori(array $filters): array
    {
        $rows = $this->transactionQuery($filters)
            ->selectRaw('categories.id, categories.name, categories.type, COUNT(*) as jumlah, SUM(transactions.amount) as total')
            ->groupBy('categories.id', 'categories.name', 'categories.type')
            ->orderBy('categories.type')
            ->orderBy('categories.name')
            ->get()
            ->map(fn ($r) => [
                'category_id' => $r->id,
                'nama' => $r->name,
                'tipe' => $r->type,
                'jumlah' => (int) $r->jumlah,
                'total' => (float) $r->total,
            ])
            ->all();

        return [
            'rows' => $rows,
            'total_pemasukan' => collect($rows)->where('tipe', 'income')->sum('total'),
            'total_pengeluaran' => collect($rows)->where('tipe', 'expense')->sum('total'),
        ];
    }

    public function akun(array $filters): array
    {
        $calculator = app(BranchBalanceCalculator::class);
        $asOf = $filters['date_to'];

        if ($filters['branch_id']) {
            $rows = $calculator->balancesByAccount($filters['branch_id'], $asOf);
            if ($filters['account_id']) {
                $rows = array_values(array_filter(
                    $rows,
                    fn ($r) => (int) $r['account_id'] === (int) $filters['account_id']
                ));
            }

            return [
                'mode' => 'branch',
                'rows' => $rows,
                'total_saldo' => collect($rows)->sum('saldo'),
            ];
        }

        $branches = Branch::query()->orderBy('name')->get();
        $matrix = [];
        foreach ($branches as $branch) {
            $accounts = $calculator->balancesByAccount($branch->id, $asOf);
            if ($filters['account_id']) {
                $accounts = array_values(array_filter(
                    $accounts,
                    fn ($r) => (int) $r['account_id'] === (int) $filters['account_id']
                ));
            }
            $matrix[] = [
                'branch_id' => $branch->id,
                'nama_cabang' => $branch->name,
                'akun' => $accounts,
                'total_saldo' => collect($accounts)->sum('saldo'),
            ];
        }

        return [
            'mode' => 'all',
            'rows' => $matrix,
            'total_saldo' => collect($matrix)->sum('total_saldo'),
        ];
    }

    public function transaksi(array $filters): array
    {
        $ids = $this->transactionQuery($filters)
            ->orderBy('transactions.transaction_date')
            ->orderBy('transactions.id')
            ->pluck('transactions.id');

        $rows = Transaction::query()
            ->with(['category', 'branch.branchType', 'account', 'user:id,name'])
            ->whereIn('id', $ids)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get()
            ->map(fn (Transaction $t) => [
                'id' => $t->id,
                'tanggal' => (string) $t->transaction_date,
                'cabang' => $t->branch?->name,
                'kategori' => $t->category?->name,
                'tipe' => $t->category?->type,
                'akun' => $t->account?->name,
                'nominal' => (float) $t->amount,
                'keterangan' => $t->description,
                'input_oleh' => $t->user?->name,
            ])
            ->all();

        $income = collect($rows)->where('tipe', 'income')->sum('nominal');
        $expense = collect($rows)->where('tipe', 'expense')->sum('nominal');

        return [
            'rows' => $rows,
            'jumlah' => count($rows),
            'total_pemasukan' => $income,
            'total_pengeluaran' => $expense,
            'selisih' => $income - $expense,
        ];
    }

    public function transfer(array $filters): array
    {
        $query = InterBranchTransfer::query()
            ->with([
                'fromBranch.branchType',
                'toBranch.branchType',
                'account',
                'requester:id,name',
                'approver:id,name',
            ])
            ->whereDate('created_at', '>=', $filters['date_from'])
            ->whereDate('created_at', '<=', $filters['date_to'])
            ->latest('id');

        if ($filters['branch_id']) {
            $branchId = $filters['branch_id'];
            $query->where(function ($q) use ($branchId) {
                $q->where('from_branch_id', $branchId)->orWhere('to_branch_id', $branchId);
            });
        }

        if ($filters['account_id']) {
            $query->where('account_id', $filters['account_id']);
        }

        $rows = $query->get()->map(fn (InterBranchTransfer $t) => [
            'id' => $t->id,
            'tanggal' => optional($t->created_at)?->toDateString(),
            'dari' => $t->fromBranch?->name,
            'ke' => $t->toBranch?->name,
            'akun' => $t->account?->name,
            'nominal' => (float) $t->amount,
            'status' => $t->status,
            'pemohon' => $t->requester?->name,
            'penyetuju' => $t->approver?->name,
            'alasan' => $t->reason,
        ])->all();

        return [
            'rows' => $rows,
            'jumlah' => count($rows),
            'total_nominal' => collect($rows)->sum('nominal'),
            'approved' => collect($rows)->where('status', 'approved')->count(),
            'pending' => collect($rows)->where('status', 'pending')->count(),
            'rejected' => collect($rows)->where('status', 'rejected')->count(),
        ];
    }

    public function servis(array $filters): array
    {
        $query = ServiceRecord::query()
            ->with(['branch:id,name', 'employee:id,name'])
            ->whereDate('service_date', '>=', $filters['date_from'])
            ->whereDate('service_date', '<=', $filters['date_to'])
            ->orderBy('service_date')
            ->orderBy('id');

        if ($filters['branch_id']) {
            $query->where('branch_id', $filters['branch_id']);
        }

        $rows = $query->get()->map(fn (ServiceRecord $r) => [
            'id' => $r->id,
            'tanggal' => $r->service_date?->toDateString(),
            'cabang' => $r->branch?->name,
            'teknisi' => $r->employee?->name,
            'merek' => $r->brand,
            'tipe' => $r->device_type,
            'kerusakan' => $r->damage,
            'modal' => (float) $r->cost,
            'harga' => (float) $r->price,
            'profit' => (float) $r->profit,
        ])->all();

        return [
            'rows' => $rows,
            'jumlah' => count($rows),
            'total_modal' => collect($rows)->sum('modal'),
            'total_harga' => collect($rows)->sum('harga'),
            'total_profit' => collect($rows)->sum('profit'),
        ];
    }

    public function absensi(array $filters): array
    {
        $employees = Employee::query()
            ->with('branch:id,name')
            ->where('status', 'active')
            ->when($filters['branch_id'], fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->orderBy('name')
            ->get();

        $atts = EmployeeAttendance::query()
            ->whereIn('employee_id', $employees->pluck('id'))
            ->whereDate('attendance_date', '>=', $filters['date_from'])
            ->whereDate('attendance_date', '<=', $filters['date_to'])
            ->get()
            ->groupBy('employee_id');

        $rows = $employees->map(function (Employee $emp) use ($atts) {
            $group = $atts->get($emp->id, collect());

            return [
                'employee_id' => $emp->id,
                'nama' => $emp->name,
                'cabang' => $emp->branch?->name,
                'hadir' => $group->where('status', EmployeeAttendance::STATUS_PRESENT)->count(),
                'izin' => $group->where('status', EmployeeAttendance::STATUS_LEAVE)->count(),
                'sakit' => $group->where('status', EmployeeAttendance::STATUS_SICK)->count(),
                'alpha' => $group->where('status', EmployeeAttendance::STATUS_ABSENT)->count(),
                'total' => $group->count(),
            ];
        })->values()->all();

        return [
            'rows' => $rows,
            'jumlah_karyawan' => count($rows),
            'total_hadir' => collect($rows)->sum('hadir'),
            'total_izin' => collect($rows)->sum('izin'),
            'total_sakit' => collect($rows)->sum('sakit'),
            'total_alpha' => collect($rows)->sum('alpha'),
        ];
    }

    public function gaji(array $filters): array
    {
        $from = Carbon::parse($filters['date_from']);
        $year = (int) $from->year;
        $month = (int) $from->month;

        $query = Payroll::query()
            ->with(['employee:id,name,position', 'branch:id,name'])
            ->where('year', $year)
            ->where('month', $month)
            ->orderBy('branch_id')
            ->orderBy('employee_id');

        if ($filters['branch_id']) {
            $query->where('branch_id', $filters['branch_id']);
        }

        $rows = $query->get()->map(fn (Payroll $p) => [
            'id' => $p->id,
            'cabang' => $p->branch?->name,
            'karyawan' => $p->employee?->name,
            'posisi' => $p->position_snapshot ?: $p->employee?->position,
            'status' => $p->status,
            'hadir' => (int) $p->present_days,
            'gapok' => (float) $p->gapok,
            'closing' => (int) $p->closing_qty,
            'insentif_hp' => (float) $p->insentif_hp,
            'insentif_service' => (float) $p->service_incentive,
            'acc' => (float) $p->insentif_acc,
            'bonus' => (float) $p->bonus_absen,
            'hutang' => (float) $p->hutang,
            'pengeluaran' => (float) $p->pengeluaran,
            'total' => (float) $p->total,
        ])->all();

        return [
            'year' => $year,
            'month' => $month,
            'periode_label' => $from->locale('id')->translatedFormat('F Y'),
            'rows' => $rows,
            'jumlah' => count($rows),
            'total_gaji' => collect($rows)->sum('total'),
            'draft' => collect($rows)->where('status', Payroll::STATUS_DRAFT)->count(),
            'locked' => collect($rows)->where('status', Payroll::STATUS_LOCKED)->count(),
        ];
    }

    public function upah(array $filters): array
    {
        $query = WorkshopJob::query()
            ->with(['employee:id,name', 'branch:id,name'])
            ->whereDate('job_date', '>=', $filters['date_from'])
            ->whereDate('job_date', '<=', $filters['date_to'])
            ->orderBy('job_date')
            ->orderBy('id');

        if ($filters['branch_id']) {
            $query->where('branch_id', $filters['branch_id']);
        } else {
            $workshopIds = Branch::query()
                ->with('branchType')
                ->get()
                ->filter(fn (Branch $b) => $b->isWorkshop())
                ->pluck('id');
            $query->whereIn('branch_id', $workshopIds);
        }

        $jobs = $query->get();
        $year = (int) Carbon::parse($filters['date_from'])->year;
        $month = (int) Carbon::parse($filters['date_from'])->month;

        $pctByBranch = [];
        foreach ($jobs->pluck('branch_id')->unique() as $bid) {
            $pctByBranch[(int) $bid] = WorkshopWageSetting::query()
                ->where('branch_id', $bid)
                ->where('year', $year)
                ->where('month', $month)
                ->pluck('tech_share_pct', 'employee_id')
                ->map(fn ($v) => (float) $v)
                ->all();
        }

        $rows = $jobs->map(function (WorkshopJob $j) use ($pctByBranch) {
            $map = $pctByBranch[(int) $j->branch_id] ?? [];
            $pct = $map[(int) $j->employee_id] ?? WorkshopWageSetting::DEFAULT_TECH_SHARE_PCT;
            $gross = (float) $j->amount;
            $net = round($gross * ($pct / 100), 2);

            return [
                'id' => $j->id,
                'tanggal' => $j->job_date?->toDateString(),
                'cabang' => $j->branch?->name,
                'teknisi' => $j->employee?->name,
                'jenis' => $j->job_type,
                'gross' => $gross,
                'pct' => $pct,
                'net' => $net,
                'keterangan' => $j->note,
            ];
        })->all();

        $gross = collect($rows)->sum('gross');
        $net = collect($rows)->sum('net');

        return [
            'rows' => $rows,
            'jumlah' => count($rows),
            'total_gross' => $gross,
            'total_net' => $net,
            'total_shop' => round($gross - $net, 2),
        ];
    }

    public function closing(array $filters): array
    {
        $from = Carbon::parse($filters['date_from']);
        $year = (int) $from->year;
        $month = (int) $from->month;
        $monthStart = $from->copy()->startOfMonth()->toDateString();
        $monthEnd = $from->copy()->endOfMonth()->toDateString();

        $employees = Employee::query()
            ->with('branch:id,name')
            ->where('status', 'active')
            ->when($filters['branch_id'], fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(! $filters['branch_id'], function ($q) {
                $konterIds = Branch::query()
                    ->with('branchType')
                    ->get()
                    ->filter(fn (Branch $b) => ! $b->isWorkshop())
                    ->pluck('id');
                $q->whereIn('branch_id', $konterIds);
            })
            ->withoutManagement()
            ->orderBy('name')
            ->get();

        $closings = EmployeeDailyClosing::query()
            ->whereIn('employee_id', $employees->pluck('id'))
            ->whereDate('closing_date', '>=', $monthStart)
            ->whereDate('closing_date', '<=', $monthEnd)
            ->get()
            ->groupBy('employee_id');

        $targets = EmployeeMonthlyTarget::query()
            ->whereIn('employee_id', $employees->pluck('id'))
            ->where('year', $year)
            ->where('month', $month)
            ->pluck('target', 'employee_id');

        $rows = $employees->map(function (Employee $emp) use ($closings, $targets) {
            $qty = (int) ($closings->get($emp->id, collect())->sum('qty'));
            $target = (int) ($targets[$emp->id] ?? 0);
            $pct = $target > 0 ? round(($qty / $target) * 100, 1) : null;

            return [
                'employee_id' => $emp->id,
                'nama' => $emp->name,
                'cabang' => $emp->branch?->name,
                'qty' => $qty,
                'target' => $target,
                'pct' => $pct,
                'selisih' => $qty - $target,
            ];
        })->values()->all();

        $totalQty = collect($rows)->sum('qty');
        $totalTarget = collect($rows)->sum('target');

        return [
            'year' => $year,
            'month' => $month,
            'periode_label' => $from->locale('id')->translatedFormat('F Y'),
            'rows' => $rows,
            'total_qty' => $totalQty,
            'total_target' => $totalTarget,
            'pct' => $totalTarget > 0 ? round(($totalQty / $totalTarget) * 100, 1) : null,
        ];
    }

    public function rekonsiliasi(array $filters): array
    {
        $query = Reconciliation::query()
            ->with(['branch:id,name', 'account:id,name,code', 'user:id,name'])
            ->whereDate('reconciliation_date', '>=', $filters['date_from'])
            ->whereDate('reconciliation_date', '<=', $filters['date_to'])
            ->orderBy('reconciliation_date')
            ->orderBy('id');

        if ($filters['branch_id']) {
            $query->where('branch_id', $filters['branch_id']);
        }

        if ($filters['account_id']) {
            $query->where('account_id', $filters['account_id']);
        }

        $rows = $query->get()->map(fn (Reconciliation $r) => [
            'id' => $r->id,
            'tanggal' => $r->reconciliation_date?->toDateString(),
            'cabang' => $r->branch?->name,
            'akun' => $r->account?->name,
            'sistem' => (float) $r->system_balance,
            'fisik' => (float) $r->physical_balance,
            'selisih' => (float) $r->difference,
            'oleh' => $r->user?->name,
        ])->all();

        return [
            'rows' => $rows,
            'jumlah' => count($rows),
            'total_selisih' => collect($rows)->sum('selisih'),
            'ada_selisih' => collect($rows)->filter(fn ($r) => abs($r['selisih']) >= 0.01)->count(),
        ];
    }

    protected function transactionQuery(array $filters): Builder
    {
        $query = Transaction::query()
            ->join('categories', 'categories.id', '=', 'transactions.category_id')
            ->whereDate('transactions.transaction_date', '>=', $filters['date_from'])
            ->whereDate('transactions.transaction_date', '<=', $filters['date_to']);

        if ($filters['branch_id']) {
            $query->where('transactions.branch_id', $filters['branch_id']);
        }

        if ($filters['type']) {
            $query->where('categories.type', $filters['type']);
        }

        if ($filters['category_id']) {
            $query->where('transactions.category_id', $filters['category_id']);
        }

        if ($filters['account_id']) {
            $query->where('transactions.account_id', $filters['account_id']);
        }

        if (! empty($filters['q'])) {
            $q = $filters['q'];
            $query->where(function ($builder) use ($q) {
                $builder->where('transactions.description', 'ilike', "%{$q}%");
                $digits = preg_replace('/[^\d]/', '', $q);
                if ($digits !== '') {
                    $builder->orWhereRaw('CAST(transactions.amount AS TEXT) LIKE ?', ["%{$digits}%"]);
                }
            });
        }

        return $query;
    }
}
