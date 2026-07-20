<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountOpeningBalance;
use App\Models\Branch;
use App\Models\Reconciliation;
use App\Models\Transaction;
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
