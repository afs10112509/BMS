<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\ProfitShare;
use App\Models\ProfitShareLine;
use App\Services\ProfitShare\ProfitShareDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProfitShareController extends Controller
{
    public function board(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $year = (int) $data['year'];
        $month = (int) $data['month'];

        $branches = Branch::query()
            ->with('branchType')
            ->when(! empty($data['branch_id']), fn ($q) => $q->where('id', (int) $data['branch_id']))
            ->orderBy('name')
            ->get();

        $existing = ProfitShare::query()
            ->with('lines')
            ->where('year', $year)
            ->where('month', $month)
            ->when(! empty($data['branch_id']), fn ($q) => $q->where('branch_id', (int) $data['branch_id']))
            ->get()
            ->keyBy('branch_id');

        $pics = $this->picEmployees($existing->pluck('pic_employee_id')->all());

        $rows = $branches->map(function (Branch $branch) use ($existing, $year, $month, $pics) {
            $row = $existing->get($branch->id);
            if ($row) {
                return $this->serialize($row, $branch, $pics);
            }

            return $this->serializeVirtual($branch, $year, $month, $pics);
        })->values();

        $totals = [
            'total_income' => round((float) $rows->sum('total_income'), 2),
            'total_expense' => round((float) $rows->sum('total_expense'), 2),
            'net_profit' => round((float) $rows->sum('net_profit'), 2),
            'pic_amount' => round((float) $rows->sum('pic_amount'), 2),
        ];

        return response()->json([
            'message' => 'Board bagi hasil dimuat.',
            'data' => [
                'year' => $year,
                'month' => $month,
                'rows' => $rows,
                'meta' => [
                    'totals' => $totals,
                    'any_locked' => $rows->contains(fn ($r) => ($r['status'] ?? '') === ProfitShare::STATUS_LOCKED),
                    'all_locked' => $rows->isNotEmpty() && $rows->every(fn ($r) => ($r['status'] ?? '') === ProfitShare::STATUS_LOCKED),
                ],
            ],
        ]);
    }

    public function detail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $branch = Branch::query()->with('branchType')->findOrFail((int) $data['branch_id']);
        $year = (int) $data['year'];
        $month = (int) $data['month'];

        $row = ProfitShare::query()
            ->with('lines')
            ->where('branch_id', $branch->id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        $employees = $this->picEmployees([$row?->pic_employee_id]);

        $payload = $row
            ? $this->serialize($row, $branch, $employees)
            : $this->serializeVirtual($branch, $year, $month, $employees);

        return response()->json([
            'message' => 'Detail bagi hasil dimuat.',
            'data' => $payload,
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'pic_name' => ['nullable', 'string', 'max:120'],
            'pic_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'pic_share_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array'],
            'lines.*.type' => ['required', Rule::in([ProfitShareLine::TYPE_INCOME, ProfitShareLine::TYPE_EXPENSE])],
            'lines.*.name' => ['required', 'string', 'max:160'],
            'lines.*.amount' => ['nullable', 'numeric'],
            'lines.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $branch = Branch::query()->with('branchType')->findOrFail((int) $data['branch_id']);
        $year = (int) $data['year'];
        $month = (int) $data['month'];

        $existing = ProfitShare::query()
            ->where('branch_id', $branch->id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if ($existing?->isLocked()) {
            return response()->json([
                'message' => 'Bagi hasil periode ini terkunci. Buka kunci terlebih dahulu.',
            ], 403);
        }

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
                    'sort_order' => (int) ($line['sort_order'] ?? $idx),
                ];
            })
            ->filter()
            ->values();

        $totals = $this->computeTotals($linesInput->all(), (float) $data['pic_share_pct']);
        $pic = $this->resolvePicForSave(
            $branch,
            (int) ($data['pic_employee_id'] ?? 0) ?: null,
            trim((string) ($data['pic_name'] ?? '')) ?: null
        );
        if ($pic instanceof JsonResponse) {
            return $pic;
        }

        $share = DB::transaction(function () use ($existing, $branch, $year, $month, $data, $linesInput, $totals, $request, $pic) {
            $share = $existing ?: new ProfitShare([
                'branch_id' => $branch->id,
                'year' => $year,
                'month' => $month,
                'status' => ProfitShare::STATUS_DRAFT,
            ]);

            $share->fill([
                'pic_name' => $pic['pic_name'],
                'pic_employee_id' => $pic['pic_employee_id'],
                'pic_share_pct' => round((float) $data['pic_share_pct'], 2),
                'total_income' => $totals['total_income'],
                'total_expense' => $totals['total_expense'],
                'net_profit' => $totals['net_profit'],
                'pic_amount' => $totals['pic_amount'],
                'note' => $data['note'] ?? null,
                'input_by' => $request->user()->id,
            ]);
            $share->save();

            $share->lines()->delete();
            foreach ($linesInput as $line) {
                $share->lines()->create($line);
            }

            return $share->fresh(['lines']);
        });

        return response()->json([
            'message' => 'Bagi hasil berhasil disimpan.',
            'data' => $this->serialize($share, $branch),
        ]);
    }

    public function lock(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $share = ProfitShare::query()
            ->with('lines')
            ->where('branch_id', (int) $data['branch_id'])
            ->where('year', (int) $data['year'])
            ->where('month', (int) $data['month'])
            ->first();

        if (! $share) {
            return response()->json([
                'message' => 'Simpan bagi hasil terlebih dahulu sebelum mengunci.',
            ], 422);
        }

        if ($share->isLocked()) {
            return response()->json([
                'message' => 'Bagi hasil sudah terkunci.',
            ], 422);
        }

        $totals = $this->computeTotals(
            $share->lines->map(fn (ProfitShareLine $l) => [
                'type' => $l->type,
                'amount' => (float) $l->amount,
            ])->all(),
            (float) $share->pic_share_pct
        );

        $share->fill([
            'status' => ProfitShare::STATUS_LOCKED,
            'total_income' => $totals['total_income'],
            'total_expense' => $totals['total_expense'],
            'net_profit' => $totals['net_profit'],
            'pic_amount' => $totals['pic_amount'],
            'locked_at' => now(),
            'locked_by' => $request->user()->id,
        ]);
        $share->save();

        $branch = Branch::query()->with('branchType')->findOrFail($share->branch_id);

        return response()->json([
            'message' => 'Bagi hasil berhasil dikunci.',
            'data' => $this->serialize($share->fresh(['lines']), $branch),
        ]);
    }

    public function unlock(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $share = ProfitShare::query()
            ->with('lines')
            ->where('branch_id', (int) $data['branch_id'])
            ->where('year', (int) $data['year'])
            ->where('month', (int) $data['month'])
            ->first();

        if (! $share) {
            return response()->json([
                'message' => 'Data bagi hasil tidak ditemukan.',
            ], 404);
        }

        if (! $share->isLocked()) {
            return response()->json([
                'message' => 'Bagi hasil belum terkunci.',
            ], 422);
        }

        $share->fill([
            'status' => ProfitShare::STATUS_DRAFT,
            'locked_at' => null,
            'locked_by' => null,
            'input_by' => $request->user()->id,
        ]);
        $share->save();

        $branch = Branch::query()->with('branchType')->findOrFail($share->branch_id);

        return response()->json([
            'message' => 'Kunci bagi hasil dibuka. Data dapat diubah kembali.',
            'data' => $this->serialize($share->fresh(['lines']), $branch),
        ]);
    }

    public function copyPrevious(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $branch = Branch::query()->with('branchType')->findOrFail((int) $data['branch_id']);
        $year = (int) $data['year'];
        $month = (int) $data['month'];

        $existing = ProfitShare::query()
            ->where('branch_id', $branch->id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if ($existing?->isLocked()) {
            return response()->json([
                'message' => 'Bagi hasil periode ini terkunci. Buka kunci terlebih dahulu.',
            ], 403);
        }

        $prevYear = $month === 1 ? $year - 1 : $year;
        $prevMonth = $month === 1 ? 12 : $month - 1;

        $previous = ProfitShare::query()
            ->with('lines')
            ->where('branch_id', $branch->id)
            ->where('year', $prevYear)
            ->where('month', $prevMonth)
            ->first();

        if ($previous && $previous->lines->isNotEmpty()) {
            $lines = $previous->lines->values()->map(function (ProfitShareLine $line, int $idx) {
                return [
                    'type' => $line->type,
                    'name' => $line->name,
                    'amount' => 0.0,
                    'sort_order' => $idx,
                ];
            })->all();
            $source = 'bulan_lalu';
        } else {
            $lines = ProfitShareDefaults::templateLines($branch);
            $source = $branch->isWorkshop() ? 'kosong' : 'template_konter';
        }

        $employees = $this->picEmployees([$existing?->pic_employee_id, $previous?->pic_employee_id]);
        $picDefault = ProfitShareDefaults::defaultPicForBranch($branch, $employees);
        $resolved = $this->matchPicEmployee(
            $employees,
            $existing?->pic_employee_id ?? $previous?->pic_employee_id ?? $picDefault['pic_employee_id'],
            $existing?->pic_name ?: ($previous?->pic_name ?: $picDefault['pic_name'])
        );
        $pct = (float) ($existing?->pic_share_pct ?? ($previous?->pic_share_pct ?? $picDefault['pic_share_pct']));

        return response()->json([
            'message' => $source === 'bulan_lalu'
                ? 'Pos disalin dari bulan lalu (nominal dikosongkan).'
                : ($source === 'template_konter'
                    ? 'Belum ada bulan lalu; memakai template pos konter.'
                    : 'Belum ada bulan lalu; pos bengkel kosong (isi manual).'),
            'data' => [
                'id' => $existing?->id,
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'branch_type' => $branch->type,
                'allows_service' => (bool) $branch->allows_service,
                'year' => $year,
                'month' => $month,
                'status' => $existing?->status ?? ProfitShare::STATUS_DRAFT,
                'pic_employee_id' => $resolved['pic_employee_id'],
                'pic_name' => $resolved['pic_name'],
                'pic_options' => $this->serializePicOptions($employees),
                'pic_share_pct' => $pct,
                'note' => $existing?->note,
                'lines' => $lines,
                'source' => $source,
                ...$this->computeTotals($lines, $pct),
            ],
        ]);
    }

    /**
     * @param  list<array{type: string, amount?: float|int|string}>  $lines
     * @return array{total_income: float, total_expense: float, net_profit: float, pic_amount: float}
     */
    private function computeTotals(array $lines, float $pct): array
    {
        $income = 0.0;
        $expense = 0.0;
        foreach ($lines as $line) {
            $amount = round((float) ($line['amount'] ?? 0), 2);
            if (($line['type'] ?? '') === ProfitShareLine::TYPE_INCOME) {
                $income += $amount;
            } elseif (($line['type'] ?? '') === ProfitShareLine::TYPE_EXPENSE) {
                $expense += $amount;
            }
        }
        $net = round($income - $expense, 2);
        $picAmount = round($net * ($pct / 100), 2);

        return [
            'total_income' => round($income, 2),
            'total_expense' => round($expense, 2),
            'net_profit' => $net,
            'pic_amount' => $picAmount,
        ];
    }

    /**
     * @param  Collection<int, Employee>|null  $employees
     */
    private function serialize(ProfitShare $share, Branch $branch, ?Collection $employees = null): array
    {
        $employees ??= $this->picEmployees([$share->pic_employee_id]);
        $resolved = $this->matchPicEmployee($employees, $share->pic_employee_id, $share->pic_name);

        $lines = $share->lines->map(fn (ProfitShareLine $l) => [
            'id' => $l->id,
            'type' => $l->type,
            'name' => $l->name,
            'amount' => (float) $l->amount,
            'sort_order' => (int) $l->sort_order,
        ])->values()->all();

        return [
            'id' => $share->id,
            'branch_id' => (int) $share->branch_id,
            'branch_name' => $branch->name,
            'branch_type' => $branch->type,
            'allows_service' => (bool) $branch->allows_service,
            'year' => (int) $share->year,
            'month' => (int) $share->month,
            'status' => $share->status,
            'pic_employee_id' => $resolved['pic_employee_id'],
            'pic_name' => $resolved['pic_name'],
            'pic_options' => $this->serializePicOptions($employees),
            'pic_share_pct' => (float) $share->pic_share_pct,
            'total_income' => (float) $share->total_income,
            'total_expense' => (float) $share->total_expense,
            'net_profit' => (float) $share->net_profit,
            'pic_amount' => (float) $share->pic_amount,
            'note' => $share->note,
            'locked_at' => $share->locked_at?->toIso8601String(),
            'lines' => $lines,
            'exists' => true,
        ];
    }

    /**
     * @param  Collection<int, Employee>|null  $employees
     */
    private function serializeVirtual(Branch $branch, int $year, int $month, ?Collection $employees = null): array
    {
        $employees ??= $this->picEmployees();
        $pic = ProfitShareDefaults::defaultPicForBranch($branch, $employees);
        $lines = ProfitShareDefaults::templateLines($branch);
        $totals = $this->computeTotals($lines, $pic['pic_share_pct']);

        return [
            'id' => null,
            'branch_id' => $branch->id,
            'branch_name' => $branch->name,
            'branch_type' => $branch->type,
            'allows_service' => (bool) $branch->allows_service,
            'year' => $year,
            'month' => $month,
            'status' => ProfitShare::STATUS_DRAFT,
            'pic_employee_id' => $pic['pic_employee_id'],
            'pic_name' => $pic['pic_name'] ?: null,
            'pic_options' => $this->serializePicOptions($employees),
            'pic_share_pct' => $pic['pic_share_pct'],
            'total_income' => $totals['total_income'],
            'total_expense' => $totals['total_expense'],
            'net_profit' => $totals['net_profit'],
            'pic_amount' => $totals['pic_amount'],
            'note' => null,
            'locked_at' => null,
            'lines' => $lines,
            'exists' => false,
        ];
    }

    /**
     * Semua karyawan jabatan PIC (lintas cabang).
     *
     * @param  list<int|string|null>  $keepIds
     * @return Collection<int, Employee>
     */
    private function picEmployees(array $keepIds = []): Collection
    {
        $keepIds = array_values(array_unique(array_filter(array_map('intval', $keepIds))));

        return Employee::query()
            ->with('branch:id,name')
            ->withPosition(Employee::POS_PIC)
            ->where(function ($q) use ($keepIds) {
                $q->where('status', 'active');
                if ($keepIds !== []) {
                    $q->orWhereIn('id', $keepIds);
                }
            })
            ->orderBy('name')
            ->get(['id', 'branch_id', 'name', 'positions', 'status']);
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return list<array{id: int, name: string, is_pic: bool, status: string, branch_name: string|null}>
     */
    private function serializePicOptions(Collection $employees): array
    {
        return $employees
            ->filter(fn (Employee $e) => $e->hasPosition(Employee::POS_PIC))
            ->sortBy(fn (Employee $e) => mb_strtolower((string) $e->name))
            ->values()
            ->map(fn (Employee $e) => [
                'id' => (int) $e->id,
                'name' => $e->name,
                'is_pic' => true,
                'status' => (string) $e->status,
                'branch_name' => $e->branch?->name,
            ])
            ->all();
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return array{pic_employee_id: int|null, pic_name: string|null}
     */
    private function matchPicEmployee(Collection $employees, ?int $employeeId, ?string $name): array
    {
        $picked = null;
        if ($employeeId) {
            $picked = $employees->firstWhere('id', $employeeId);
        }
        $needle = mb_strtolower(trim((string) $name));
        if (! $picked && $needle !== '') {
            $picked = $employees->first(function (Employee $e) use ($needle) {
                return mb_strtolower(trim((string) $e->name)) === $needle;
            });
        }

        return [
            'pic_employee_id' => $picked ? (int) $picked->id : ($employeeId ?: null),
            'pic_name' => $picked?->name ?: ($name ?: null),
        ];
    }

    /**
     * @return array{pic_employee_id: int|null, pic_name: string|null}|JsonResponse
     */
    private function resolvePicForSave(Branch $branch, ?int $employeeId, ?string $name): array|JsonResponse
    {
        if ($employeeId) {
            $employee = Employee::query()->where('id', $employeeId)->first();
            if (! $employee || ! $employee->hasPosition(Employee::POS_PIC)) {
                return response()->json([
                    'message' => 'PIC harus karyawan dengan jabatan PIC.',
                ], 422);
            }

            return [
                'pic_employee_id' => (int) $employee->id,
                'pic_name' => $employee->name,
            ];
        }

        if ($name) {
            $employees = $this->picEmployees();
            $resolved = $this->matchPicEmployee($employees, null, $name);

            return [
                'pic_employee_id' => $resolved['pic_employee_id'],
                'pic_name' => $resolved['pic_name'] ?: $name,
            ];
        }

        return [
            'pic_employee_id' => null,
            'pic_name' => null,
        ];
    }
}
