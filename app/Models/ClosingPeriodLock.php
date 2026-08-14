<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'branch_id',
    'year',
    'month',
    'is_locked',
    'locked_by',
    'locked_at',
])]
class ClosingPeriodLock extends Model
{
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'is_locked' => 'boolean',
            'locked_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public static function isBranchPeriodLocked(int $branchId, int $year, int $month): bool
    {
        return static::query()
            ->where('branch_id', $branchId)
            ->where('year', $year)
            ->where('month', $month)
            ->where('is_locked', true)
            ->exists();
    }
}
