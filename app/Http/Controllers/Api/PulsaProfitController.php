<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PulsaDailyBalance;
use App\Models\PulsaDailyExpense;
use App\Models\PulsaDailySheet;
use App\Models\PulsaProvider;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PulsaProfitController extends Controller
{
    public function providers(Request $request): JsonResponse
    {
        $user = $request->user();
        $branchId = $this->resolveBranchId($request, $user, requireBranch: true);
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        if ($deny = $this->denyUnlessCounterBranch($branchId)) {
            return $deny;
        }

        $this->ensureDefaultProviders($branchId, $user->id);

        $rows = PulsaProvider::query()
            ->where('branch_id', $branchId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (PulsaProvider $p) => $p->toPayload())
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function storeProvider(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($blocked = $this->assertCanMutate($user)) {
            return $blocked;
        }

        $branchId = $this->resolveBranchId($request, $user, requireBranch: true);
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }
        if ($deny = $this->denyUnlessCounterBranch($branchId)) {
            return $deny;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $name = PulsaProvider::normalizeName($data['name']);
        if ($name === '') {
            return response()->json(['message' => 'Nama provider wajib diisi.'], 422);
        }

        $exists = PulsaProvider::query()
            ->where('branch_id', $branchId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'Provider dengan nama ini sudah ada.'], 422);
        }

        $provider = PulsaProvider::query()->create([
            'branch_id' => $branchId,
            'name' => $name,
            'status' => PulsaProvider::STATUS_ACTIVE,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'created_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Provider pulsa berhasil ditambahkan.',
            'data' => $provider->toPayload(),
        ], 201);
    }

    public function updateProvider(Request $request, PulsaProvider $pulsaProvider): JsonResponse
    {
        $user = $request->user();
        if ($blocked = $this->assertCanMutate($user)) {
            return $blocked;
        }

        $ownBranchId = $this->resolveBranchId($request, $user, requireBranch: true);
        if ($ownBranchId instanceof JsonResponse) {
            return $ownBranchId;
        }
        if ((int) $pulsaProvider->branch_id !== (int) $ownBranchId) {
            return response()->json(['message' => 'Provider bukan milik cabang Anda.'], 403);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'in:active,inactive'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        if (isset($data['name'])) {
            $name = PulsaProvider::normalizeName($data['name']);
            if ($name === '') {
                return response()->json(['message' => 'Nama provider wajib diisi.'], 422);
            }
            $exists = PulsaProvider::query()
                ->where('branch_id', $pulsaProvider->branch_id)
                ->where('id', '!=', $pulsaProvider->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->exists();
            if ($exists) {
                return response()->json(['message' => 'Provider dengan nama ini sudah ada.'], 422);
            }
            $pulsaProvider->name = $name;
        }

        if (isset($data['status'])) {
            $pulsaProvider->status = $data['status'];
        }
        if (array_key_exists('sort_order', $data) && $data['sort_order'] !== null) {
            $pulsaProvider->sort_order = (int) $data['sort_order'];
        }
        $pulsaProvider->save();

        return response()->json([
            'message' => 'Provider pulsa berhasil diperbarui.',
            'data' => $pulsaProvider->fresh()->toPayload(),
        ]);
    }

    public function destroyProvider(Request $request, PulsaProvider $pulsaProvider): JsonResponse
    {
        $user = $request->user();
        if ($blocked = $this->assertCanMutate($user)) {
            return $blocked;
        }

        $ownBranchId = $this->resolveBranchId($request, $user, requireBranch: true);
        if ($ownBranchId instanceof JsonResponse) {
            return $ownBranchId;
        }
        if ((int) $pulsaProvider->branch_id !== (int) $ownBranchId) {
            return response()->json(['message' => 'Provider bukan milik cabang Anda.'], 403);
        }

        $inUse = PulsaDailyBalance::query()
            ->where('pulsa_provider_id', $pulsaProvider->id)
            ->exists();

        if ($inUse) {
            $pulsaProvider->status = PulsaProvider::STATUS_INACTIVE;
            $pulsaProvider->save();

            return response()->json([
                'message' => 'Provider sudah dipakai di catatan harian — dinonaktifkan.',
                'data' => $pulsaProvider->toPayload(),
            ]);
        }

        $pulsaProvider->delete();

        return response()->json(['message' => 'Provider pulsa berhasil dihapus.']);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $branchId = $this->resolveBranchId($request, $user, requireBranch: ! $user->isOwner());
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        if ($branchId && ($deny = $this->denyUnlessCounterBranch($branchId))) {
            return $deny;
        }

        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $query = PulsaDailySheet::query()
            ->with(['branch:id,name', 'inputter:id,name'])
            ->orderByDesc('sheet_date')
            ->orderByDesc('id');

        if ($branchId) {
            $query->where('branch_id', $branchId);
        } else {
            $query->whereIn('branch_id', $this->counterBranchIds());
        }

        if (! empty($data['date_from'])) {
            $query->whereDate('sheet_date', '>=', $data['date_from']);
        }
        if (! empty($data['date_to'])) {
            $query->whereDate('sheet_date', '<=', $data['date_to']);
        }
        if (! empty($data['year']) && ! empty($data['month'])) {
            $query->whereYear('sheet_date', (int) $data['year'])
                ->whereMonth('sheet_date', (int) $data['month']);
        }

        $rows = $query->limit(100)->get()->map(fn (PulsaDailySheet $s) => $this->sheetListPayload($s));

        return response()->json(['data' => $rows]);
    }

    public function daily(Request $request): JsonResponse
    {
        $user = $request->user();
        $branchId = $this->resolveBranchId($request, $user, requireBranch: true);
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        if ($deny = $this->denyUnlessCounterBranch($branchId)) {
            return $deny;
        }

        $data = $request->validate([
            'date' => ['required', 'date'],
        ]);

        $date = Carbon::parse($data['date'])->toDateString();
        $this->ensureDefaultProviders($branchId, $user->id);

        $sheet = PulsaDailySheet::query()
            ->with(['balances', 'expenses', 'branch:id,name', 'inputter:id,name'])
            ->where('branch_id', $branchId)
            ->whereDate('sheet_date', $date)
            ->first();

        if ($sheet) {
            $payload = $this->sheetDetailPayload($sheet);
            $payload['balances'] = $this->mergeActiveProvidersIntoBalances(
                $branchId,
                $date,
                $payload['balances']
            );

            return response()->json(['data' => $payload]);
        }

        return response()->json([
            'data' => $this->blankDailyPayload($branchId, $date),
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
        if ($deny = $this->denyUnlessCounterBranch($branchId)) {
            return $deny;
        }

        $data = $request->validate([
            'sheet_date' => ['required', 'date'],
            'cash_on_hand' => ['required', 'numeric', 'gte:0'],
            'note' => ['nullable', 'string', 'max:255'],
            'balances' => ['required', 'array', 'min:1', 'max:50'],
            'balances.*.pulsa_provider_id' => ['required', 'integer', 'exists:pulsa_providers,id'],
            'balances.*.opening_balance' => ['required', 'numeric', 'gte:0'],
            'balances.*.topup_amount' => ['nullable', 'numeric', 'gte:0'],
            'balances.*.closing_balance' => ['required', 'numeric', 'gte:0'],
            'expenses' => ['nullable', 'array', 'max:100'],
            'expenses.*.name' => ['required_with:expenses', 'string', 'max:150'],
            'expenses.*.amount' => ['required_with:expenses', 'numeric', 'gte:0'],
        ]);

        $date = Carbon::parse($data['sheet_date'])->toDateString();
        $providerIds = collect($data['balances'])->pluck('pulsa_provider_id')->map(fn ($id) => (int) $id)->unique();
        $providers = PulsaProvider::query()
            ->where('branch_id', $branchId)
            ->whereIn('id', $providerIds)
            ->get()
            ->keyBy('id');

        if ($providers->count() !== $providerIds->count()) {
            return response()->json([
                'message' => 'Ada provider yang tidak valid untuk cabang ini.',
            ], 422);
        }

        $sheet = DB::transaction(function () use ($data, $branchId, $user, $date, $providers) {
            $balanceRows = [];
            $totalUsed = 0.0;
            foreach ($data['balances'] as $index => $item) {
                $provider = $providers->get((int) $item['pulsa_provider_id']);
                $opening = round((float) $item['opening_balance'], 2);
                $topup = round((float) ($item['topup_amount'] ?? 0), 2);
                $closing = round((float) $item['closing_balance'], 2);
                $used = PulsaDailySheet::calcUsed($opening, $topup, $closing);
                $totalUsed += $used;
                $balanceRows[] = [
                    'pulsa_provider_id' => $provider->id,
                    'provider_name' => $provider->name,
                    'opening_balance' => $opening,
                    'topup_amount' => $topup,
                    'closing_balance' => $closing,
                    'used_amount' => $used,
                    'sort_order' => $index,
                ];
            }

            $expenseRows = [];
            $totalExpense = 0.0;
            foreach ($data['expenses'] ?? [] as $index => $item) {
                $name = trim((string) $item['name']);
                $amount = round((float) $item['amount'], 2);
                if ($name === '' || $amount <= 0) {
                    continue;
                }
                $totalExpense += $amount;
                $expenseRows[] = [
                    'name' => $name,
                    'amount' => $amount,
                    'sort_order' => $index,
                ];
            }

            $cashOnHand = round((float) $data['cash_on_hand'], 2);
            $totalCash = round($cashOnHand + $totalExpense, 2);
            $profit = round($totalCash - $totalUsed, 2);

            $sheet = PulsaDailySheet::query()->updateOrCreate(
                [
                    'branch_id' => $branchId,
                    'sheet_date' => $date,
                ],
                [
                    'cash_on_hand' => $cashOnHand,
                    'total_used_balance' => round($totalUsed, 2),
                    'total_expense' => round($totalExpense, 2),
                    'total_cash' => $totalCash,
                    'profit' => $profit,
                    'note' => $data['note'] ?? null,
                    'input_by' => $user->id,
                ]
            );

            $sheet->balances()->delete();
            foreach ($balanceRows as $row) {
                $sheet->balances()->create($row);
            }

            $sheet->expenses()->delete();
            foreach ($expenseRows as $row) {
                $sheet->expenses()->create($row);
            }

            return $sheet->fresh(['balances', 'expenses', 'branch:id,name', 'inputter:id,name']);
        });

        return response()->json([
            'message' => 'Keuntungan pulsa harian berhasil disimpan.',
            'data' => $this->sheetDetailPayload($sheet),
        ]);
    }

    public function destroy(Request $request, PulsaDailySheet $pulsaDailySheet): JsonResponse
    {
        $user = $request->user();
        if ($blocked = $this->assertCanMutate($user)) {
            return $blocked;
        }

        $ownBranchId = $this->resolveBranchId($request, $user, requireBranch: true);
        if ($ownBranchId instanceof JsonResponse) {
            return $ownBranchId;
        }
        if ((int) $pulsaDailySheet->branch_id !== (int) $ownBranchId) {
            return response()->json([
                'message' => 'Anda hanya dapat menghapus catatan cabang sendiri.',
            ], 403);
        }

        $pulsaDailySheet->delete();

        return response()->json(['message' => 'Catatan keuntungan pulsa berhasil dihapus.']);
    }

    /** @return array<string, mixed> */
    protected function blankDailyPayload(int $branchId, string $date): array
    {
        $providers = PulsaProvider::query()
            ->where('branch_id', $branchId)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $prevClosings = $this->previousClosingsByProvider($branchId, $date);

        $balances = $providers->values()->map(function (PulsaProvider $p, int $index) use ($prevClosings) {
            $opening = (float) ($prevClosings[$p->id] ?? 0);

            return [
                'pulsa_provider_id' => $p->id,
                'provider_name' => $p->name,
                'opening_balance' => $opening,
                'topup_amount' => 0,
                'closing_balance' => 0,
                'used_amount' => PulsaDailySheet::calcUsed($opening, 0, 0),
                'sort_order' => $index,
            ];
        })->all();

        return [
            'id' => null,
            'branch_id' => $branchId,
            'sheet_date' => $date,
            'cash_on_hand' => 0,
            'total_used_balance' => round(collect($balances)->sum('used_amount'), 2),
            'total_expense' => 0,
            'total_cash' => 0,
            'profit' => 0,
            'note' => null,
            'balances' => $balances,
            'expenses' => [],
            'is_new' => true,
        ];
    }

    /**
     * Pastikan provider aktif baru ikut muncul di form (opening dari hari sebelumnya).
     *
     * @param  list<array<string, mixed>>  $balances
     * @return list<array<string, mixed>>
     */
    protected function mergeActiveProvidersIntoBalances(int $branchId, string $date, array $balances): array
    {
        $byId = collect($balances)->keyBy(fn ($b) => (int) ($b['pulsa_provider_id'] ?? 0));
        $prevClosings = $this->previousClosingsByProvider($branchId, $date);
        $providers = PulsaProvider::query()
            ->where('branch_id', $branchId)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $merged = [];
        foreach ($providers as $index => $provider) {
            if ($byId->has($provider->id)) {
                $row = $byId->get($provider->id);
                $row['sort_order'] = $index;
                $merged[] = $row;
                continue;
            }
            $opening = (float) ($prevClosings[$provider->id] ?? 0);
            $merged[] = [
                'id' => null,
                'pulsa_provider_id' => $provider->id,
                'provider_name' => $provider->name,
                'opening_balance' => $opening,
                'topup_amount' => 0,
                'closing_balance' => 0,
                'used_amount' => PulsaDailySheet::calcUsed($opening, 0, 0),
                'sort_order' => $index,
            ];
        }

        return $merged;
    }

    /**
     * @return array<int, float> provider_id => closing_balance
     */
    protected function previousClosingsByProvider(int $branchId, string $date): array
    {
        $prevSheet = PulsaDailySheet::query()
            ->where('branch_id', $branchId)
            ->whereDate('sheet_date', '<', $date)
            ->orderByDesc('sheet_date')
            ->first();

        if (! $prevSheet) {
            return [];
        }

        return PulsaDailyBalance::query()
            ->where('pulsa_daily_sheet_id', $prevSheet->id)
            ->whereNotNull('pulsa_provider_id')
            ->get()
            ->mapWithKeys(fn (PulsaDailyBalance $b) => [(int) $b->pulsa_provider_id => (float) $b->closing_balance])
            ->all();
    }

    /** @return array<string, mixed> */
    protected function sheetDetailPayload(PulsaDailySheet $sheet): array
    {
        return [
            'id' => $sheet->id,
            'branch_id' => $sheet->branch_id,
            'branch' => $sheet->branch ? ['id' => $sheet->branch->id, 'name' => $sheet->branch->name] : null,
            'sheet_date' => $sheet->sheet_date?->toDateString() ?? (string) $sheet->sheet_date,
            'cash_on_hand' => (float) $sheet->cash_on_hand,
            'total_used_balance' => (float) $sheet->total_used_balance,
            'total_expense' => (float) $sheet->total_expense,
            'total_cash' => (float) $sheet->total_cash,
            'profit' => (float) $sheet->profit,
            'note' => $sheet->note,
            'input_by' => $sheet->inputter ? ['id' => $sheet->inputter->id, 'name' => $sheet->inputter->name] : null,
            'balances' => $sheet->balances->map(fn (PulsaDailyBalance $b) => [
                'id' => $b->id,
                'pulsa_provider_id' => $b->pulsa_provider_id,
                'provider_name' => $b->provider_name,
                'opening_balance' => (float) $b->opening_balance,
                'topup_amount' => (float) $b->topup_amount,
                'closing_balance' => (float) $b->closing_balance,
                'used_amount' => (float) $b->used_amount,
                'sort_order' => (int) $b->sort_order,
            ])->values()->all(),
            'expenses' => $sheet->expenses->map(fn (PulsaDailyExpense $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'amount' => (float) $e->amount,
                'sort_order' => (int) $e->sort_order,
            ])->values()->all(),
            'is_new' => false,
        ];
    }

    /** @return array<string, mixed> */
    protected function sheetListPayload(PulsaDailySheet $sheet): array
    {
        return [
            'id' => $sheet->id,
            'branch_id' => $sheet->branch_id,
            'branch' => $sheet->branch ? ['id' => $sheet->branch->id, 'name' => $sheet->branch->name] : null,
            'sheet_date' => $sheet->sheet_date?->toDateString() ?? (string) $sheet->sheet_date,
            'cash_on_hand' => (float) $sheet->cash_on_hand,
            'total_used_balance' => (float) $sheet->total_used_balance,
            'total_expense' => (float) $sheet->total_expense,
            'total_cash' => (float) $sheet->total_cash,
            'profit' => (float) $sheet->profit,
            'input_by' => $sheet->inputter ? ['id' => $sheet->inputter->id, 'name' => $sheet->inputter->name] : null,
        ];
    }

    protected function ensureDefaultProviders(int $branchId, ?int $userId): void
    {
        $count = PulsaProvider::query()->where('branch_id', $branchId)->count();
        if ($count > 0) {
            return;
        }

        foreach (PulsaProvider::DEFAULT_NAMES as $index => $name) {
            PulsaProvider::query()->create([
                'branch_id' => $branchId,
                'name' => $name,
                'status' => PulsaProvider::STATUS_ACTIVE,
                'sort_order' => $index,
                'created_by' => $userId,
            ]);
        }
    }

    protected function assertCanMutate($user): ?JsonResponse
    {
        if ($user->isOwner()) {
            return response()->json([
                'message' => 'Owner hanya dapat memantau keuntungan pulsa. Input/ubah/hapus hanya Admin atau PIC cabang konter.',
            ], 403);
        }

        // Admin cabang konter atau PIC cabang konter.
        if ($user->isAdmin() || $user->isCounterPicEmployee()) {
            return null;
        }

        return response()->json([
            'message' => 'Hanya Admin atau PIC cabang konter yang dapat mengubah keuntungan pulsa.',
        ], 403);
    }

    protected function denyUnlessCounterBranch(?int $branchId): ?JsonResponse
    {
        if (! $branchId) {
            return response()->json(['message' => 'Cabang wajib dipilih.'], 422);
        }

        $branch = Branch::query()->with('branchType')->find($branchId);
        if (! $branch) {
            return response()->json(['message' => 'Cabang tidak ditemukan.'], 422);
        }

        if ($branch->isWorkshop()) {
            return response()->json([
                'message' => 'Modul keuntungan pulsa hanya untuk cabang konter.',
            ], 403);
        }

        return null;
    }

    /**
     * @return list<int>
     */
    protected function counterBranchIds(): array
    {
        return Branch::query()
            ->with('branchType')
            ->get()
            ->filter(fn (Branch $b) => ! $b->isWorkshop())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    protected function resolveBranchId(Request $request, $user, bool $requireBranch = false): int|JsonResponse|null
    {
        if ($user->isAdmin() || $user->isCounterPicEmployee()) {
            $branchId = (int) ($user->branch_id ?: 0);
            if (! $branchId && $user->isCounterPicEmployee()) {
                $branchId = (int) ($user->employee?->branch_id ?: 0);
            }
            if (! $branchId) {
                return response()->json([
                    'message' => 'Akun tidak terikat ke cabang.',
                ], 422);
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
