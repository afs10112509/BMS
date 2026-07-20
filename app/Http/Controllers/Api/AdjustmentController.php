<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\AccountAvailability;
use App\Services\AuditLogger;
use App\Services\PeriodLockChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdjustmentController extends Controller
{
    public function __construct(
        protected PeriodLockChecker $periodLockChecker,
        protected AuditLogger $auditLogger,
        protected AccountAvailability $accountAvailability,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'type' => ['required', 'in:income,expense'],
            'reason' => ['required', 'string'],
            'transaction_date' => ['required', 'date'],
        ]);

        if (! $this->accountAvailability->isAllowed((int) $data['branch_id'], (int) $data['account_id'])) {
            return response()->json([
                'message' => 'Akun tidak tersedia untuk cabang ini.',
            ], 422);
        }

        $this->periodLockChecker->assertPeriodOpen($data['branch_id'], $data['transaction_date']);

        $category = Category::query()->firstOrCreate(
            [
                'branch_id' => null,
                'name' => $data['type'] === 'income'
                    ? 'Penyesuaian Saldo - Pemasukan'
                    : 'Penyesuaian Saldo - Pengeluaran',
            ],
            ['type' => $data['type'], 'is_active' => true]
        );

        $transaction = Transaction::query()->create([
            'branch_id' => $data['branch_id'],
            'user_id' => $user->id,
            'category_id' => $category->id,
            'account_id' => $data['account_id'],
            'amount' => \App\Support\Money::of($data['amount']),
            'description' => '[Penyesuaian] '.$data['reason'],
            'transaction_date' => $data['transaction_date'],
        ]);

        $this->auditLogger->log($user, 'CREATE', $transaction, null, $transaction->toArray());

        return response()->json([
            'message' => 'Jurnal penyesuaian saldo berhasil dibuat.',
            'data' => $transaction->load(['category', 'branch.branchType', 'account']),
        ], 201);
    }
}
