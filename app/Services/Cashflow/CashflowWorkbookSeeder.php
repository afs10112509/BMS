<?php

namespace App\Services\Cashflow;

use App\Models\Branch;
use App\Models\CashflowWorkbookLine;
use App\Models\Category;
use App\Models\WorkshopJob;
use App\Models\WorkshopWageSetting;
use App\Services\BranchBalanceCalculator;
use Carbon\Carbon;

class CashflowWorkbookSeeder
{
    public function __construct(
        protected BranchBalanceCalculator $calculator,
    ) {}

    /**
     * Bangun daftar pos dari transaksi + bagian toko upah (snapshot, belum disimpan).
     *
     * @return list<array{type:string,name:string,amount:float,category_id:?int,source:string,sort_order:int}>
     */
    public function buildLines(Branch $branch, int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $from = $start->toDateString();
        $to = $start->copy()->endOfMonth()->toDateString();

        $totals = $this->calculator->totalsByCategory($branch->id, $from, $to);
        $byNameIncome = collect($totals['pemasukan'])->keyBy('nama');
        $byNameExpense = collect($totals['pengeluaran'])->keyBy('nama');

        $preferredIncome = ['Penjualan', 'Service', 'Brilink', 'Kos Kosan', 'Pulsa', 'Lain-lain'];
        $preferredExpense = [
            'Gaji Karyawan', 'Operasional', 'Dapur', 'Listrik', 'Jajan',
            'Insentif PIC', 'In Acc HP Bonus', 'Lain-lain',
        ];

        $catMap = Category::query()
            ->where(function ($q) use ($branch) {
                $q->whereNull('branch_id')->orWhere('branch_id', $branch->id);
            })
            ->where('name', 'not like', 'Transfer%')
            ->where('is_active', true)
            ->get(['id', 'name', 'type'])
            ->keyBy(fn (Category $c) => $c->type.'|'.$c->name);

        $lines = [];
        $order = 0;

        $incomeNames = collect($preferredIncome)
            ->merge($byNameIncome->keys())
            ->unique()
            ->values();

        foreach ($incomeNames as $name) {
            $row = $byNameIncome->get($name);
            $cat = $catMap->get('income|'.$name);
            $lines[] = [
                'type' => CashflowWorkbookLine::TYPE_INCOME,
                'name' => $name,
                'amount' => round((float) ($row['total'] ?? 0), 2),
                'category_id' => $row['category_id'] ?? $cat?->id,
                'source' => CashflowWorkbookLine::SOURCE_TRANSACTION,
                'sort_order' => $order++,
            ];
        }

        if ($branch->isWorkshop()) {
            $shop = $this->workshopShopShare((int) $branch->id, $from, $to);
            $lines[] = [
                'type' => CashflowWorkbookLine::TYPE_INCOME,
                'name' => CashflowWorkbookLine::SHOP_LINE_NAME,
                'amount' => $shop,
                'category_id' => null,
                'source' => CashflowWorkbookLine::SOURCE_WORKSHOP_SHOP,
                'sort_order' => $order++,
            ];
        }

        $expenseNames = collect($preferredExpense)
            ->merge($byNameExpense->keys())
            ->unique()
            ->values();

        foreach ($expenseNames as $name) {
            $row = $byNameExpense->get($name);
            $cat = $catMap->get('expense|'.$name);
            $lines[] = [
                'type' => CashflowWorkbookLine::TYPE_EXPENSE,
                'name' => $name,
                'amount' => round((float) ($row['total'] ?? 0), 2),
                'category_id' => $row['category_id'] ?? $cat?->id,
                'source' => CashflowWorkbookLine::SOURCE_TRANSACTION,
                'sort_order' => $order++,
            ];
        }

        return $lines;
    }

    public function workshopShopShare(int $branchId, string $from, string $to): float
    {
        $jobs = WorkshopJob::query()
            ->where('branch_id', $branchId)
            ->whereDate('job_date', '>=', $from)
            ->whereDate('job_date', '<=', $to)
            ->get(['employee_id', 'job_date', 'amount']);

        if ($jobs->isEmpty()) {
            return 0.0;
        }

        /** @var array<string, array<int, float>> $pctMaps */
        $pctMaps = [];
        $shop = 0.0;

        foreach ($jobs as $job) {
            if (! $job->job_date) {
                continue;
            }
            $year = (int) $job->job_date->year;
            $month = (int) $job->job_date->month;
            $key = "{$year}-{$month}";
            if (! isset($pctMaps[$key])) {
                $pctMaps[$key] = WorkshopWageSetting::query()
                    ->where('branch_id', $branchId)
                    ->where('year', $year)
                    ->where('month', $month)
                    ->pluck('tech_share_pct', 'employee_id')
                    ->map(fn ($v) => (float) $v)
                    ->all();
            }
            $pct = $pctMaps[$key][(int) $job->employee_id]
                ?? WorkshopWageSetting::DEFAULT_TECH_SHARE_PCT;
            $gross = (float) $job->amount;
            $net = round($gross * ($pct / 100), 2);
            $shop += round($gross - $net, 2);
        }

        return round($shop, 2);
    }

    /**
     * @param  list<array{type:string,amount?:float|int|string}>  $lines
     * @return array{total_income:float,total_expense:float,net_profit:float}
     */
    public function computeTotals(array $lines): array
    {
        $income = 0.0;
        $expense = 0.0;
        foreach ($lines as $line) {
            $amount = round((float) ($line['amount'] ?? 0), 2);
            if (($line['type'] ?? '') === CashflowWorkbookLine::TYPE_INCOME) {
                $income += $amount;
            } elseif (($line['type'] ?? '') === CashflowWorkbookLine::TYPE_EXPENSE) {
                $expense += $amount;
            }
        }

        return [
            'total_income' => round($income, 2),
            'total_expense' => round($expense, 2),
            'net_profit' => round($income - $expense, 2),
        ];
    }
}
