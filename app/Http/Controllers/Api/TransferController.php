<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Category;
use App\Models\InterBranchTransfer;
use App\Models\Transaction;
use App\Services\AccountAvailability;
use App\Services\AuditLogger;
use App\Services\NotificationDispatcher;
use App\Services\PeriodLockChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransferController extends Controller
{
    public function __construct(
        protected PeriodLockChecker $periodLockChecker,
        protected AuditLogger $auditLogger,
        protected NotificationDispatcher $notifier,
        protected AccountAvailability $accountAvailability,
    ) {}

    /**
     * Mutasi antar akun dalam satu cabang (mis. Cash → Mandiri).
     */
    public function internal(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'from_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'to_account_id' => ['required', 'integer', 'exists:accounts,id', 'different:from_account_id'],
            'description' => ['nullable', 'string'],
            'transaction_date' => ['required', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $branchId = $user->isOwner()
            ? ($data['branch_id'] ?? $user->branch_id)
            : $user->branch_id;

        if (! $branchId) {
            return response()->json(['message' => 'Cabang wajib dipilih.'], 422);
        }

        if ($user->isAdmin() && (int) $branchId !== (int) $user->branch_id) {
            return response()->json([
                'message' => 'Anda tidak boleh melakukan transfer internal di cabang lain.',
            ], 403);
        }

        if (! $this->accountAvailability->isAllowed((int) $branchId, (int) $data['from_account_id'])
            || ! $this->accountAvailability->isAllowed((int) $branchId, (int) $data['to_account_id'])) {
            return response()->json([
                'message' => 'Akun tidak tersedia untuk cabang ini.',
            ], 422);
        }

        $fromAccount = Account::query()->findOrFail($data['from_account_id']);
        $toAccount = Account::query()->findOrFail($data['to_account_id']);

        $this->periodLockChecker->assertPeriodOpen($branchId, $data['transaction_date']);

        $expenseCategory = Category::query()->firstOrCreate(
            ['branch_id' => null, 'name' => 'Transfer Antar Akun - Keluar'],
            ['type' => 'expense', 'is_active' => true]
        );
        $incomeCategory = Category::query()->firstOrCreate(
            ['branch_id' => null, 'name' => 'Transfer Antar Akun - Masuk'],
            ['type' => 'income', 'is_active' => true]
        );

        $note = $data['description'] ?? "Transfer {$fromAccount->name} → {$toAccount->name}";
        $amount = \App\Support\Money::of($data['amount']);

        [$out, $in] = DB::transaction(function () use ($user, $branchId, $data, $note, $expenseCategory, $incomeCategory, $amount) {
            $out = Transaction::query()->create([
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'category_id' => $expenseCategory->id,
                'account_id' => $data['from_account_id'],
                'amount' => $amount,
                'description' => "[Transfer Antar Akun - Keluar] {$note}",
                'transaction_date' => $data['transaction_date'],
            ]);

            $in = Transaction::query()->create([
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'category_id' => $incomeCategory->id,
                'account_id' => $data['to_account_id'],
                'amount' => $amount,
                'description' => "[Transfer Antar Akun - Masuk] {$note}",
                'transaction_date' => $data['transaction_date'],
            ]);

            $this->auditLogger->log($user, 'CREATE', $out, null, $out->toArray());
            $this->auditLogger->log($user, 'CREATE', $in, null, $in->toArray());

            return [$out, $in];
        });

        return response()->json([
            'message' => 'Transfer antar akun berhasil dicatat.',
            'data' => [
                'keluar' => $out->load(['category', 'account']),
                'masuk' => $in->load(['category', 'account']),
            ],
        ], 201);
    }

    public function requestInterBranch(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'to_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'from_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'reason' => ['nullable', 'string'],
        ]);

        $fromBranchId = $user->isOwner()
            ? ($data['from_branch_id'] ?? null)
            : $user->branch_id;

        if (! $fromBranchId) {
            return response()->json(['message' => 'Cabang asal wajib dipilih.'], 422);
        }

        if ((int) $fromBranchId === (int) $data['to_branch_id']) {
            return response()->json([
                'message' => 'Cabang asal dan tujuan tidak boleh sama.',
            ], 422);
        }

        if ($user->isAdmin() && (int) $fromBranchId !== (int) $user->branch_id) {
            return response()->json([
                'message' => 'Anda hanya boleh mengajukan transfer dari cabang sendiri.',
            ], 403);
        }

        if (! $this->accountAvailability->isAllowed((int) $fromBranchId, (int) $data['account_id'])) {
            return response()->json([
                'message' => 'Akun tidak tersedia untuk cabang asal.',
            ], 422);
        }

        $transfer = InterBranchTransfer::query()->create([
            'from_branch_id' => $fromBranchId,
            'to_branch_id' => $data['to_branch_id'],
            'amount' => $data['amount'],
            'account_id' => $data['account_id'],
            'status' => 'pending',
            'requested_by' => $user->id,
            'reason' => $data['reason'] ?? null,
        ]);

        $this->notifier->notifyOwnerApprovalNeeded([
            'transfer_id' => $transfer->id,
            'dari_cabang' => $transfer->from_branch_id,
            'ke_cabang' => $transfer->to_branch_id,
            'nominal' => $transfer->amount,
            'akun' => $transfer->account_id,
            'diajukan_oleh' => $user->name,
        ]);

        return response()->json([
            'message' => 'Permohonan transfer lintas cabang berhasil diajukan.',
            'data' => $transfer->load([
                'fromBranch.branchType',
                'toBranch.branchType',
                'account',
                'requester:id,name,email',
            ]),
        ], 201);
    }

    public function approve(Request $request, InterBranchTransfer $transfer): JsonResponse
    {
        if ($transfer->status !== 'pending') {
            return response()->json([
                'message' => 'Permohonan transfer ini sudah diproses.',
            ], 422);
        }

        $user = $request->user();
        $date = now()->toDateString();

        $this->periodLockChecker->assertPeriodOpen($transfer->from_branch_id, $date);
        $this->periodLockChecker->assertPeriodOpen($transfer->to_branch_id, $date);

        $accountId = $transfer->account_id
            ?? Account::query()->where('code', 'cash')->value('id');

        if (! $accountId) {
            return response()->json(['message' => 'Akun transfer tidak ditemukan.'], 422);
        }

        if (! $this->accountAvailability->isAllowed((int) $transfer->from_branch_id, (int) $accountId)
            || ! $this->accountAvailability->isAllowed((int) $transfer->to_branch_id, (int) $accountId)) {
            return response()->json([
                'message' => 'Akun transfer tidak tersedia di salah satu cabang terkait.',
            ], 422);
        }

        $expenseCategory = Category::query()->firstOrCreate(
            ['branch_id' => null, 'name' => 'Transfer Keluar Cabang'],
            ['type' => 'expense', 'is_active' => true]
        );
        $incomeCategory = Category::query()->firstOrCreate(
            ['branch_id' => null, 'name' => 'Transfer Masuk Cabang'],
            ['type' => 'income', 'is_active' => true]
        );

        try {
            $transfer = DB::transaction(function () use ($transfer, $user, $date, $expenseCategory, $incomeCategory, $accountId) {
                /** @var InterBranchTransfer $locked */
                $locked = InterBranchTransfer::query()
                    ->whereKey($transfer->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->status !== 'pending') {
                    throw new \RuntimeException('Permohonan transfer ini sudah diproses.');
                }

                $locked->update([
                    'status' => 'approved',
                    'approved_by' => $user->id,
                    'rejection_reason' => null,
                ]);

                $amount = \App\Support\Money::of($locked->amount);

                $out = Transaction::query()->create([
                    'branch_id' => $locked->from_branch_id,
                    'user_id' => $user->id,
                    'category_id' => $expenseCategory->id,
                    'account_id' => $accountId,
                    'amount' => $amount,
                    'description' => "Transfer keluar ke cabang #{$locked->to_branch_id} (ID transfer {$locked->id})",
                    'transaction_date' => $date,
                ]);

                $in = Transaction::query()->create([
                    'branch_id' => $locked->to_branch_id,
                    'user_id' => $user->id,
                    'category_id' => $incomeCategory->id,
                    'account_id' => $accountId,
                    'amount' => $amount,
                    'description' => "Transfer masuk dari cabang #{$locked->from_branch_id} (ID transfer {$locked->id})",
                    'transaction_date' => $date,
                ]);

                $this->auditLogger->log($user, 'UPDATE', $locked, ['status' => 'pending'], $locked->toArray());
                $this->auditLogger->log($user, 'CREATE', $out, null, $out->toArray());
                $this->auditLogger->log($user, 'CREATE', $in, null, $in->toArray());

                return $locked->fresh();
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Transfer lintas cabang berhasil disetujui.',
            'data' => $transfer->load([
                'fromBranch.branchType',
                'toBranch.branchType',
                'account',
                'requester:id,name',
                'approver:id,name',
            ]),
        ]);
    }

    public function reject(Request $request, InterBranchTransfer $transfer): JsonResponse
    {
        if ($transfer->status !== 'pending') {
            return response()->json([
                'message' => 'Permohonan transfer ini sudah diproses.',
            ], 422);
        }

        $data = $request->validate([
            'rejection_reason' => ['required', 'string'],
        ]);

        $old = $transfer->toArray();

        $transfer->update([
            'status' => 'rejected',
            'approved_by' => $request->user()->id,
            'rejection_reason' => $data['rejection_reason'],
        ]);

        $this->auditLogger->log($request->user(), 'UPDATE', $transfer, $old, $transfer->toArray());

        return response()->json([
            'message' => 'Transfer lintas cabang ditolak.',
            'data' => $transfer->load([
                'fromBranch.branchType',
                'toBranch.branchType',
                'account',
                'requester:id,name',
                'approver:id,name',
            ]),
        ]);
    }
}
