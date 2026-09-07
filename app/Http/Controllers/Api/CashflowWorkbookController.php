<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashflowWorkbook;
use App\Models\CashflowWorkbookLine;
use App\Services\Cashflow\CashflowWorkbookSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CashflowWorkbookController extends Controller
{
    public function __construct(
        protected CashflowWorkbookSeeder $seeder,
    ) {}

    public function board(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $scope = $this->resolveBranchScope($request, $data['branch_id'] ?? null);
        if ($scope instanceof JsonResponse) {
            return $scope;
        }

        $year = (int) $data['year'];
        $month = (int) $data['month'];

        $branches = Branch::query()
            ->with('branchType')
            ->where('status', 'active')
            ->when($scope !== null, fn ($q) => $q->where('id', $scope))
            ->orderBy('name')
            ->get();

        $existing = CashflowWorkbook::query()
            ->with('lines')
            ->where('year', $year)
            ->where('month', $month)
            ->when($scope !== null, fn ($q) => $q->where('branch_id', $scope))
            ->get()
            ->keyBy('branch_id');

        $rows = $branches->map(function (Branch $branch) use ($existing, $year, $month) {
            $wb = $existing->get($branch->id);
            if ($wb) {
                return $this->serialize($wb, $branch);
            }

            return $this->serializeVirtual($branch, $year, $month);
        })->values();

        $totals = [
            'total_income' => round((float) $rows->sum('total_income'), 2),
            'total_expense' => round((float) $rows->sum('total_expense'), 2),
            'net_profit' => round((float) $rows->sum('net_profit'), 2),
        ];

        return response()->json([
            'message' => 'Alur kas bulanan dimuat.',
            'data' => [
                'year' => $year,
                'month' => $month,
                'rows' => $rows,
                'meta' => [
                    'totals' => $totals,
                    'saved_count' => $rows->where('exists', true)->count(),
                    'virtual_count' => $rows->where('exists', false)->count(),
                ],
            ],
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array'],
            'lines.*.type' => ['required', Rule::in([CashflowWorkbookLine::TYPE_INCOME, CashflowWorkbookLine::TYPE_EXPENSE])],
            'lines.*.name' => ['required', 'string', 'max:160'],
            'lines.*.amount' => ['nullable', 'numeric'],
            'lines.*.category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'lines.*.source' => ['nullable', Rule::in([
                CashflowWorkbookLine::SOURCE_TRANSACTION,
                CashflowWorkbookLine::SOURCE_WORKSHOP_SHOP,
                CashflowWorkbookLine::SOURCE_MANUAL,
            ])],
            'lines.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $scope = $this->resolveBranchScope($request, $data['branch_id']);
        if ($scope instanceof JsonResponse) {
            return $scope;
        }
        if ($scope !== null && (int) $scope !== (int) $data['branch_id']) {
            return response()->json(['message' => 'Anda hanya boleh mengubah alur kas cabang Anda.'], 403);
        }

        $branch = Branch::query()->with('branchType')->findOrFail((int) $data['branch_id']);
        $year = (int) $data['year'];
        $month = (int) $data['month'];

        $linesInput = collect($data['lines'])
            ->map(function (array $line, int $idx) {
                $name = trim((string) ($line['name'] ?? ''));
                if ($name === '') {
                    return null;
                }

                return [
                    'type' => $line['type'],
                    'name' => $name,
                    'amount' => round((float) ($line['amount'] ?? 0), 2),
                    'category_id' => ! empty($line['category_id']) ? (int) $line['category_id'] : null,
                    'source' => $line['source'] ?? CashflowWorkbookLine::SOURCE_MANUAL,
                    'sort_order' => (int) ($line['sort_order'] ?? $idx),
                ];
            })
            ->filter()
            ->values();

        $totals = $this->seeder->computeTotals($linesInput->all());

        $workbook = DB::transaction(function () use ($branch, $year, $month, $data, $linesInput, $totals, $request) {
            $workbook = CashflowWorkbook::query()->firstOrNew([
                'branch_id' => $branch->id,
                'year' => $year,
                'month' => $month,
            ]);

            $workbook->fill([
                'total_income' => $totals['total_income'],
                'total_expense' => $totals['total_expense'],
                'net_profit' => $totals['net_profit'],
                'note' => $data['note'] ?? null,
                'input_by' => $request->user()->id,
            ]);
            $workbook->save();

            $workbook->lines()->delete();
            foreach ($linesInput as $line) {
                $workbook->lines()->create($line);
            }

            return $workbook->fresh(['lines']);
        });

        return response()->json([
            'message' => 'Alur kas berhasil disimpan (snapshot). Transaksi asli tidak berubah.',
            'data' => $this->serialize($workbook, $branch),
        ]);
    }

    public function seedFromSystem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $scope = $this->resolveBranchScope($request, $data['branch_id']);
        if ($scope instanceof JsonResponse) {
            return $scope;
        }
        if ($scope !== null && (int) $scope !== (int) $data['branch_id']) {
            return response()->json(['message' => 'Anda hanya boleh mengubah alur kas cabang Anda.'], 403);
        }

        $branch = Branch::query()->with('branchType')->findOrFail((int) $data['branch_id']);
        $year = (int) $data['year'];
        $month = (int) $data['month'];
        $existing = CashflowWorkbook::query()
            ->where('branch_id', $branch->id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        $lines = $this->seeder->buildLines($branch, $year, $month);
        $totals = $this->seeder->computeTotals($lines);

        return response()->json([
            'message' => 'Pos diisi ulang dari transaksi + bagian toko upah. Simpan untuk menyimpan snapshot.',
            'data' => [
                'id' => $existing?->id,
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'branch_type' => $branch->type,
                'year' => $year,
                'month' => $month,
                'note' => $existing?->note,
                'lines' => $lines,
                'exists' => (bool) $existing,
                ...$totals,
            ],
        ]);
    }

    public function copyPrevious(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $scope = $this->resolveBranchScope($request, $data['branch_id']);
        if ($scope instanceof JsonResponse) {
            return $scope;
        }
        if ($scope !== null && (int) $scope !== (int) $data['branch_id']) {
            return response()->json(['message' => 'Anda hanya boleh mengubah alur kas cabang Anda.'], 403);
        }

        $branch = Branch::query()->with('branchType')->findOrFail((int) $data['branch_id']);
        $year = (int) $data['year'];
        $month = (int) $data['month'];

        $prevYear = $month === 1 ? $year - 1 : $year;
        $prevMonth = $month === 1 ? 12 : $month - 1;

        $previous = CashflowWorkbook::query()
            ->with('lines')
            ->where('branch_id', $branch->id)
            ->where('year', $prevYear)
            ->where('month', $prevMonth)
            ->first();

        $existing = CashflowWorkbook::query()
            ->where('branch_id', $branch->id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if ($previous && $previous->lines->isNotEmpty()) {
            $lines = $previous->lines->values()->map(function (CashflowWorkbookLine $line, int $idx) {
                return [
                    'type' => $line->type,
                    'name' => $line->name,
                    'amount' => 0.0,
                    'category_id' => $line->category_id,
                    'source' => $line->source ?: CashflowWorkbookLine::SOURCE_MANUAL,
                    'sort_order' => $idx,
                ];
            })->all();
            $source = 'bulan_lalu';
            $message = 'Pos disalin dari bulan lalu (nominal dikosongkan).';
        } else {
            $lines = $this->seeder->buildLines($branch, $year, $month);
            // zero amounts — names from system seed if no previous workbook
            $lines = array_map(function (array $line) {
                $line['amount'] = 0.0;

                return $line;
            }, $lines);
            $source = 'sistem';
            $message = 'Belum ada snapshot bulan lalu; memakai daftar pos sistem (nominal kosong).';
        }

        $totals = $this->seeder->computeTotals($lines);

        return response()->json([
            'message' => $message,
            'data' => [
                'id' => $existing?->id,
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'branch_type' => $branch->type,
                'year' => $year,
                'month' => $month,
                'note' => $existing?->note,
                'lines' => $lines,
                'source' => $source,
                'exists' => (bool) $existing,
                ...$totals,
            ],
        ]);
    }

    /**
     * @return int|null|JsonResponse
     */
    protected function resolveBranchScope(Request $request, mixed $requestedBranchId): mixed
    {
        $user = $request->user();

        // Workbook Alur Kas (edit snapshot) hanya Owner.
        if (! $user->isOwner()) {
            return response()->json([
                'message' => 'Kelola Alur Kas hanya dapat diakses oleh Owner.',
            ], 403);
        }

        return $requestedBranchId !== null && $requestedBranchId !== ''
            ? (int) $requestedBranchId
            : null;
    }

    private function serialize(CashflowWorkbook $workbook, Branch $branch): array
    {
        $lines = $workbook->lines->map(fn (CashflowWorkbookLine $l) => [
            'id' => $l->id,
            'type' => $l->type,
            'name' => $l->name,
            'amount' => (float) $l->amount,
            'category_id' => $l->category_id,
            'source' => $l->source,
            'sort_order' => (int) $l->sort_order,
        ])->values()->all();

        return [
            'id' => $workbook->id,
            'branch_id' => (int) $workbook->branch_id,
            'branch_name' => $branch->name,
            'branch_type' => $branch->type,
            'year' => (int) $workbook->year,
            'month' => (int) $workbook->month,
            'total_income' => (float) $workbook->total_income,
            'total_expense' => (float) $workbook->total_expense,
            'net_profit' => (float) $workbook->net_profit,
            'note' => $workbook->note,
            'lines' => $lines,
            'exists' => true,
        ];
    }

    private function serializeVirtual(Branch $branch, int $year, int $month): array
    {
        $lines = $this->seeder->buildLines($branch, $year, $month);
        $totals = $this->seeder->computeTotals($lines);

        return [
            'id' => null,
            'branch_id' => $branch->id,
            'branch_name' => $branch->name,
            'branch_type' => $branch->type,
            'year' => $year,
            'month' => $month,
            'total_income' => $totals['total_income'],
            'total_expense' => $totals['total_expense'],
            'net_profit' => $totals['net_profit'],
            'note' => null,
            'lines' => $lines,
            'exists' => false,
        ];
    }
}
