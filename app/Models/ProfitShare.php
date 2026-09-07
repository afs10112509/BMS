<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'branch_id',
    'year',
    'month',
    'status',
    'pic_name',
    'pic_employee_id',
    'pic_share_pct',
    'total_income',
    'total_expense',
    'net_profit',
    'pic_amount',
    'note',
    'locked_at',
    'locked_by',
    'input_by',
])]
class ProfitShare extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_LOCKED = 'locked';

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'pic_employee_id' => 'integer',
            'pic_share_pct' => 'decimal:2',
            'total_income' => 'decimal:2',
            'total_expense' => 'decimal:2',
            'net_profit' => 'decimal:2',
            'pic_amount' => 'decimal:2',
            'locked_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function picEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'pic_employee_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProfitShareLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function inputBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'input_by');
    }

    public function isLocked(): bool
    {
        return $this->status === self::STATUS_LOCKED;
    }
}
