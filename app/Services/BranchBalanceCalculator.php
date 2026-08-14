<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountOpeningBalance;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Reconciliation;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BranchBalanceCalculator
{
    public function balancesByAccount(int $branchId, ?string $asOfDate = null): array
    {
        $accounts = app(AccountAvailability::class)->forBranch($branchId);
        if ($accounts->isEmpty()) {
            return [];
        }

        $accountIds = $accounts->pluck('id')->map(fn ($id) => (int) $id)->all();
        $totals = $this->netByAccount($branchId, $asOfDate, $accountIds);
        $openings = $this->openingsFor($branchId, $accountIds);
        $lastChecks = $this->lastReconciliationsFor($branchId, $accountIds);

        return $accounts->map(function (Account $account) use ($totals, $openings, $lastChecks) {
            $id = (int) $account->id;
            $opening = $openings->get($id);
            $last = $lastChecks->get($id);

            return [
                'account_id' => $id,
                'nama_akun' => $account->name,
                'kode' => $account->code,
                'saldo' => (float) ($totals[$id] ?? 0),
                'saldo_awal' => $opening ? (float) $opening->amount : null,
                'tanggal_awal' => $opening?->effective_date?->toDateString(),
                'terakhir_dicek' => $last?->reconciliation_date?->toDateString(),
                'saldo_fisik_terakhir' => $last ? (float) $last->physical_balance : null,
                'selisih_terakhir' => $last ? (float) $last->difference : null,
            ];
        })->values()->all();
    }

    public function systemBalance(int $branchId, ?string $asOfDate = null, ?int $accountId = null): string
    {
        if ($accountId) {
            $totals = $this->netByAccount($branchId, $asOfDate, [$accountId]);

            return number_format((float) ($totals[$accountId] ?? 0), 2, '.', '');
        }

        $rows = $this->balancesByAccount($branchId, $asOfDate);
        $sum = array_sum(array_map(fn (array $r) => (float) $r['saldo'], $rows));

        return number_format($sum, 2, '.', '');
    }

    public function balancesByBranch(?int $branchId = null, ?string $asOfDate = null): array
    {
        $query = Branch::query()->orderBy('name');
        if ($branchId) {
            $query->whereKey($branchId);
        }
        $branches = $query->get(['id', 'name']);
        if ($branches->isEmpty()) {
            return [];
        }

        $totals = $this->netByBranch($branches->pluck('id')->all(), $asOfDate);

        return $branches->map(fn (Branch $branch) => [
            'branch_id' => $branch->id,
            'nama_cabang' => $branch->name,
            'saldo' => (float) ($totals[$branch->id] ?? 0),
        ])->values()->all();
    }

    public function dailyCashflow(int $branchId, int $days = 7, ?string $from = null, ?string $to = null): array
    {
        $query = Transaction::query()
            ->select([
                'transactions.transaction_date',
                'categories.type',
                DB::raw('SUM(transactions.amount) as total'),
            ])
            ->join('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('transactions.branch_id', $branchId)
            ->groupBy('transactions.transaction_date', 'categories.type')
            ->orderBy('transactions.transaction_date');

        if ($from && $to) {
            $query->whereDate('transactions.transaction_date', '>=', $from)
                ->whereDate('transactions.transaction_date', '<=', $to);
        } else {
            $query->whereDate('transactions.transaction_date', '>=', now()->subDays($days - 1)->toDateString());
        }

        $rows = $query->get();

        $result = [];

        foreach ($rows as $row) {
            $date = $row->transaction_date->toDateString();
            $result[$date] ??= ['tanggal' => $date, 'pemasukan' => 0, 'pengeluaran' => 0];

            if ($row->type === 'income') {
                $result[$date]['pemasukan'] = (float) $row->total;
            } else {
                $result[$date]['pengeluaran'] = (float) $row->total;
            }
        }

        return array_values($result);
    }

    public function branchComparison(?string $from = null, ?string $to = null, ?int $branchId = null): array
    {
        $query = Transaction::query()
            ->select([
                'transactions.branch_id',
                'branches.name as branch_name',
                'categories.type',
                DB::raw('SUM(transactions.amount) as total'),
            ])
            ->join('categories', 'categories.id', '=', 'transactions.category_id')
            ->join('branches', 'branches.id', '=', 'transactions.branch_id')
            ->groupBy('transactions.branch_id', 'branches.name', 'categories.type');

        if ($branchId) {
            $query->where('transactions.branch_id', $branchId);
        }

        if ($from) {
            $query->whereDate('transactions.transaction_date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('transactions.transaction_date', '<=', $to);
        }

        $rows = $query->get();
        $result = [];

        foreach ($rows as $row) {
            $id = $row->branch_id;
            $result[$id] ??= [
                'branch_id' => $id,
                'nama_cabang' => $row->branch_name,
                'pemasukan' => 0,
                'pengeluaran' => 0,
                'saldo' => 0,
            ];

            if ($row->type === 'income') {
                $result[$id]['pemasukan'] = (float) $row->total;
            } else {
                $result[$id]['pengeluaran'] = (float) $row->total;
            }

            $result[$id]['saldo'] = $result[$id]['pemasukan'] - $result[$id]['pengeluaran'];
        }

        return array_values($result);
    }

    /**
     * @return array{
     *   pemasukan: list<array{category_id:int,nama:string,jumlah:int,total:float}>,
     *   pengeluaran: list<array{category_id:int,nama:string,jumlah:int,total:float}>,
     *   total_pemasukan: float,
     *   total_pengeluaran: float,
     *   selisih: float
     * }
     */
    public function totalsByCategory(?int $branchId = null, ?string $from = null, ?string $to = null): array
    {
        $query = Transaction::query()
            ->select([
                'categories.id',
                'categories.name',
                'categories.type',
                DB::raw('COUNT(transactions.id) as jumlah'),
                DB::raw('SUM(transactions.amount) as total'),
            ])
            ->join('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('categories.name', 'not like', 'Transfer%')
            ->groupBy('categories.id', 'categories.name', 'categories.type')
            ->orderBy('categories.type')
            ->orderBy('categories.name');

        if ($branchId) {
            $query->where('transactions.branch_id', $branchId);
        }

        if ($from) {
            $query->whereDate('transactions.transaction_date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('transactions.transaction_date', '<=', $to);
        }

        $rows = $query->get()->map(fn ($r) => [
            'category_id' => (int) $r->id,
            'nama' => $r->name,
            'tipe' => $r->type,
            'jumlah' => (int) $r->jumlah,
            'total' => (float) $r->total,
        ]);

        $pemasukan = $rows->where('tipe', 'income')->values()->map(fn ($r) => [
            'category_id' => $r['category_id'],
            'nama' => $r['nama'],
            'jumlah' => $r['jumlah'],
            'total' => $r['total'],
        ])->all();

        $pengeluaran = $rows->where('tipe', 'expense')->values()->map(fn ($r) => [
            'category_id' => $r['category_id'],
            'nama' => $r['nama'],
            'jumlah' => $r['jumlah'],
            'total' => $r['total'],
        ])->all();

        $totalPemasukan = (float) collect($pemasukan)->sum('total');
        $totalPengeluaran = (float) collect($pengeluaran)->sum('total');

        return [
            'pemasukan' => $pemasukan,
            'pengeluaran' => $pengeluaran,
            'total_pemasukan' => $totalPemasukan,
            'total_pengeluaran' => $totalPengeluaran,
            'selisih' => $totalPemasukan - $totalPengeluaran,
        ];
    }

    /**
     * Matriks alur kas: cabang × bulan × kategori (exclude Transfer%).
     *
     * @return array{
     *   months: list<array{key:string,label:string}>,
     *   branches: list<array<string,mixed>>,
     *   grand: array{total_pendapatan: array{by_month: array<string,float>, total: float}, total_pengeluaran: array{by_month: array<string,float>, total: float}, laba: array{by_month: array<string,float>, total: float}},
     *   tx_count: int,
     *   has_data: bool
     * }
     */
    public function matrixByBranchMonth(?int $branchId, string $from, string $to): array
    {
        $months = $this->monthKeysBetween($from, $to);
        $monthKeys = array_column($months, 'key');

        $branchesQuery = Branch::query()->where('status', 'active')->orderBy('name');
        if ($branchId) {
            $branchesQuery->where('id', $branchId);
        }
        $branches = $branchesQuery->get(['id', 'name']);

        $agg = Transaction::query()
            ->select([
                'transactions.branch_id',
                'categories.id as category_id',
                'categories.name as category_name',
                'categories.type as category_type',
                DB::raw("to_char(transactions.transaction_date, 'YYYY-MM') as month_key"),
                DB::raw('SUM(transactions.amount) as total'),
                DB::raw('COUNT(transactions.id) as jumlah'),
            ])
            ->join('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('categories.name', 'not like', 'Transfer%')
            ->whereDate('transactions.transaction_date', '>=', $from)
            ->whereDate('transactions.transaction_date', '<=', $to)
            ->when($branchId, fn ($q) => $q->where('transactions.branch_id', $branchId))
            ->groupBy(
                'transactions.branch_id',
                'categories.id',
                'categories.name',
                'categories.type',
                DB::raw("to_char(transactions.transaction_date, 'YYYY-MM')")
            )
            ->get();

        $txCount = (int) $agg->sum('jumlah');

        /** @var array<int, array<int, array<string, float>>> $lookup [branch][category][month] = amount */
        $lookup = [];
        /** @var array<int, array<int, array{id:int,name:string,type:string}>> $usedCats */
        $usedCats = [];
        foreach ($agg as $row) {
            $bid = (int) $row->branch_id;
            $cid = (int) $row->category_id;
            $mk = (string) $row->month_key;
            $lookup[$bid][$cid][$mk] = (float) $row->total;
            $usedCats[$bid][$cid] = [
                'id' => $cid,
                'name' => (string) $row->category_name,
                'type' => (string) $row->category_type,
            ];
        }

        $allCats = Category::query()
            ->where('is_active', true)
            ->where('name', 'not like', 'Transfer%')
            ->where(function ($q) use ($branchId, $branches) {
                $q->whereNull('branch_id');
                if ($branchId) {
                    $q->orWhere('branch_id', $branchId);
                } else {
                    $ids = $branches->pluck('id')->all();
                    if ($ids !== []) {
                        $q->orWhereIn('branch_id', $ids);
                    }
                }
            })
            ->get(['id', 'name', 'type', 'branch_id']);

        $resultBranches = [];

        foreach ($branches as $branch) {
            $bid = (int) $branch->id;
            $catsForBranch = $allCats->filter(function ($c) use ($bid) {
                return $c->branch_id === null || (int) $c->branch_id === $bid;
            })->values();

            foreach ($usedCats[$bid] ?? [] as $cid => $meta) {
                if (! $catsForBranch->contains(fn ($c) => (int) $c->id === $cid)) {
                    $catsForBranch->push((object) [
                        'id' => $meta['id'],
                        'name' => $meta['name'],
                        'type' => $meta['type'],
                        'branch_id' => $bid,
                    ]);
                }
            }

            $incomeCats = $this->sortCashflowCategories(
                $catsForBranch->where('type', 'income')->values()
            );
            $expenseCats = $this->sortCashflowCategories(
                $catsForBranch->where('type', 'expense')->values()
            );

            $pendapatan = [];
            foreach ($incomeCats as $cat) {
                $row = $this->buildMatrixCategoryRow($bid, (int) $cat->id, (string) $cat->name, $monthKeys, $lookup);
                if ($row['total'] == 0.0 && ! $this->isPreferredCashflowPos((string) $cat->name, 'income')) {
                    continue;
                }
                $pendapatan[] = $row;
            }

            $pengeluaran = [];
            foreach ($expenseCats as $cat) {
                $row = $this->buildMatrixCategoryRow($bid, (int) $cat->id, (string) $cat->name, $monthKeys, $lookup);
                if ($row['total'] == 0.0 && ! $this->isPreferredCashflowPos((string) $cat->name, 'expense')) {
                    continue;
                }
                $pengeluaran[] = $row;
            }

            $totalPendapatan = $this->emptyMonthMap($monthKeys);
            $totalPengeluaran = $this->emptyMonthMap($monthKeys);
            foreach ($lookup[$bid] ?? [] as $cid => $monthsMap) {
                $meta = $usedCats[$bid][$cid] ?? null;
                if (! $meta) {
                    continue;
                }
                foreach ($monthsMap as $mk => $amt) {
                    if (! array_key_exists($mk, $totalPendapatan)) {
                        continue;
                    }
                    if ($meta['type'] === 'income') {
                        $totalPendapatan[$mk] += $amt;
                    } else {
                        $totalPengeluaran[$mk] += $amt;
                    }
                }
            }

            $labaByMonth = [];
            foreach ($monthKeys as $mk) {
                $labaByMonth[$mk] = $totalPendapatan[$mk] - $totalPengeluaran[$mk];
            }

            $resultBranches[] = [
                'branch_id' => $bid,
                'nama' => (string) $branch->name,
                'pendapatan' => $pendapatan,
                'pengeluaran' => $pengeluaran,
                'total_pendapatan' => [
                    'by_month' => $totalPendapatan,
                    'total' => array_sum($totalPendapatan),
                ],
                'total_pengeluaran' => [
                    'by_month' => $totalPengeluaran,
                    'total' => array_sum($totalPengeluaran),
                ],
                'laba' => [
                    'by_month' => $labaByMonth,
                    'total' => array_sum($totalPendapatan) - array_sum($totalPengeluaran),
                ],
            ];
        }

        $grandIncome = $this->emptyMonthMap($monthKeys);
        $grandExpense = $this->emptyMonthMap($monthKeys);
        foreach ($resultBranches as $b) {
            foreach ($monthKeys as $mk) {
                $grandIncome[$mk] += (float) ($b['total_pendapatan']['by_month'][$mk] ?? 0);
                $grandExpense[$mk] += (float) ($b['total_pengeluaran']['by_month'][$mk] ?? 0);
            }
        }
        $grandLaba = [];
        foreach ($monthKeys as $mk) {
            $grandLaba[$mk] = $grandIncome[$mk] - $grandExpense[$mk];
        }

        return [
            'months' => $months,
            'branches' => $resultBranches,
            'grand' => [
                'total_pendapatan' => [
                    'by_month' => $grandIncome,
                    'total' => array_sum($grandIncome),
                ],
                'total_pengeluaran' => [
                    'by_month' => $grandExpense,
                    'total' => array_sum($grandExpense),
                ],
                'laba' => [
                    'by_month' => $grandLaba,
                    'total' => array_sum($grandIncome) - array_sum($grandExpense),
                ],
            ],
            'tx_count' => $txCount,
            'has_data' => $txCount > 0,
        ];
    }

    /**
     * @return list<array{key:string,label:string}>
     */
    protected function monthKeysBetween(string $from, string $to): array
    {
        $cursor = Carbon::parse($from)->startOfMonth();
        $end = Carbon::parse($to)->startOfMonth();
        $months = [];
        while ($cursor->lte($end)) {
            $months[] = [
                'key' => $cursor->format('Y-m'),
                'label' => $cursor->copy()->locale('id')->translatedFormat('F Y'),
            ];
            $cursor->addMonth();
        }

        return $months;
    }

    /**
     * @param  list<string>  $monthKeys
     * @return array<string, float>
     */
    protected function emptyMonthMap(array $monthKeys): array
    {
        $map = [];
        foreach ($monthKeys as $mk) {
            $map[$mk] = 0.0;
        }

        return $map;
    }

    /**
     * @param  list<string>  $monthKeys
     * @param  array<int, array<int, array<string, float>>>  $lookup
     * @return array{category_id:int,nama:string,by_month:array<string,float>,total:float}
     */
    protected function buildMatrixCategoryRow(
        int $branchId,
        int $categoryId,
        string $name,
        array $monthKeys,
        array $lookup,
    ): array {
        $byMonth = [];
        $rowTotal = 0.0;
        foreach ($monthKeys as $mk) {
            $amt = (float) ($lookup[$branchId][$categoryId][$mk] ?? 0);
            $byMonth[$mk] = $amt;
            $rowTotal += $amt;
        }

        return [
            'category_id' => $categoryId,
            'nama' => $name,
            'by_month' => $byMonth,
            'total' => $rowTotal,
        ];
    }

    protected function isPreferredCashflowPos(string $name, string $type): bool
    {
        $income = ['Penjualan', 'Service', 'Brilink', 'Kos Kosan', 'Lain-lain', 'Pulsa'];
        $expense = [
            'Gaji Karyawan', 'Operasional', 'Dapur', 'Listrik', 'Jajan', 'Lain-lain',
            'Insentif PIC', 'In Acc HP Bonus',
        ];

        return in_array($name, $type === 'income' ? $income : $expense, true);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, mixed>  $cats
     * @return \Illuminate\Support\Collection<int, mixed>
     */
    protected function sortCashflowCategories($cats)
    {
        $orderIncome = ['Penjualan', 'Service', 'Brilink', 'Kos Kosan', 'Pulsa', 'Lain-lain'];
        $orderExpense = [
            'Gaji Karyawan', 'Operasional', 'Dapur', 'Listrik', 'Jajan',
            'Insentif PIC', 'In Acc HP Bonus', 'Lain-lain',
        ];

        return $cats->sortBy(function ($c) use ($orderIncome, $orderExpense) {
            $name = (string) $c->name;
            $type = (string) $c->type;
            $order = $type === 'income' ? $orderIncome : $orderExpense;
            $idx = array_search($name, $order, true);

            return sprintf('%03d-%s', $idx === false ? 500 : $idx, mb_strtolower($name));
        })->values();
    }

    /**
     * @param  list<int>  $accountIds
     * @return array<int, float>
     */
    protected function netByAccount(int $branchId, ?string $asOfDate, array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        $openings = $this->openingsFor($branchId, $accountIds);
        $totals = array_fill_keys(array_map('intval', $accountIds), 0.0);

        foreach ($openings as $accountId => $opening) {
            $accountId = (int) $accountId;
            $effective = $opening->effective_date->toDateString();
            if ($asOfDate && $asOfDate < $effective) {
                continue;
            }
            $totals[$accountId] = (float) $opening->amount;
        }

        $query = Transaction::query()
            ->select([
                'transactions.account_id',
                'categories.type',
                DB::raw('SUM(transactions.amount) as total'),
            ])
            ->join('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('transactions.branch_id', $branchId)
            ->whereIn('transactions.account_id', $accountIds)
            ->groupBy('transactions.account_id', 'categories.type');

        if ($asOfDate) {
            $query->whereDate('transactions.transaction_date', '<=', $asOfDate);
        }

        $raw = [];
        foreach ($query->get() as $row) {
            $id = (int) $row->account_id;
            $raw[$id] ??= 0.0;
            $amount = (float) $row->total;
            $raw[$id] += $row->type === 'income' ? $amount : -$amount;
        }

        // Transaksi sebelum tanggal saldo awal tidak dihitung
        $before = [];
        $withOpening = $openings->filter(function (AccountOpeningBalance $opening) use ($asOfDate) {
            $effective = $opening->effective_date->toDateString();

            return ! ($asOfDate && $asOfDate < $effective);
        });

        if ($withOpening->isNotEmpty()) {
            $beforeQuery = Transaction::query()
                ->select([
                    'transactions.account_id',
                    'categories.type',
                    DB::raw('SUM(transactions.amount) as total'),
                ])
                ->join('categories', 'categories.id', '=', 'transactions.category_id')
                ->join('account_opening_balances as o', function ($join) use ($branchId) {
                    $join->on('o.account_id', '=', 'transactions.account_id')
                        ->where('o.branch_id', '=', $branchId);
                })
                ->where('transactions.branch_id', $branchId)
                ->whereIn('transactions.account_id', $withOpening->keys()->all())
                ->whereColumn('transactions.transaction_date', '<', 'o.effective_date')
                ->groupBy('transactions.account_id', 'categories.type');

            if ($asOfDate) {
                $beforeQuery->whereDate('transactions.transaction_date', '<=', $asOfDate);
            }

            foreach ($beforeQuery->get() as $row) {
                $id = (int) $row->account_id;
                $before[$id] ??= 0.0;
                $amount = (float) $row->total;
                $before[$id] += $row->type === 'income' ? $amount : -$amount;
            }
        }

        foreach ($accountIds as $accountId) {
            $accountId = (int) $accountId;
            $opening = $openings->get($accountId);
            if ($opening) {
                $effective = $opening->effective_date->toDateString();
                if ($asOfDate && $asOfDate < $effective) {
                    $totals[$accountId] = 0.0;

                    continue;
                }
                $txNet = ($raw[$accountId] ?? 0.0) - ($before[$accountId] ?? 0.0);
                $totals[$accountId] = (float) $opening->amount + $txNet;
            } else {
                $totals[$accountId] = (float) ($raw[$accountId] ?? 0.0);
            }
        }

        return $totals;
    }

    /**
     * @param  list<int>  $branchIds
     * @return array<int, float>
     */
    protected function netByBranch(array $branchIds, ?string $asOfDate = null): array
    {
        $totals = [];
        foreach ($branchIds as $branchId) {
            $branchId = (int) $branchId;
            $totals[$branchId] = (float) $this->systemBalance($branchId, $asOfDate);
        }

        return $totals;
    }

    /**
     * @param  list<int>  $accountIds
     * @return \Illuminate\Support\Collection<int, AccountOpeningBalance>
     */
    protected function openingsFor(int $branchId, array $accountIds)
    {
        if ($accountIds === []) {
            return collect();
        }

        return AccountOpeningBalance::query()
            ->where('branch_id', $branchId)
            ->whereIn('account_id', $accountIds)
            ->get()
            ->keyBy(fn (AccountOpeningBalance $row) => (int) $row->account_id);
    }

    /**
     * @param  list<int>  $accountIds
     * @return \Illuminate\Support\Collection<int, Reconciliation>
     */
    protected function lastReconciliationsFor(int $branchId, array $accountIds)
    {
        if ($accountIds === []) {
            return collect();
        }

        $rows = Reconciliation::query()
            ->where('branch_id', $branchId)
            ->whereIn('account_id', $accountIds)
            ->orderByDesc('reconciliation_date')
            ->orderByDesc('id')
            ->get();

        $byAccount = collect();
        foreach ($rows as $row) {
            $aid = (int) $row->account_id;
            if (! $byAccount->has($aid)) {
                $byAccount->put($aid, $row);
            }
        }

        return $byAccount;
    }
}
