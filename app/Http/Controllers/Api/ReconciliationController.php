<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reconciliation;
use App\Services\AccountAvailability;
use App\Services\BranchBalanceCalculator;
use App\Services\BranchContext;
use App\Services\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    public function __construct(
        protected BranchBalanceCalculator $balanceCalculator,
        protected NotificationDispatcher $notifier,
        protected AccountAvailability $accountAvailability,
        protected BranchContext $branchContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'with_difference' => ['nullable', 'boolean'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $days = (int) ($data['days'] ?? 60);
        $limit = (int) ($data['limit'] ?? 30);
        $withDifference = $request->boolean('with_difference', false);

        $query = Reconciliation::query()
            ->with([
                'branch:id,name',
                'account:id,name,code',
                'user:id,name',
                'adjustedBy:id,name',
            ])
            ->whereDate('reconciliation_date', '>=', now()->subDays($days)->toDateString())
            ->orderByRaw('CASE WHEN adjusted_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('reconciliation_date')
            ->orderByDesc('id');

        if ($user->isOwner() && ! empty($data['branch_id'])) {
            $query->where('branch_id', (int) $data['branch_id']);
        }

        if ($withDifference) {
            $query->whereRaw('ABS(difference) > 0.009');
        }

        $rows = $query->limit($limit)->get()->map(fn (Reconciliation $r) => [
            'id' => $r->id,
            'branch_id' => (int) $r->branch_id,
            'account_id' => (int) $r->account_id,
            'branch' => $r->branch ? ['id' => $r->branch->id, 'name' => $r->branch->name] : null,
            'account' => $r->account ? [
                'id' => $r->account->id,
                'name' => $r->account->name,
                'code' => $r->account->code,
            ] : null,
            'user' => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name] : null,
            'system_balance' => (float) $r->system_balance,
            'physical_balance' => (float) $r->physical_balance,
            'difference' => (float) $r->difference,
            'reconciliation_date' => $r->reconciliation_date?->toDateString(),
            'is_adjusted' => $r->isAdjusted(),
            'adjusted_at' => $r->adjusted_at?->toIso8601String(),
            'adjusted_by' => $r->adjustedBy ? [
                'id' => $r->adjustedBy->id,
                'name' => $r->adjustedBy->name,
            ] : null,
            'adjustment_transaction_id' => $r->adjustment_transaction_id,
        ]);

        return response()->json([
            'message' => 'Daftar rekonsiliasi berhasil diambil.',
            'data' => $rows,
            'meta' => [
                'days' => $days,
                'limit' => $limit,
                'with_difference' => $withDifference,
                'jumlah' => $rows->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'physical_balance' => ['required', 'numeric'],
            'reconciliation_date' => ['required', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $this->branchContext->resolve(
            $user,
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            requireBranch: true
        );
        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        $accountId = (int) $data['account_id'];

        if (! $this->accountAvailability->isAllowed((int) $branchId, $accountId)) {
            return response()->json([
                'message' => 'Akun tidak tersedia untuk cabang ini.',
            ], 422);
        }

        $systemBalance = $this->balanceCalculator->systemBalance(
            (int) $branchId,
            $data['reconciliation_date'],
            $accountId
        );

        $difference = (float) $data['physical_balance'] - (float) $systemBalance;

        $reconciliation = Reconciliation::query()->updateOrCreate(
            [
                'branch_id' => $branchId,
                'account_id' => $accountId,
                'reconciliation_date' => $data['reconciliation_date'],
            ],
            [
                'user_id' => $user->id,
                'system_balance' => $systemBalance,
                'physical_balance' => $data['physical_balance'],
                'difference' => number_format($difference, 2, '.', ''),
                // Cek ulang membuka status penyesuaian lama (boleh dikoreksi lagi jika masih selisih).
                'adjusted_at' => null,
                'adjusted_by' => null,
                'adjustment_transaction_id' => null,
            ]
        );

        $reconciliation->load('account:id,name,code');

        if (abs($difference) > 0.009) {
            $this->notifier->notifyReconciliationDifference([
                'reconciliation_id' => $reconciliation->id,
                'branch_id' => $branchId,
                'account_id' => $accountId,
                'akun' => $reconciliation->account?->name,
                'saldo_sistem' => $systemBalance,
                'saldo_fisik' => $data['physical_balance'],
                'selisih' => $reconciliation->difference,
                'tanggal' => $data['reconciliation_date'],
            ]);
        }

        return response()->json([
            'message' => abs($difference) > 0.009
                ? 'Rekonsiliasi akun tersimpan. Ada selisih saldo; notifikasi telah dicatat.'
                : 'Rekonsiliasi akun tersimpan. Saldo sesuai.',
            'data' => $reconciliation->load([
                'branch.branchType',
                'account:id,name,code',
                'user:id,name',
            ]),
        ], 201);
    }
}
