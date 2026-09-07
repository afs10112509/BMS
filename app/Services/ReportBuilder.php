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
use App\Models\CashflowWorkbook;
use App\Models\CashflowWorkbookLine;
use App\Models\ProfitShare;
use App\Models\ProfitShareLine;
use App\Models\BrilinkDailySheet;
use App\Models\PulsaDailySheet;
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
        } elseif (method_exists($user, 'isEmployee') && $user->isEmployee()) {
            // Karyawan (PIC) hanya cabangnya sendiri.
            $branchId = method_exists($user, 'employeeBranchId')
                ? $user->employeeBranchId()
                : ($user->branch_id ? (int) $user->branch_id : null);
            if (! $branchId) {
                throw ValidationException::withMessages([
                    'branch_id' => 'Akun karyawan tidak terhubung ke cabang.',
                ]);
            }
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
            'employee_id' => ! empty($input['employee_id']) ? (int) $input['employee_id'] : null,
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

        $teknisiLabel = 'Semua teknisi';
        if (! empty($filters['employee_id'])) {
            $teknisiLabel = Employee::query()->where('id', $filters['employee_id'])->value('name') ?: '-';
        }

        $periode = $this->formatDateId($filters['date_from']).' s/d '.$this->formatDateId($filters['date_to']);
        $periodeRaw = $filters['date_from'].' s/d '.$filters['date_to'];
        if ($reportType === 'bagi-hasil') {
            $bulan = Carbon::parse($filters['date_from'])->locale('id');
            $periode = $bulan->translatedFormat('F Y');
            $periodeRaw = $bulan->format('Y-m');
        }

        return [
            'jenis' => $reportType,
            'judul' => $this->title($reportType),
            'cabang' => $branchName,
            'periode' => $periode,
            'periode_raw' => $periodeRaw,
            'tipe' => $tipeLabel,
            'kategori' => $kategoriLabel,
            'akun' => $akunLabel,
            'teknisi' => $teknisiLabel,
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
            'bagi-hasil' => 'Laporan Bagi Hasil',
            'closing' => 'Laporan Closing Harian & Target',
            'rekonsiliasi' => 'Laporan Rekonsiliasi',
            'alur-kas' => 'Laporan Alur Kas',
            'keuntungan-pulsa' => 'Laporan Keuntungan Pulsa',
            'brilink' => 'Laporan Brilink',
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
            'bagi-hasil' => $this->bagiHasil($filters),
            'closing' => $this->closing($filters),
            'rekonsiliasi' => $this->rekonsiliasi($filters),
            'alur-kas' => $this->alurKas($filters),
            'keuntungan-pulsa' => $this->keuntunganPulsa($filters),
            'brilink' => $this->brilink($filters),
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
            ->selectRaw('DATE(transactions.transaction_date) as day, categories.type, SUM(transactions.amount) as total')
            ->groupByRaw('DATE(transactions.transaction_date), categories.type')
            ->orderByDesc('day')
            ->get()
            ->groupBy(fn ($r) => (string) $r->day)
            ->map(function (Collection $rows, $date) {
                $income = (float) ($rows->firstWhere('type', 'income')->total ?? 0);
                $expense = (float) ($rows->firstWhere('type', 'expense')->total ?? 0);
                $day = substr((string) $date, 0, 10);

                return [
                    'tanggal' => $day,
                    'pemasukan' => $income,
                    'pengeluaran' => $expense,
                    'selisih' => $income - $expense,
                ];
            })
            // Terbaru di atas (groupBy bisa mengacak urutan di beberapa driver).
            ->sortByDesc('tanggal')
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
        $isDetail = ! empty($filters['category_id']);
        $summaryRows = $this->kategoriSummary($filters);

        $groups = [];
        if ($isDetail) {
            foreach ($this->filteredTransactions($filters) as $t) {
                $catId = (int) $t->category_id;
                if (! isset($groups[$catId])) {
                    $groups[$catId] = [
                        'category_id' => $catId,
                        'nama' => $t->category?->name,
                        'tipe' => $t->category?->type,
                        'jumlah' => 0,
                        'total' => 0.0,
                        'rows' => [],
                    ];
                }
                $groups[$catId]['rows'][] = [
                    'id' => $t->id,
                    'tanggal' => $t->transaction_date?->toDateString() ?? (string) $t->transaction_date,
                    'cabang' => $t->branch?->name,
                    'akun' => $t->account?->name,
                    'nominal' => (float) $t->amount,
                    'keterangan' => $t->description,
                    'input_oleh' => $t->user?->name,
                ];
                $groups[$catId]['jumlah']++;
                $groups[$catId]['total'] += (float) $t->amount;
            }
            $groups = array_values($groups);
        } else {
            $groups = array_map(static fn (array $row) => $row + ['rows' => []], $summaryRows);
        }

        return [
            'mode' => $isDetail ? 'detail' : 'summary',
            'categories' => $summaryRows,
            'groups' => $groups,
            'rows' => $summaryRows,
            'jumlah' => (int) collect($summaryRows)->sum('jumlah'),
            'total_pemasukan' => (float) collect($summaryRows)->where('tipe', 'income')->sum('total'),
            'total_pengeluaran' => (float) collect($summaryRows)->where('tipe', 'expense')->sum('total'),
        ];
    }

    public function alurKas(array $filters): array
    {
        $from = Carbon::parse($filters['date_from']);
        $to = Carbon::parse($filters['date_to']);

        // Satu bulan + ada snapshot tersimpan → pakai workbook (termasuk edit manual & bagian toko).
        if ($from->format('Y-m') === $to->format('Y-m')) {
            $year = (int) $from->year;
            $month = (int) $from->month;
            $workbooks = CashflowWorkbook::query()
                ->with('lines')
                ->where('year', $year)
                ->where('month', $month)
                ->when($filters['branch_id'], fn ($q) => $q->where('branch_id', $filters['branch_id']))
                ->get();

            if ($workbooks->isNotEmpty()) {
                $incomeMap = [];
                $expenseMap = [];
                foreach ($workbooks as $wb) {
                    foreach ($wb->lines as $line) {
                        $key = mb_strtolower(trim((string) $line->name));
                        if (($line->type ?? '') === CashflowWorkbookLine::TYPE_INCOME) {
                            if (! isset($incomeMap[$key])) {
                                $incomeMap[$key] = [
                                    'category_id' => $line->category_id ? (int) $line->category_id : 0,
                                    'nama' => $line->name,
                                    'jumlah' => 0,
                                    'total' => 0.0,
                                ];
                            }
                            $incomeMap[$key]['total'] += (float) $line->amount;
                            $incomeMap[$key]['jumlah']++;
                        } else {
                            if (! isset($expenseMap[$key])) {
                                $expenseMap[$key] = [
                                    'category_id' => $line->category_id ? (int) $line->category_id : 0,
                                    'nama' => $line->name,
                                    'jumlah' => 0,
                                    'total' => 0.0,
                                ];
                            }
                            $expenseMap[$key]['total'] += (float) $line->amount;
                            $expenseMap[$key]['jumlah']++;
                        }
                    }
                }

                $pemasukan = array_values(array_map(function (array $r) {
                    $r['total'] = round($r['total'], 2);

                    return $r;
                }, $incomeMap));
                $pengeluaran = array_values(array_map(function (array $r) {
                    $r['total'] = round($r['total'], 2);

                    return $r;
                }, $expenseMap));
                $totalIn = (float) collect($pemasukan)->sum('total');
                $totalOut = (float) collect($pengeluaran)->sum('total');

                return [
                    'has_data' => ($totalIn + $totalOut) > 0 || count($pemasukan) + count($pengeluaran) > 0,
                    'tx_count' => 0,
                    'source' => 'workbook',
                    'pemasukan' => $pemasukan,
                    'pengeluaran' => $pengeluaran,
                    'total_pemasukan' => $totalIn,
                    'total_pengeluaran' => $totalOut,
                    'selisih' => round($totalIn - $totalOut, 2),
                ];
            }
        }

        $calculator = app(BranchBalanceCalculator::class);
        $totals = $calculator->totalsByCategory(
            $filters['branch_id'],
            $filters['date_from'],
            $filters['date_to'],
        );

        $txCount = Transaction::query()
            ->join('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('categories.name', 'not like', 'Transfer%')
            ->whereDate('transactions.transaction_date', '>=', $filters['date_from'])
            ->whereDate('transactions.transaction_date', '<=', $filters['date_to'])
            ->when($filters['branch_id'], fn ($q) => $q->where('transactions.branch_id', $filters['branch_id']))
            ->count();

        return [
            'has_data' => $txCount > 0,
            'tx_count' => $txCount,
            'source' => 'transactions',
            'pemasukan' => $totals['pemasukan'],
            'pengeluaran' => $totals['pengeluaran'],
            'total_pemasukan' => $totals['total_pemasukan'],
            'total_pengeluaran' => $totals['total_pengeluaran'],
            'selisih' => $totals['selisih'],
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
        $rows = $this->filteredTransactions($filters)
            ->map(fn (Transaction $t) => [
                'id' => $t->id,
                'tanggal' => $t->transaction_date?->toDateString() ?? (string) $t->transaction_date,
                'cabang' => $t->branch?->name,
                'category_id' => (int) $t->category_id,
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
            ->orderByDesc('created_at')
            ->orderByDesc('id');

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
            ->orderByDesc('service_date')
            ->orderByDesc('id');

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
            ->whereHas('branch.branchType', fn ($q) => $q->where('allows_service', true))
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
            'insentif_pic' => (float) ($p->insentif_pic ?? 0),
            'closing' => (int) $p->closing_qty,
            'insentif_hp' => (float) $p->insentif_hp,
            'insentif_service' => (float) $p->service_incentive,
            'acc' => (float) $p->insentif_acc,
            'bonus' => (float) $p->bonus_absen,
            'hutang' => (float) $p->hutang,
            'pengeluaran' => (float) $p->pengeluaran,
            'total' => (float) $p->total,
        ])->all();

        $collection = collect($rows);

        return [
            'year' => $year,
            'month' => $month,
            'periode_label' => $from->locale('id')->translatedFormat('F Y'),
            'rows' => $rows,
            'jumlah' => $collection->count(),
            'total_hadir' => (int) $collection->sum('hadir'),
            'total_gapok' => (float) $collection->sum('gapok'),
            'total_insentif_pic' => (float) $collection->sum('insentif_pic'),
            'total_insentif_hp' => (float) $collection->sum('insentif_hp'),
            'total_insentif_service' => (float) $collection->sum('insentif_service'),
            'total_acc' => (float) $collection->sum('acc'),
            'total_bonus' => (float) $collection->sum('bonus'),
            'total_hutang' => (float) $collection->sum('hutang'),
            'total_pengeluaran' => (float) $collection->sum('pengeluaran'),
            'total_gaji' => (float) $collection->sum('total'),
            'draft' => $collection->where('status', Payroll::STATUS_DRAFT)->count(),
            'locked' => $collection->where('status', Payroll::STATUS_LOCKED)->count(),
        ];
    }

    public function bagiHasil(array $filters): array
    {
        $from = Carbon::parse($filters['date_from'])->startOfMonth();
        $year = (int) $from->year;
        $month = (int) $from->month;

        $query = ProfitShare::query()
            ->with(['branch:id,name,type', 'lines'])
            ->where('year', $year)
            ->where('month', $month)
            ->orderBy('branch_id');

        if ($filters['branch_id']) {
            $query->where('branch_id', $filters['branch_id']);
        }

        $shares = $query->get();

        $rows = $shares->map(function (ProfitShare $share) {
            $periode = Carbon::create((int) $share->year, (int) $share->month, 1)
                ->locale('id')
                ->translatedFormat('F Y');

            $incomeLines = $share->lines
                ->where('type', ProfitShareLine::TYPE_INCOME)
                ->values()
                ->map(fn (ProfitShareLine $l) => [
                    'name' => $l->name,
                    'amount' => (float) $l->amount,
                ])->all();

            $expenseLines = $share->lines
                ->where('type', ProfitShareLine::TYPE_EXPENSE)
                ->values()
                ->map(fn (ProfitShareLine $l) => [
                    'name' => $l->name,
                    'amount' => (float) $l->amount,
                ])->all();

            return [
                'id' => $share->id,
                'branch_id' => (int) $share->branch_id,
                'cabang' => $share->branch?->name,
                'year' => (int) $share->year,
                'month' => (int) $share->month,
                'periode_label' => $periode,
                'status' => $share->status,
                'pic_name' => $share->pic_name,
                'pic_share_pct' => (float) $share->pic_share_pct,
                'total_income' => (float) $share->total_income,
                'total_expense' => (float) $share->total_expense,
                'net_profit' => (float) $share->net_profit,
                'pic_amount' => (float) $share->pic_amount,
                'note' => $share->note,
                'income_lines' => $incomeLines,
                'expense_lines' => $expenseLines,
            ];
        })->all();

        $periodeLabel = $from->copy()->locale('id')->translatedFormat('F Y');

        return [
            'periode_label' => $periodeLabel,
            'rows' => $rows,
            'jumlah' => count($rows),
            'total_income' => (float) collect($rows)->sum('total_income'),
            'total_expense' => (float) collect($rows)->sum('total_expense'),
            'total_net_profit' => (float) collect($rows)->sum('net_profit'),
            'total_pic_amount' => (float) collect($rows)->sum('pic_amount'),
            'draft' => collect($rows)->where('status', ProfitShare::STATUS_DRAFT)->count(),
            'locked' => collect($rows)->where('status', ProfitShare::STATUS_LOCKED)->count(),
        ];
    }

    public function upah(array $filters): array
    {
        $query = WorkshopJob::query()
            ->with(['employee:id,name', 'branch:id,name'])
            ->whereDate('job_date', '>=', $filters['date_from'])
            ->whereDate('job_date', '<=', $filters['date_to'])
            ->orderByDesc('job_date')
            ->orderByDesc('id');

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

        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }

        $jobs = $query->get();

        /** @var array<string, array<int, float>> $pctMaps key: branchId-year-month */
        $pctMaps = [];
        foreach ($jobs as $j) {
            if (! $j->job_date) {
                continue;
            }
            $bid = (int) $j->branch_id;
            $year = (int) $j->job_date->year;
            $month = (int) $j->job_date->month;
            $key = "{$bid}-{$year}-{$month}";
            if (isset($pctMaps[$key])) {
                continue;
            }
            $pctMaps[$key] = WorkshopWageSetting::query()
                ->where('branch_id', $bid)
                ->where('year', $year)
                ->where('month', $month)
                ->pluck('tech_share_pct', 'employee_id')
                ->map(fn ($v) => (float) $v)
                ->all();
        }

        $rows = $jobs->map(function (WorkshopJob $j) use ($pctMaps) {
            $year = (int) ($j->job_date?->year ?? 0);
            $month = (int) ($j->job_date?->month ?? 0);
            $key = ((int) $j->branch_id).'-'.$year.'-'.$month;
            $map = $pctMaps[$key] ?? [];
            $pct = $map[(int) $j->employee_id] ?? WorkshopWageSetting::DEFAULT_TECH_SHARE_PCT;
            $gross = (float) $j->amount;
            $net = round($gross * ($pct / 100), 2);

            return [
                'id' => $j->id,
                'employee_id' => (int) $j->employee_id,
                'tanggal' => $j->job_date?->toDateString(),
                'cabang' => $j->branch?->name,
                'teknisi' => $j->employee?->name,
                'jenis' => $j->job_type,
                'gross' => $gross,
                'pct' => $pct,
                'net' => $net,
                'toko' => round($gross - $net, 2),
                'keterangan' => $j->note,
            ];
        })->all();

        $byTeknisi = collect($rows)
            ->groupBy('employee_id')
            ->map(function (Collection $items) {
                $first = $items->first();
                $gross = (float) $items->sum('gross');
                $net = (float) $items->sum('net');
                $uniquePcts = $items->pluck('pct')->unique()->values();
                // Satu % jika konsisten di periode; jika campur pakai efektif dari upah/gross.
                $techSharePct = $uniquePcts->count() === 1
                    ? (float) $uniquePcts->first()
                    : ($gross > 0 ? round(($net / $gross) * 100, 2) : 0.0);
                $shopSharePct = round(100 - $techSharePct, 2);

                return [
                    'employee_id' => (int) ($first['employee_id'] ?? 0),
                    'teknisi' => $first['teknisi'] ?? '-',
                    'cabang' => $first['cabang'] ?? '-',
                    'jumlah_job' => $items->count(),
                    'total_gross' => $gross,
                    'tech_share_pct' => $techSharePct,
                    'shop_share_pct' => $shopSharePct,
                    'pct_mixed' => $uniquePcts->count() > 1,
                    'total_net' => $net,
                    'total_shop' => round($gross - $net, 2),
                ];
            })
            ->sortBy('teknisi', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $gross = (float) collect($rows)->sum('gross');
        $net = (float) collect($rows)->sum('net');

        return [
            'rows' => $rows,
            'by_teknisi' => $byTeknisi,
            'jumlah' => count($rows),
            'jumlah_teknisi' => count($byTeknisi),
            'total_gross' => $gross,
            'total_net' => $net,
            'total_shop' => round($gross - $net, 2),
            'employee_id' => $filters['employee_id'] ?? null,
        ];
    }

    public function closing(array $filters): array
    {
        $from = Carbon::parse($filters['date_from']);
        $year = (int) $from->year;
        $month = (int) $from->month;
        $monthStart = $from->copy()->startOfMonth()->toDateString();
        $monthEnd = $from->copy()->endOfMonth()->toDateString();

        $daysInMonth = (int) $from->daysInMonth;

        // Selaras board closing: PIC ikut tampil; hanya jabatan Owner disembunyikan.
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
            ->withoutOwner()
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

        $rows = $employees->map(function (Employee $emp) use ($closings, $targets, $daysInMonth) {
            $qty = (int) ($closings->get($emp->id, collect())->sum('qty'));
            $target = $targets->has($emp->id)
                ? (int) $targets[$emp->id]
                : $daysInMonth;
            $pct = $target > 0 ? round(($qty / $target) * 100, 1) : null;
            $tercapai = $target > 0 ? $qty >= $target : $qty > 0;

            return [
                'employee_id' => $emp->id,
                'nama' => $emp->name,
                'phone' => $emp->phone,
                'cabang' => $emp->branch?->name,
                'qty' => $qty,
                'target' => $target,
                'pct' => $pct,
                'selisih' => $qty - $target,
                'tercapai' => $tercapai,
                'status' => $tercapai ? 'tercapai' : 'belum',
                'status_label' => $tercapai ? 'Tercapai' : 'Belum tercapai',
            ];
        })->values()->all();

        $totalQty = collect($rows)->sum('qty');
        $totalTarget = collect($rows)->sum('target');
        $jumlahTercapai = collect($rows)->where('tercapai', true)->count();
        $jumlahBelum = collect($rows)->where('tercapai', false)->count();

        return [
            'year' => $year,
            'month' => $month,
            'periode_label' => $from->locale('id')->translatedFormat('F Y'),
            'rows' => $rows,
            'total_qty' => $totalQty,
            'total_target' => $totalTarget,
            'pct' => $totalTarget > 0 ? round(($totalQty / $totalTarget) * 100, 1) : null,
            'jumlah_tercapai' => $jumlahTercapai,
            'jumlah_belum' => $jumlahBelum,
            'jumlah_karyawan' => count($rows),
        ];
    }

    public function rekonsiliasi(array $filters): array
    {
        $query = Reconciliation::query()
            ->with(['branch:id,name', 'account:id,name,code', 'user:id,name'])
            ->whereDate('reconciliation_date', '>=', $filters['date_from'])
            ->whereDate('reconciliation_date', '<=', $filters['date_to'])
            ->orderByDesc('reconciliation_date')
            ->orderByDesc('id');

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

    /**
     * Rekap total per kategori (tanpa baris transaksi).
     * Pola query sama dengan laporan ringkasan agar GROUP BY aman di PostgreSQL.
     *
     * @return list<array{category_id:int,nama:?string,tipe:?string,jumlah:int,total:float}>
     */
    protected function kategoriSummary(array $filters): array
    {
        return $this->transactionQuery($filters)
            ->toBase()
            ->selectRaw(
                'transactions.category_id as category_id,
                 categories.name as nama,
                 categories.type as tipe,
                 COUNT(transactions.id) as trx_count,
                 COALESCE(SUM(transactions.amount), 0) as total'
            )
            ->groupBy('transactions.category_id', 'categories.name', 'categories.type')
            ->orderByRaw("CASE WHEN categories.type = 'income' THEN 0 ELSE 1 END")
            ->orderBy('categories.name')
            ->get()
            ->map(static fn ($row) => [
                'category_id' => (int) $row->category_id,
                'nama' => $row->nama,
                'tipe' => $row->tipe,
                'jumlah' => (int) $row->trx_count,
                'total' => (float) $row->total,
            ])
            ->values()
            ->all();
    }

    /**
     * Transaksi terfilter dengan kolom transactions.* (wajib setelah JOIN kategori,
     * agar id model tidak tertimpa categories.id).
     */
    protected function filteredTransactions(array $filters): Collection
    {
        return $this->transactionQuery($filters)
            ->with(['category', 'branch.branchType', 'account', 'user:id,name'])
            ->select('transactions.*')
            ->orderByDesc('transactions.transaction_date')
            ->orderByDesc('transactions.id')
            ->get();
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

    public function keuntunganPulsa(array $filters): array
    {
        $query = PulsaDailySheet::query()
            ->with([
                'branch:id,name',
                'balances',
                'expenses',
                'inputter:id,name',
            ])
            ->whereDate('sheet_date', '>=', $filters['date_from'])
            ->whereDate('sheet_date', '<=', $filters['date_to'])
            ->orderByDesc('sheet_date')
            ->orderByDesc('id');

        if ($filters['branch_id']) {
            $query->where('branch_id', $filters['branch_id']);
        } else {
            // Owner tanpa filter cabang: hanya cabang konter.
            $konterIds = Branch::query()
                ->with('branchType')
                ->get()
                ->filter(fn (Branch $b) => ! $b->isWorkshop())
                ->pluck('id')
                ->all();
            $query->whereIn('branch_id', $konterIds ?: [0]);
        }

        $rows = $query->get()->map(function (PulsaDailySheet $sheet) {
            return [
                'id' => $sheet->id,
                'tanggal' => $sheet->sheet_date?->toDateString() ?? (string) $sheet->sheet_date,
                'cabang' => $sheet->branch?->name,
                'uang_pulsa' => (float) $sheet->cash_on_hand,
                'saldo_terpotong' => (float) $sheet->total_used_balance,
                'pengeluaran' => (float) $sheet->total_expense,
                'total_uang' => (float) $sheet->total_cash,
                'keuntungan' => (float) $sheet->profit,
                'oleh' => $sheet->inputter?->name,
                'balances' => $sheet->balances->map(fn ($b) => [
                    'provider' => $b->provider_name,
                    'kemarin' => (float) $b->opening_balance,
                    'tambah' => (float) $b->topup_amount,
                    'sekarang' => (float) $b->closing_balance,
                    'terpakai' => (float) $b->used_amount,
                ])->values()->all(),
                'expenses' => $sheet->expenses->map(fn ($e) => [
                    'nama' => $e->name,
                    'nominal' => (float) $e->amount,
                ])->values()->all(),
            ];
        })->all();

        return [
            'rows' => $rows,
            'jumlah' => count($rows),
            'total_uang_pulsa' => round(collect($rows)->sum('uang_pulsa'), 2),
            'total_saldo_terpotong' => round(collect($rows)->sum('saldo_terpotong'), 2),
            'total_pengeluaran' => round(collect($rows)->sum('pengeluaran'), 2),
            'total_uang' => round(collect($rows)->sum('total_uang'), 2),
            'total_keuntungan' => round(collect($rows)->sum('keuntungan'), 2),
        ];
    }

    public function brilink(array $filters): array
    {
        $query = BrilinkDailySheet::query()
            ->with(['branch:id,name', 'lines', 'inputter:id,name'])
            ->whereDate('sheet_date', '>=', $filters['date_from'])
            ->whereDate('sheet_date', '<=', $filters['date_to'])
            ->orderByDesc('sheet_date')
            ->orderByDesc('id');

        if ($filters['branch_id']) {
            $query->where('branch_id', $filters['branch_id']);
        }

        $rows = $query->get()->map(function (BrilinkDailySheet $sheet) {
            return [
                'id' => $sheet->id,
                'tanggal' => $sheet->sheet_date?->toDateString() ?? (string) $sheet->sheet_date,
                'cabang' => $sheet->branch?->name,
                'saldo_kemarin' => (float) $sheet->previous_total,
                'total' => (float) $sheet->total_amount,
                'keuntungan' => (float) $sheet->profit,
                'oleh' => $sheet->inputter?->name,
                'lines' => $sheet->lines->map(fn ($l) => [
                    'nama' => $l->name,
                    'nominal' => (float) $l->amount,
                ])->values()->all(),
            ];
        })->all();

        return [
            'rows' => $rows,
            'jumlah' => count($rows),
            'total_saldo_kemarin' => round(collect($rows)->sum('saldo_kemarin'), 2),
            'total_hari_ini' => round(collect($rows)->sum('total'), 2),
            'total_keuntungan' => round(collect($rows)->sum('keuntungan'), 2),
        ];
    }
}
