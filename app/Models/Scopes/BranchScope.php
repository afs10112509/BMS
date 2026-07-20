<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Isolasi multi-tenant: admin hanya melihat baris branch_id miliknya.
 * Owner / guest / queue tanpa user → tidak difilter.
 */
class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if (! $user || ! method_exists($user, 'isAdmin') || ! $user->isAdmin()) {
            return;
        }

        if (! $user->branch_id) {
            // Middleware EnsureAdminHasBranch seharusnya menolak lebih dulu;
            // fallback: pastikan tidak ada baris yang bocor.
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('branch_id'), (int) $user->branch_id);
    }
}
