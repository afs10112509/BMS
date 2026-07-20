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
