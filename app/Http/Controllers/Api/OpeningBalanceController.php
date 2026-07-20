<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountOpeningBalance;
use App\Services\AccountAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpeningBalanceController extends Controller
{
    public function __construct(private AccountAvailability $availability)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $branchId = $user->isOwner()
            ? ($request->filled('branch_id') ? $request->integer('branch_id') : null)
            : (int) $user->branch_id;

        if (! $branchId) {
            return response()->json(['message' => 'Cabang wajib dipilih.'], 422);
        }

        if ($user->isAdmin() && (int) $branchId !== (int) $user->branch_id) {
            return response()->json(['message' => 'Anda tidak boleh melihat saldo awal cabang lain.'], 403);
        }

        $rows = AccountOpeningBalance::query()
            ->with('account:id,name,code')
            ->where('branch_id', $branchId)
            ->orderBy('account_id')
            ->get();

        return response()->json([
            'message' => 'Saldo awal berhasil diambil.',
            'data' => $rows,
            'meta' => ['branch_id' => $branchId],
        ]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'amount' => ['required', 'numeric'],
            'effective_date' => ['required', 'date'],
        ]);

        $branchId = $user->isOwner()
            ? (int) ($data['branch_id'] ?? $user->branch_id)
            : (int) $user->branch_id;

        if (! $branchId) {
            return response()->json(['message' => 'Cabang wajib dipilih.'], 422);
        }

        if ($user->isAdmin() && (int) $branchId !== (int) $user->branch_id) {
            return response()->json(['message' => 'Anda hanya boleh mengatur saldo awal cabang sendiri.'], 403);
        }

        $accountId = (int) $data['account_id'];

        if (! $this->availability->isAllowed($branchId, $accountId)) {
            return response()->json(['message' => 'Akun tidak tersedia untuk cabang ini.'], 422);
        }

        $row = AccountOpeningBalance::query()->updateOrCreate(
            [
                'branch_id' => $branchId,
                'account_id' => $accountId,
            ],
            [
                'amount' => number_format((float) $data['amount'], 2, '.', ''),
                'effective_date' => $data['effective_date'],
                'set_by' => $user->id,
            ]
        );

        return response()->json([
            'message' => 'Saldo awal berhasil disimpan.',
            'data' => $row->load('account:id,name,code'),
        ]);
    }
}
