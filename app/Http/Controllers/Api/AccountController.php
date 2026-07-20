<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\InterBranchTransfer;
use App\Models\Transaction;
use App\Services\AccountAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function __construct(private AccountAvailability $availability)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($request->boolean('all') && $user->isOwner()) {
            $usedIds = $this->usedAccountIds();

            $accounts = Account::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(function (Account $account) use ($usedIds) {
                    $account->setAttribute('in_use', $usedIds->contains($account->id));

                    return $account;
                });

            return response()->json([
                'message' => 'Daftar semua akun berhasil diambil.',
                'data' => $accounts,
            ]);
        }

        $branchId = null;
        if ($user->isAdmin()) {
            $branchId = (int) $user->branch_id;
        } elseif ($request->filled('branch_id')) {
            $branchId = $request->integer('branch_id');
        } elseif ($user->branch_id) {
            $branchId = (int) $user->branch_id;
        }

        $accounts = $this->availability->forBranch($branchId);

        return response()->json([
            'message' => 'Daftar akun berhasil diambil.',
            'data' => $accounts,
            'meta' => [
                'branch_id' => $branchId,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validated($request);

        $branchId = null;
        if ($user->isAdmin()) {
            $branchId = (int) $user->branch_id;
            if (! $branchId) {
                return response()->json([
                    'message' => 'Admin harus terikat ke cabang untuk menambah akun.',
                ], 422);
            }
        }

        $existing = Account::query()->where('code', $data['code'])->first();

        if ($existing) {
            if ($user->isOwner()) {
                return response()->json([
                    'message' => 'Kode akun sudah dipakai.',
                    'errors' => ['code' => ['Kode akun sudah dipakai.']],
                ], 422);
            }

            if (! $existing->is_active) {
                return response()->json([
                    'message' => 'Akun dengan kode ini ada tetapi nonaktif. Hubungi pemilik.',
                ], 422);
            }

            $this->availability->attachToBranch($branchId, (int) $existing->id);

            return response()->json([
                'message' => 'Akun sudah ada di sistem dan kini dipasang ke cabang Anda.',
                'data' => $existing->fresh(),
            ], 200);
        }

        $account = Account::query()->create([
            'name' => $data['name'],
            'code' => $data['code'],
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? ((int) Account::query()->max('sort_order') + 1),
        ]);

        if ($branchId) {
            $this->availability->attachToBranch($branchId, (int) $account->id);
        }

        $account->setAttribute('in_use', false);

        return response()->json([
            'message' => $branchId
                ? 'Akun berhasil ditambahkan dan dipasang ke cabang Anda.'
                : 'Akun berhasil ditambahkan.',
            'data' => $account,
        ], 201);
    }

    public function update(Request $request, Account $account): JsonResponse
    {
        $data = $this->validated($request, $account);

        $account->fill($data);
        $account->save();
        $account->setAttribute('in_use', $this->isInUse($account));

        return response()->json([
            'message' => 'Akun berhasil diperbarui.',
            'data' => $account->fresh(),
        ]);
    }

    public function destroy(Account $account): JsonResponse
    {
        if ($this->isInUse($account)) {
            return response()->json([
                'message' => 'Akun tidak dapat dihapus karena sudah digunakan di transaksi atau transfer. Nonaktifkan saja.',
            ], 422);
        }

        $account->branchTypes()->detach();
        $account->branches()->detach();
        $account->delete();

        return response()->json([
            'message' => 'Akun berhasil dihapus.',
        ]);
    }

    protected function isInUse(Account $account): bool
    {
        return $this->usedAccountIds()->contains($account->id);
    }

    /** @return \Illuminate\Support\Collection<int, int> */
    protected function usedAccountIds()
    {
        $fromTx = Transaction::query()->whereNotNull('account_id')->distinct()->pluck('account_id');
        $fromTransfer = InterBranchTransfer::query()->whereNotNull('account_id')->distinct()->pluck('account_id');

        return $fromTx->merge($fromTransfer)->map(fn ($id) => (int) $id)->unique()->values();
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Account $existing = null): array
    {
        $user = $request->user();
        $codeRules = [
            $existing ? 'sometimes' : 'required',
            'string',
            'max:32',
            'regex:/^[a-z][a-z0-9_]*$/',
        ];

        // Owner: kode harus unik. Admin: boleh pakai kode yang sudah ada → ditautkan ke cabang.
        if ($existing || ($user && $user->isOwner())) {
            $codeRules[] = Rule::unique('accounts', 'code')->ignore($existing?->id);
        }

        $data = $request->validate([
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:100'],
            'code' => $codeRules,
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ]);

        if (isset($data['code'])) {
            $data['code'] = Str::lower($data['code']);
        }

        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }

        return $data;
    }
}
