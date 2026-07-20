<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'branch_id',
    'week_start',
    'week_end',
    'status',
    'tech_share_pct_snapshot',
    'shares_snapshot',
    'paid_at',
    'paid_by',
])]
class WorkshopWeek extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_PAID = 'paid';

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'week_end' => 'date',
            'tech_share_pct_snapshot' => 'decimal:2',
            'shares_snapshot' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
