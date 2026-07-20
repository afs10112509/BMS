<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\AuditLogger;
use App\Services\BranchContext;
use App\Services\PeriodLockChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function __construct(
        protected PeriodLockChecker $periodLockChecker,
        protected AuditLogger $auditLogger,
        protected BranchContext $branchContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Transaction::class);

        $user = $request->user();

        $query = Transaction::query()
            ->with([
                'category',
                'branch.branchType',
                'account',
                'user:id,name',
                'updatedBy:id,name',
            ])
            ->latest('transaction_date')
            ->latest('id');

        // Admin: BranchScope + middleware sudah mengisolasi.
        // Owner: filter opsional dari query string.
        if ($user->isOwner() && $request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        if ($request->filled('account_id')) {
            $query->where('account_id', $request->integer('account_id'));
        }

        if ($request->filled('type') && in_array($request->string('type')->toString(), ['income', 'expense'], true)) {
            $query->whereHas('category', fn ($q) => $q->where('type', $request->string('type')->toString()));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('transaction_date', '>=', $request->date('date_from')->toDateString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('transaction_date', '<=', $request->date('date_to')->toDateString());
        }

        if ($request->filled('q')) {
            $q = trim($request->string('q')->toString());
            if ($q !== '') {
                $query->where(function ($builder) use ($q) {
                    $builder->where('description', 'ilike', "%{$q}%");

                    $digits = preg_replace('/[^\d]/', '', $q);
                    if ($digits !== '') {
                        $builder->orWhereRaw('CAST(amount AS TEXT) LIKE ?', ["%{$digits}%"]);
                    }
                });
            }
        }

        $perPage = min(max($request->integer('per_page', 20), 1), 200);

        return response()->json([
            'message' => 'Daftar transaksi berhasil diambil.',
            'data' => $query->paginate($perPage),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Transaction::class);

        $user = $request->user();

        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string'],
            'transaction_date' => ['required', 'date'],
        ]);

        $branchId = $user->isOwner()
            ? ($data['branch_id'] ?? null)
            : $user->branch_id;

        if (! $branchId) {
            return response()->json([
                'message' => 'Cabang wajib dipilih.',
            ], 422);
        }

        if ($deny = $this->branchContext->denyUnlessOwnsBranch($user, (int) $branchId)) {
            return $deny;
        }

        if (! app(\App\Services\AccountAvailability::class)->isAllowed((int) $branchId, (int) $data['account_id'])) {
            return response()->json([
                'message' => 'Akun tidak tersedia untuk cabang ini.',
            ], 422);
        }

        if (! app(\App\Services\CategoryAvailability::class)->isAllowed((int) $branchId, (int) $data['category_id'])) {
            return response()->json([
                'message' => 'Kategori tidak tersedia untuk cabang ini.',
            ], 422);
        }

        $this->periodLockChecker->assertPeriodOpen($branchId, $data['transaction_date']);

        $transaction = Transaction::query()->create([
            'branch_id' => $branchId,
            'user_id' => $user->id,
            'category_id' => $data['category_id'],
            'account_id' => $data['account_id'],
            'amount' => \App\Support\Money::of($data['amount']),
            'description' => $data['description'] ?? null,
            'transaction_date' => $data['transaction_date'],
        ]);

        $this->auditLogger->log($user, 'CREATE', $transaction, null, $transaction->toArray());

        return response()->json([
            'message' => 'Transaksi berhasil dicatat.',
            'data' => $transaction->load(['category', 'branch.branchType', 'account', 'user:id,name', 'updatedBy:id,name']),
        ], 201);
    }

    public function update(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('update', $transaction);

        $user = $request->user();

        $data = $request->validate([
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'account_id' => ['sometimes', 'integer', 'exists:accounts,id'],
            'amount' => ['sometimes', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string'],
            'transaction_date' => ['sometimes', 'date'],
        ]);

        $date = $data['transaction_date'] ?? $transaction->transaction_date->toDateString();
        // Cegah bypass kunci: cek periode tanggal lama DAN baru.
        $this->periodLockChecker->assertPeriodOpen(
            $transaction->branch_id,
            $transaction->transaction_date->toDateString()
        );
        $this->periodLockChecker->assertPeriodOpen($transaction->branch_id, $date);

        if (isset($data['account_id'])
            && ! app(\App\Services\AccountAvailability::class)->isAllowed((int) $transaction->branch_id, (int) $data['account_id'])) {
            return response()->json([
                'message' => 'Akun tidak tersedia untuk cabang ini.',
            ], 422);
        }

        if (isset($data['category_id'])
            && (int) $data['category_id'] !== (int) $transaction->category_id
            && ! app(\App\Services\CategoryAvailability::class)->isAllowed((int) $transaction->branch_id, (int) $data['category_id'])) {
            return response()->json([
                'message' => 'Kategori tidak tersedia untuk cabang ini.',
            ], 422);
        }

        $oldValues = $transaction->toArray();
        $transaction->update([
            ...$data,
            'updated_by' => $user->id,
        ]);

        $this->auditLogger->log(
            $user,
            'UPDATE',
            $transaction,
            $oldValues,
            $transaction->fresh()->toArray()
        );

        return response()->json([
            'message' => 'Transaksi berhasil diperbarui.',
            'data' => $transaction->fresh()->load(['category', 'branch.branchType', 'account', 'user:id,name', 'updatedBy:id,name']),
        ]);
    }

    public function destroy(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('delete', $transaction);

        $user = $request->user();

        $this->periodLockChecker->assertPeriodOpen(
            $transaction->branch_id,
            $transaction->transaction_date->toDateString()
        );

        $oldValues = $transaction->toArray();
        $transaction->delete();

        $this->auditLogger->log($user, 'DELETE', $transaction, $oldValues, null);

        return response()->json([
            'message' => 'Transaksi berhasil dihapus.',
        ]);
    }
}
