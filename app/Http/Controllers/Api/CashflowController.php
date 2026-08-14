<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\BranchBalanceCalculator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashflowController extends Controller
{
    public function __construct(
        protected BranchBalanceCalculator $balanceCalculator,
    ) {}

    public function monthly(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $resolved = $this->resolveBranchScope($request, $data['branch_id'] ?? null);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        $branchId = $resolved;

        $year = (int) ($data['year'] ?? now()->year);
        $month = (int) ($data['month'] ?? now()->month);

        $periodStart = Carbon::create($year, $month, 1)->startOfDay();
        $from = $periodStart->toDateString();
        $to = $periodStart->copy()->endOfMonth()->toDateString();

        $totals = $this->balanceCalculator->totalsByCategory($branchId, $from, $to);

        $txCount = Transaction::query()
            ->join('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('categories.name', 'not like', 'Transfer%')
            ->whereDate('transactions.transaction_date', '>=', $from)
            ->whereDate('transactions.transaction_date', '<=', $to)
            ->when($branchId, fn ($q) => $q->where('transactions.branch_id', $branchId))
            ->count();

        $branchName = null;
        if ($branchId) {
            $branchName = Branch::query()->where('id', $branchId)->value('name');
        }

        $hasData = $txCount > 0;
        $periodLabel = $periodStart->locale('id')->translatedFormat('F Y');

        $alert = $hasData
            ? null
            : ($branchName
                ? "Belum ada transaksi pada {$periodLabel} di cabang {$branchName}."
                : "Belum ada transaksi pada {$periodLabel} (semua cabang).");

        return response()->json([
            'message' => 'Alur kas bulanan berhasil diambil.',
            'meta' => [
                'year' => $year,
                'month' => $month,
                'date_from' => $from,
                'date_to' => $to,
                'branch_id' => $branchId,
                'cabang' => $branchName ?? 'Semua cabang',
                'period_label' => $periodLabel,
            ],
            'data' => [
                'has_data' => $hasData,
                'tx_count' => $txCount,
                'alert' => $alert,
                'pemasukan' => $totals['pemasukan'],
                'pengeluaran' => $totals['pengeluaran'],
                'total_pemasukan' => $totals['total_pemasukan'],
                'total_pengeluaran' => $totals['total_pengeluaran'],
                'selisih' => $totals['selisih'],
            ],
        ]);
    }

    public function matrix(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year_from' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month_from' => ['required', 'integer', 'min:1', 'max:12'],
            'year_to' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month_to' => ['required', 'integer', 'min:1', 'max:12'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $resolved = $this->resolveBranchScope($request, $data['branch_id'] ?? null);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        $branchId = $resolved;

        $start = Carbon::create((int) $data['year_from'], (int) $data['month_from'], 1)->startOfMonth();
        $end = Carbon::create((int) $data['year_to'], (int) $data['month_to'], 1)->startOfMonth();

        if ($start->gt($end)) {
            return response()->json([
                'message' => 'Periode awal tidak boleh setelah periode akhir.',
            ], 422);
        }

        $monthSpan = (($end->year - $start->year) * 12) + ($end->month - $start->month) + 1;
        if ($monthSpan > 12) {
            return response()->json([
                'message' => 'Rentang maksimal 12 bulan.',
            ], 422);
        }

        $from = $start->toDateString();
        $to = $end->copy()->endOfMonth()->toDateString();

        $matrix = $this->balanceCalculator->matrixByBranchMonth($branchId, $from, $to);

        $branchName = null;
        if ($branchId) {
            $branchName = Branch::query()->where('id', $branchId)->value('name');
        }

        $periodLabel = $start->locale('id')->translatedFormat('M Y')
            .' – '
            .$end->locale('id')->translatedFormat('M Y');

        $alert = $matrix['has_data']
            ? null
            : ($branchName
                ? "Belum ada transaksi pada {$periodLabel} di cabang {$branchName}."
                : "Belum ada transaksi pada {$periodLabel} (semua cabang).");

        return response()->json([
            'message' => 'Matriks alur kas berhasil diambil.',
            'meta' => [
                'date_from' => $from,
                'date_to' => $to,
                'year_from' => (int) $data['year_from'],
                'month_from' => (int) $data['month_from'],
                'year_to' => (int) $data['year_to'],
                'month_to' => (int) $data['month_to'],
                'branch_id' => $branchId,
                'cabang' => $branchName ?? 'Semua cabang',
                'period_label' => $periodLabel,
                'months' => $matrix['months'],
            ],
            'data' => [
                'has_data' => $matrix['has_data'],
                'tx_count' => $matrix['tx_count'],
                'alert' => $alert,
                'months' => $matrix['months'],
                'branches' => $matrix['branches'],
                'grand' => $matrix['grand'],
                'total_pemasukan' => $matrix['grand']['total_pendapatan']['total'],
                'total_pengeluaran' => $matrix['grand']['total_pengeluaran']['total'],
                'selisih' => $matrix['grand']['laba']['total'],
            ],
        ]);
    }

    public function categoryTransactions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $resolved = $this->resolveBranchScope($request, $data['branch_id'] ?? null);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        $branchId = $resolved;

        $category = Category::query()->findOrFail((int) $data['category_id']);
        if (str_starts_with((string) $category->name, 'Transfer')) {
            return response()->json([
                'message' => 'Kategori transfer sistem tidak ditampilkan di alur kas.',
            ], 422);
        }

        $from = Carbon::parse($data['date_from'])->toDateString();
        $to = Carbon::parse($data['date_to'])->toDateString();

        $rows = Transaction::query()
            ->with(['branch:id,name', 'account:id,name', 'user:id,name'])
            ->where('category_id', (int) $category->id)
            ->whereDate('transaction_date', '>=', $from)
            ->whereDate('transaction_date', '<=', $to)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get()
            ->map(fn (Transaction $t) => [
                'id' => $t->id,
                'tanggal' => $t->transaction_date?->toDateString(),
                'cabang' => $t->branch?->name,
                'akun' => $t->account?->name,
                'nominal' => (float) $t->amount,
                'keterangan' => $t->description,
                'input_oleh' => $t->user?->name,
            ])
            ->all();

        $total = (float) collect($rows)->sum('nominal');

        return response()->json([
            'message' => 'Detail transaksi pos berhasil diambil.',
            'meta' => [
                'category_id' => (int) $category->id,
                'pos' => (string) $category->name,
                'tipe' => (string) $category->type,
                'date_from' => $from,
                'date_to' => $to,
                'branch_id' => $branchId,
                'cabang' => $branchId
                    ? (Branch::query()->where('id', $branchId)->value('name') ?: '-')
                    : 'Semua cabang',
            ],
            'data' => [
                'rows' => $rows,
                'jumlah' => count($rows),
                'total' => $total,
            ],
        ]);
    }

    /**
     * @return int|null|JsonResponse
     */
    protected function resolveBranchScope(Request $request, mixed $requestedBranchId): mixed
    {
        $user = $request->user();

        if ($user->isOwner()) {
            return $requestedBranchId !== null && $requestedBranchId !== ''
                ? (int) $requestedBranchId
                : null;
        }

        if ($user->isWorkshopAdmin()) {
            return (int) $user->branch_id;
        }

        return response()->json([
            'message' => 'Alur kas hanya dapat diakses oleh Owner atau Admin cabang bengkel.',
        ], 403);
    }
}
