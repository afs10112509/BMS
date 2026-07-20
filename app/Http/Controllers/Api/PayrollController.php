<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\ServiceRecord;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\PayrollLocker;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PayrollController extends Controller
{
    public function __construct(
        protected PayrollCalculator $calculator,
        protected PayrollLocker $locker,
    ) {}

    public function board(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $year = (int) $data['year'];
        $month = (int) $data['month'];
        $daysInMonth = Carbon::create($year, $month, 1)->daysInMonth;

        $branchId = $this->resolveBranchId($user, $data['branch_id'] ?? null, requireBranch: false);
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        $employees = $this->eligibleEmployeesQuery($branchId)->get();
        $employeeIds = $employees->pluck('id')->all();

        $stored = Payroll::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('year', $year)
            ->where('month', $month)
            ->get()
            ->keyBy('employee_id');

        $autoByEmployee = $this->calculator->computeAutoBatch($employees, $year, $month);

        $rows = $employees->map(function (Employee $employee) use ($stored, $autoByEmployee, $year, $month) {
            $payroll = $stored->get($employee->id);
            $auto = $autoByEmployee[$employee->id] ?? $this->calculator->emptyAuto($employee);

            if ($payroll && $payroll->isLocked()) {
                return $this->calculator->rowFromPayroll($payroll, $employee);
            }

            $manual = [
                'insentif_acc' => (float) ($payroll?->insentif_acc ?? 0),
                'bonus_absen' => (float) ($payroll?->bonus_absen ?? 0),
                'hutang' => (float) ($payroll?->hutang ?? 0),
                'pengeluaran' => (float) ($payroll?->pengeluaran ?? 0),
                'note' => $payroll?->note,
            ];

            $total = Payroll::computeTotal(
                $auto['gapok'],
                $auto['insentif_hp'],
                $auto['service_incentive'],
                $manual['insentif_acc'],
                $manual['bonus_absen'],
                $manual['hutang'],
                $manual['pengeluaran'],
            );

            return [
                'payroll_id' => $payroll?->id,
                'employee_id' => $employee->id,
                'name' => $employee->name,
                'branch_id' => $employee->branch_id,
                'branch_name' => $employee->branch?->name,
                'position' => $employee->position,
                'is_promotor' => $auto['is_promotor'],
                'is_technician' => $auto['is_technician'],
                'status' => Payroll::STATUS_DRAFT,
                'present_days' => $auto['present_days'],
                'gapok' => $auto['gapok'],
                'closing_qty' => $auto['closing_qty'],
                'insentif_hp' => $auto['insentif_hp'],
                'service_profit' => $auto['service_profit'],
                'service_incentive' => $auto['service_incentive'],
                'insentif_acc' => $manual['insentif_acc'],
                'bonus_absen' => $manual['bonus_absen'],
                'hutang' => $manual['hutang'],
                'pengeluaran' => $manual['pengeluaran'],
                'total' => $total,
                'note' => $manual['note'],
                'year' => $year,
                'month' => $month,
            ];
        })->values();

        $groups = $this->groupRows($rows);
        $totals = $this->sumTotals($rows);
        $statuses = $rows->pluck('status');

        return response()->json([
            'message' => 'Rekap gaji berhasil diambil.',
            'meta' => [
                'year' => $year,
                'month' => $month,
                'days_in_month' => $daysInMonth,
                'branch_id' => $branchId,
                'gapok_rate' => Payroll::GAPOK_RATE,
                'hp_rate' => Payroll::HP_RATE,
                'service_pct' => (int) (Payroll::SERVICE_PCT * 100),
                'totals' => $totals,
                'any_locked' => $statuses->contains(Payroll::STATUS_LOCKED),
                'all_locked' => $rows->isNotEmpty() && $statuses->every(fn ($s) => $s === Payroll::STATUS_LOCKED),
            ],
            'data' => $rows,
            'groups' => $groups,
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.employee_id' => ['required', 'integer', 'exists:employees,id'],
            'items.*.insentif_acc' => ['nullable', 'numeric', 'min:0'],
            'items.*.bonus_absen' => ['nullable', 'numeric', 'min:0'],
            'items.*.hutang' => ['nullable', 'numeric', 'min:0'],
            'items.*.pengeluaran' => ['nullable', 'numeric', 'min:0'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        $year = (int) $data['year'];
        $month = (int) $data['month'];

        $branchId = $this->resolveBranchId($user, $data['branch_id'] ?? null, requireBranch: false);
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        $employees = $this->eligibleEmployeesQuery($branchId)->get()->keyBy('id');
        $allowedSet = array_fill_keys($employees->keys()->all(), true);

        foreach ($data['items'] as $item) {
            if (! isset($allowedSet[(int) $item['employee_id']])) {
                return response()->json([
                    'message' => 'Ada karyawan yang tidak boleh digaji untuk cabang/periode ini.',
                ], 422);
            }
        }

        $lockedIds = Payroll::query()
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', Payroll::STATUS_LOCKED)
            ->whereIn('employee_id', collect($data['items'])->pluck('employee_id'))
            ->pluck('employee_id')
            ->all();

        if ($lockedIds) {
            return response()->json([
                'message' => 'Ada slip gaji yang sudah dikunci. Buka kunci dulu atau hapus dari daftar simpan.',
            ], 422);
        }

        $this->locker->saveDrafts($employees, $year, $month, $data['items'], $user);

        return response()->json([
            'message' => 'Rekap gaji berhasil disimpan.',
            'meta' => [
                'year' => $year,
                'month' => $month,
                'branch_id' => $branchId,
            ],
        ]);
    }

    public function lock(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $year = (int) $data['year'];
        $month = (int) $data['month'];

        $branchId = $this->resolveBranchId($user, $data['branch_id'] ?? null, requireBranch: false);
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        $employees = $this->eligibleEmployeesQuery($branchId)->get()->keyBy('id');
        if ($employees->isEmpty()) {
            return response()->json(['message' => 'Tidak ada karyawan untuk dikunci.'], 422);
        }

        $this->locker->lockPeriod($employees, $year, $month, $user);

        return response()->json([
            'message' => 'Rekap gaji berhasil dikunci.',
            'meta' => [
                'year' => $year,
                'month' => $month,
                'branch_id' => $branchId,
            ],
        ]);
    }

    public function unlock(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isOwner()) {
            return response()->json(['message' => 'Hanya owner yang boleh membuka kunci gaji.'], 403);
        }

        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $year = (int) $data['year'];
        $month = (int) $data['month'];
        $branchId = $data['branch_id'] ?? null;

        $query = Payroll::query()
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', Payroll::STATUS_LOCKED);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $updated = $query->update([
            'status' => Payroll::STATUS_DRAFT,
            'locked_at' => null,
            'locked_by' => null,
        ]);

        return response()->json([
            'message' => $updated
                ? 'Kunci gaji dibuka. Slip kembali ke draf.'
                : 'Tidak ada slip terkunci untuk periode ini.',
            'meta' => [
                'year' => $year,
                'month' => $month,
                'branch_id' => $branchId,
                'unlocked' => $updated,
            ],
        ]);
    }

    public function detail(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $year = (int) $data['year'];
        $month = (int) $data['month'];
        $employeeId = (int) $data['employee_id'];

        $branchId = $this->resolveBranchId($user, null, requireBranch: false);
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        $employee = $this->eligibleEmployeesQuery($branchId)
            ->where('employees.id', $employeeId)
            ->first();

        if (! $employee) {
            return response()->json(['message' => 'Karyawan tidak ditemukan atau tidak boleh diakses.'], 404);
        }

        [$from, $to] = $this->calculator->periodRange($year, $month);

        $payroll = Payroll::query()
            ->where('employee_id', $employeeId)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if ($payroll && $payroll->isLocked()) {
            $row = $this->calculator->rowFromPayroll($payroll, $employee);
        } else {
            $auto = $this->calculator->computeAutoBatch(collect([$employee]), $year, $month)[$employeeId]
                ?? $this->calculator->emptyAuto($employee);
            $insentifAcc = (float) ($payroll?->insentif_acc ?? 0);
            $bonusAbsen = (float) ($payroll?->bonus_absen ?? 0);
            $hutang = (float) ($payroll?->hutang ?? 0);
            $pengeluaran = (float) ($payroll?->pengeluaran ?? 0);
            $row = array_merge([
                'payroll_id' => $payroll?->id,
                'employee_id' => $employee->id,
                'name' => $employee->name,
                'branch_id' => $employee->branch_id,
                'branch_name' => $employee->branch?->name,
                'position' => $employee->position,
                'status' => Payroll::STATUS_DRAFT,
                'insentif_acc' => $insentifAcc,
                'bonus_absen' => $bonusAbsen,
                'hutang' => $hutang,
                'pengeluaran' => $pengeluaran,
                'note' => $payroll?->note,
                'year' => $year,
                'month' => $month,
                'total' => Payroll::computeTotal(
                    $auto['gapok'],
                    $auto['insentif_hp'],
                    $auto['service_incentive'],
                    $insentifAcc,
                    $bonusAbsen,
                    $hutang,
                    $pengeluaran,
                ),
            ], $auto);
        }

        $services = ServiceRecord::query()
            ->where('employee_id', $employeeId)
            ->whereBetween('service_date', [$from, $to])
            ->orderBy('service_date')
            ->get(['id', 'service_date', 'brand', 'device_type', 'cost', 'price', 'profit', 'notes']);

        return response()->json([
            'message' => 'Detail gaji berhasil diambil.',
            'data' => $row,
            'services' => $services,
            'meta' => [
                'year' => $year,
                'month' => $month,
                'from' => $from,
                'to' => $to,
                'gapok_rate' => Payroll::GAPOK_RATE,
                'hp_rate' => Payroll::HP_RATE,
                'service_pct' => (int) (Payroll::SERVICE_PCT * 100),
            ],
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function groupRows(Collection $rows): Collection
    {
        return $rows->groupBy('branch_name')->map(function ($group, $branchName) {
            return [
                'branch_name' => $branchName ?: '—',
                'totals' => $this->sumTotals($group),
                'rows' => $group->values(),
            ];
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    private function sumTotals(Collection $rows): array
    {
        return [
            'gapok' => round($rows->sum(fn ($r) => (float) $r['gapok']), 2),
            'insentif_hp' => round($rows->sum(fn ($r) => (float) $r['insentif_hp']), 2),
            'service_incentive' => round($rows->sum(fn ($r) => (float) $r['service_incentive']), 2),
            'insentif_acc' => round($rows->sum(fn ($r) => (float) $r['insentif_acc']), 2),
            'bonus_absen' => round($rows->sum(fn ($r) => (float) $r['bonus_absen']), 2),
            'hutang' => round($rows->sum(fn ($r) => (float) $r['hutang']), 2),
            'pengeluaran' => round($rows->sum(fn ($r) => (float) $r['pengeluaran']), 2),
            'grand_total' => round($rows->sum(fn ($r) => (float) $r['total']), 2),
            'present_days' => (int) $rows->sum(fn ($r) => (int) $r['present_days']),
            'closing_qty' => (int) $rows->sum(fn ($r) => (int) $r['closing_qty']),
        ];
    }

    /**
     * @return int|JsonResponse|null
     */
    private function resolveBranchId($user, ?int $requestedBranchId, bool $requireBranch)
    {
        if (! $user->isOwner()) {
            return response()->json(['message' => 'Hanya owner yang boleh mengakses gaji.'], 403);
        }

        if ($requireBranch && ! $requestedBranchId) {
            return response()->json(['message' => 'Cabang wajib dipilih.'], 422);
        }

        return $requestedBranchId ?: null;
    }

    private function eligibleEmployeesQuery(?int $branchId)
    {
        $query = Employee::query()
            ->with('branch:id,name,type')
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('position')
                    ->orWhereRaw('LOWER(TRIM(position)) NOT IN (?, ?)', ['owner', 'pemilik']);
            })
            ->orderBy('name');

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }
}
