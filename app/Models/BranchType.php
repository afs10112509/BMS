<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'allows_service', 'status', 'sort_order'])]
class BranchType extends Model
{
    protected function casts(): array
    {
        return [
            'allows_service' => 'boolean',
        ];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class, 'type', 'code');
    }

    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'account_branch_type');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
