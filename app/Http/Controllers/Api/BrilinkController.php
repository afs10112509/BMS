<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrilinkDailySheet;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BrilinkController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $branchId = $this->resolveBranchId($request, $user, requireBranch: ! $user->isOwner());
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $query = BrilinkDailySheet::query()
            ->with(['branch:id,name', 'inputter:id,name'])
            ->orderByDesc('sheet_date')
            ->orderByDesc('id');

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        if (! empty($data['date_from'])) {
            $query->whereDate('sheet_date', '>=', $data['date_from']);
        }
        if (! empty($data['date_to'])) {
            $query->whereDate('sheet_date', '<=', $data['date_to']);
        }

        $rows = $query->limit(100)->get()->map(fn (BrilinkDailySheet $s) => $this->sheetListPayload($s));

        return response()->json(['data' => $rows]);
    }

    public function daily(Request $request): JsonResponse
    {
        $user = $request->user();
        $branchId = $this->resolveBranchId($request, $user, requireBranch: true);
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        $data = $request->validate([
            'date' => ['required', 'date'],
        ]);
        $date = Carbon::parse($data['date'])->toDateString();

        $sheet = BrilinkDailySheet::query()
            ->with(['lines', 'branch:id,name', 'inputter:id,name'])
            ->where('branch_id', $branchId)
            ->whereDate('sheet_date', $date)
            ->first();

        if ($sheet) {
            return response()->json(['data' => $this->sheetDetailPayload($sheet)]);
        }

        return response()->json([
            'data' => $this->blankDailyPayload($branchId, $date),
        ]);
    }

    /** Salin nama item dari hari sebelumnya (nominal kosong). */
    public function copyPrevious(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($blocked = $this->assertCanMutate($user)) {
            return $blocked;
        }

        $branchId = $this->resolveBranchId($request, $user, requireBranch: true);
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        $data = $request->validate([
            'date' => ['required', 'date'],
        ]);
        $date = Carbon::parse($data['date'])->toDateString();

        $prev = BrilinkDailySheet::query()
            ->with('lines')
            ->where('branch_id', $branchId)
            ->whereDate('sheet_date', '<', $date)
            ->orderByDesc('sheet_date')
            ->first();

        if (! $prev || $prev->lines->isEmpty()) {
            return response()->json([
                'message' => 'Tidak ada item baris dari hari sebelumnya untuk disalin.',
                'data' => [
                    'lines' => [],
                    'previous_total' => $this->previousTotal($branchId, $date),
                ],
            ], 422);
        }

        $lines = $prev->lines->values()->map(fn ($line, $index) => [
            'name' => $line->name,
            'amount' => 0,
            'sort_order' => $index,
        ])->all();

        return response()->json([
            'message' => count($lines).' nama item disalin dari '.$prev->sheet_date?->toDateString().'.',
            'data' => [
                'lines' => $lines,
                'previous_total' => $this->previousTotal($branchId, $date),
                'copied_from' => $prev->sheet_date?->toDateString(),
            ],
        ]);
    }

    public function upsertDaily(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($blocked = $this->assertCanMutate($user)) {
            return $blocked;
        }

        $branchId = $this->resolveBranchId($request, $user, requireBranch: true);
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        $data = $request->validate([
            'sheet_date' => ['required', 'date'],
            'previous_total' => ['nullable', 'numeric', 'gte:0'],
            'note' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.name' => ['required', 'string', 'max:150'],
            'lines.*.amount' => ['required', 'numeric', 'gte:0'],
        ]);

        $date = Carbon::parse($data['sheet_date'])->toDateString();
        $defaultPrev = $this->previousTotal($branchId, $date);
        $previousTotal = array_key_exists('previous_total', $data) && $data['previous_total'] !== null
            ? round((float) $data['previous_total'], 2)
            : $defaultPrev;

        $lineRows = [];
        $total = 0.0;
        foreach ($data['lines'] as $index => $item) {
            $name = trim((string) $item['name']);
            if ($name === '') {
                continue;
            }
            $amount = round((float) $item['amount'], 2);
            $total += $amount;
            $lineRows[] = [
                'name' => $name,
                'amount' => $amount,
                'sort_order' => $index,
            ];
        }

        if ($lineRows === []) {
            return response()->json([
                'message' => 'Minimal satu baris dengan nama item.',
            ], 422);
        }

        $profit = round($total - $previousTotal, 2);

        $sheet = DB::transaction(function () use ($data, $branchId, $user, $date, $previousTotal, $total, $profit, $lineRows) {
            $sheet = BrilinkDailySheet::query()->updateOrCreate(
                [
                    'branch_id' => $branchId,
                    'sheet_date' => $date,
                ],
                [
                    'previous_total' => $previousTotal,
                    'total_amount' => round($total, 2),
                    'profit' => $profit,
                    'note' => $data['note'] ?? null,
                    'input_by' => $user->id,
                ]
            );

            $sheet->lines()->delete();
            foreach ($lineRows as $row) {
                $sheet->lines()->create($row);
            }

            return $sheet->fresh(['lines', 'branch:id,name', 'inputter:id,name']);
        });

        return response()->json([
            'message' => 'Catatan Brilink harian berhasil disimpan.',
            'data' => $this->sheetDetailPayload($sheet),
        ]);
    }

    public function destroy(Request $request, BrilinkDailySheet $brilinkDailySheet): JsonResponse
    {
        $user = $request->user();
        if ($blocked = $this->assertCanMutate($user)) {
            return $blocked;
        }

        $ownBranchId = $this->resolveBranchId($request, $user, requireBranch: true);
        if ($ownBranchId instanceof JsonResponse) {
            return $ownBranchId;
        }
        if ((int) $brilinkDailySheet->branch_id !== (int) $ownBranchId) {
            return response()->json([
                'message' => 'Anda hanya dapat menghapus catatan cabang sendiri.',
            ], 403);
        }

        $brilinkDailySheet->delete();

        return response()->json(['message' => 'Catatan Brilink berhasil dihapus.']);
    }

    /** @return array<string, mixed> */
    protected function blankDailyPayload(int $branchId, string $date): array
    {
        $previous = $this->previousTotal($branchId, $date);

        return [
            'id' => null,
            'branch_id' => $branchId,
            'sheet_date' => $date,
            'previous_total' => $previous,
            'total_amount' => 0,
            'profit' => round(0 - $previous, 2),
            'note' => null,
            'lines' => [],
            'is_new' => true,
        ];
    }

    protected function previousTotal(int $branchId, string $date): float
    {
        $prev = BrilinkDailySheet::query()
            ->where('branch_id', $branchId)
            ->whereDate('sheet_date', '<', $date)
            ->orderByDesc('sheet_date')
            ->first();

        return $prev ? (float) $prev->total_amount : 0.0;
    }

    /** @return array<string, mixed> */
    protected function sheetDetailPayload(BrilinkDailySheet $sheet): array
    {
        return [
            'id' => $sheet->id,
            'branch_id' => $sheet->branch_id,
            'branch' => $sheet->branch ? ['id' => $sheet->branch->id, 'name' => $sheet->branch->name] : null,
            'sheet_date' => $sheet->sheet_date?->toDateString() ?? (string) $sheet->sheet_date,
            'previous_total' => (float) $sheet->previous_total,
            'total_amount' => (float) $sheet->total_amount,
            'profit' => (float) $sheet->profit,
            'note' => $sheet->note,
            'input_by' => $sheet->inputter ? ['id' => $sheet->inputter->id, 'name' => $sheet->inputter->name] : null,
            'lines' => $sheet->lines->map(fn ($l) => [
                'id' => $l->id,
                'name' => $l->name,
                'amount' => (float) $l->amount,
                'sort_order' => (int) $l->sort_order,
            ])->values()->all(),
            'is_new' => false,
        ];
    }

    /** @return array<string, mixed> */
    protected function sheetListPayload(BrilinkDailySheet $sheet): array
    {
        return [
            'id' => $sheet->id,
            'branch_id' => $sheet->branch_id,
            'branch' => $sheet->branch ? ['id' => $sheet->branch->id, 'name' => $sheet->branch->name] : null,
            'sheet_date' => $sheet->sheet_date?->toDateString() ?? (string) $sheet->sheet_date,
            'previous_total' => (float) $sheet->previous_total,
            'total_amount' => (float) $sheet->total_amount,
            'profit' => (float) $sheet->profit,
            'input_by' => $sheet->inputter ? ['id' => $sheet->inputter->id, 'name' => $sheet->inputter->name] : null,
        ];
    }

    protected function assertCanMutate($user): ?JsonResponse
    {
        if ($user->isOwner()) {
            return response()->json([
                'message' => 'Owner hanya dapat memantau Brilink. Input/ubah/hapus hanya Admin atau PIC cabang.',
            ], 403);
        }

        if ($user->isAdmin() || $user->isPicEmployee()) {
            return null;
        }

        return response()->json([
            'message' => 'Hanya Admin atau PIC cabang yang dapat mengubah catatan Brilink.',
        ], 403);
    }

    protected function resolveBranchId(Request $request, $user, bool $requireBranch = false): int|JsonResponse|null
    {
        if ($user->isAdmin() || $user->isPicEmployee()) {
            $branchId = (int) ($user->branch_id ?: 0);
            if (! $branchId && $user->isPicEmployee()) {
                $branchId = (int) ($user->employeeBranchId() ?: 0);
            }
            if (! $branchId) {
                return response()->json(['message' => 'Akun tidak terikat ke cabang.'], 422);
            }

            return $branchId;
        }

        if ($user->isOwner()) {
            if ($request->filled('branch_id')) {
                return (int) $request->integer('branch_id');
            }
            if ($requireBranch) {
                return response()->json(['message' => 'Cabang wajib dipilih.'], 422);
            }

            return null;
        }

        return response()->json(['message' => 'Akses ditolak.'], 403);
    }
}
