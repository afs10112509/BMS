<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['branch_id', 'name', 'type', 'is_active'])]
class Category extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeAllowedForBranch(Builder $query, ?int $branchId): Builder
    {
        return $query->where(function (Builder $w) use ($branchId) {
            $w->whereNull('branch_id');
            if ($branchId) {
                $w->orWhere('branch_id', $branchId);
            }
        });
    }

    public function isSystem(): bool
    {
        return str_starts_with($this->name, 'Transfer')
            || str_starts_with($this->name, 'Penyesuaian');
    }
}
