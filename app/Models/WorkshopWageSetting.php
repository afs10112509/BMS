<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'branch_id',
    'employee_id',
    'year',
    'month',
    'tech_share_pct',
    'set_by',
])]
class WorkshopWageSetting extends Model
{
    public const DEFAULT_TECH_SHARE_PCT = 50.0;

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'tech_share_pct' => 'decimal:2',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
