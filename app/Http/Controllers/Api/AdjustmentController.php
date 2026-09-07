<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Reconciliation;
use App\Models\Transaction;
use App\Services\AccountAvailability;
use App\Services\AuditLogger;
use App\Services\PeriodLockChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'reconciliation_id' => ['nullable', 'integer', 'exists:reconciliations,id'],
        ]);

        if (! $this->accountAvailability->isAllowed((int) $data['branch_id'], (int) $data['account_id'])) {
            return response()->json([
                'message' => 'Akun tidak tersedia untuk cabang ini.',
            ], 422);
        }

        $this->periodLockChecker->assertPeriodOpen($data['branch_id'], $data['transaction_date']);

        $linkedReconId = null;

        try {
            $transaction = DB::transaction(function () use ($data, $user, &$linkedReconId) {
                $reconciliation = null;

                if (! empty($data['reconciliation_id'])) {
                    $reconciliation = Reconciliation::query()
                        ->whereKey((int) $data['reconciliation_id'])
                        ->lockForUpdate()
                        ->first();

                    if (! $reconciliation) {
                        throw new \RuntimeException('Rekonsiliasi tidak ditemukan.');
                    }

                    if ((int) $reconciliation->branch_id !== (int) $data['branch_id']
                        || (int) $reconciliation->account_id !== (int) $data['account_id']) {
                        throw new \RuntimeException('Rekonsiliasi tidak cocok dengan cabang/akun penyesuaian.');
                    }

                    if ($reconciliation->isAdjusted()) {
                        throw new \RuntimeException('Selisih rekonsiliasi ini sudah disesuaikan. Tidak bisa diproses dua kali.');
                    }

                    if (abs((float) $reconciliation->difference) <= 0.009) {
                        throw new \RuntimeException('Rekonsiliasi ini tidak memiliki selisih.');
                    }
                }

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

                if ($reconciliation) {
                    $reconciliation->update([
                        'adjusted_at' => now(),
                        'adjusted_by' => $user->id,
                        'adjustment_transaction_id' => $transaction->id,
                    ]);
                    $linkedReconId = $reconciliation->id;
                }

                return $transaction;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->auditLogger->log($user, 'CREATE', $transaction, null, $transaction->toArray());

        return response()->json([
            'message' => $linkedReconId
                ? 'Jurnal penyesuaian saldo berhasil dibuat. Selisih rekonsiliasi ditandai sudah disesuaikan.'
                : 'Jurnal penyesuaian saldo berhasil dibuat.',
            'data' => $transaction->load(['category', 'branch.branchType', 'account']),
        ], 201);
    }
}
