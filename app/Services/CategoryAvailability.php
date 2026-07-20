<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Support\Collection;

class CategoryAvailability
{
    /**
     * Kategori yang boleh dipakai cabang: global + lokal cabang tersebut.
     *
     * @return Collection<int, Category>
     */
    public function forBranch(?int $branchId, bool $includeInactive = false): Collection
    {
        $query = Category::query()
            ->with('branch:id,name')
            ->allowedForBranch($branchId)
            ->orderBy('type')
            ->orderBy('name');

        if (! $includeInactive) {
            $query->active();
        }

        return $query->get();
    }

    public function isAllowed(int $branchId, int $categoryId): bool
    {
        return Category::query()
            ->whereKey($categoryId)
            ->active()
            ->allowedForBranch($branchId)
            ->exists();
    }
}
