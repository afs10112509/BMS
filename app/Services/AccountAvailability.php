<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Branch;
use Illuminate\Support\Collection;

class AccountAvailability
{
    /**
     * Akun yang boleh dipakai cabang.
     * Prioritas: override per-cabang (jika ada baris) → default tipe → semua akun aktif.
     *
     * @return Collection<int, Account>
     */
    public function forBranch(?int $branchId): Collection
    {
        $base = Account::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name');

        if (! $branchId) {
            return $base->get();
        }

        $branch = Branch::query()->with('branchType')->find($branchId);
        if (! $branch) {
            return $base->get();
        }

        if ($branch->accounts()->exists()) {
            return $branch->accounts()
                ->where('accounts.is_active', true)
                ->orderBy('accounts.sort_order')
                ->orderBy('accounts.name')
                ->get();
        }

        $type = $branch->branchType;
        if ($type && $type->accounts()->exists()) {
            return $type->accounts()
                ->where('accounts.is_active', true)
                ->orderBy('accounts.sort_order')
                ->orderBy('accounts.name')
                ->get();
        }

        return $base->get();
    }

    public function isAllowed(int $branchId, int $accountId): bool
    {
        $branch = Branch::query()->with('branchType')->find($branchId);
        if (! $branch) {
            return Account::query()->whereKey($accountId)->where('is_active', true)->exists();
        }

        if ($branch->accounts()->exists()) {
            return $branch->accounts()
                ->where('accounts.id', $accountId)
                ->where('accounts.is_active', true)
                ->exists();
        }

        $type = $branch->branchType;
        if ($type && $type->accounts()->exists()) {
            return $type->accounts()
                ->where('accounts.id', $accountId)
                ->where('accounts.is_active', true)
                ->exists();
        }

        return Account::query()->whereKey($accountId)->where('is_active', true)->exists();
    }

    /**
     * @return array{mode: string, account_ids: list<int>, type_account_ids: list<int>}
     */
    public function settingsForBranch(Branch $branch): array
    {
        $branch->loadMissing('branchType');

        $typeIds = $branch->branchType
            ? $branch->branchType->accounts()->pluck('accounts.id')->map(fn ($id) => (int) $id)->values()->all()
            : [];

        $hasOverride = $branch->accounts()->exists();
        $branchIds = $hasOverride
            ? $branch->accounts()->pluck('accounts.id')->map(fn ($id) => (int) $id)->values()->all()
            : [];

        return [
            'mode' => $hasOverride ? 'custom' : 'type',
            'account_ids' => $hasOverride ? $branchIds : $typeIds,
            'type_account_ids' => $typeIds,
        ];
    }

    /**
     * Pasang akun ke cabang (mode custom), mempertahankan akun yang sudah boleh dipakai.
     */
    public function attachToBranch(int $branchId, int $accountId): void
    {
        $branch = Branch::query()->with('branchType')->findOrFail($branchId);
        $settings = $this->settingsForBranch($branch);

        if ($settings['mode'] === 'custom') {
            $ids = $settings['account_ids'];
        } elseif ($settings['type_account_ids'] !== []) {
            $ids = $settings['type_account_ids'];
        } else {
            $ids = $this->forBranch($branchId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        if (! in_array($accountId, $ids, true)) {
            $ids[] = $accountId;
        }

        $branch->accounts()->sync($ids);
    }
}
