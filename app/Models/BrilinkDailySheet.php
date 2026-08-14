<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'branch_id',
    'sheet_date',
    'previous_total',
    'total_amount',
    'profit',
    'note',
    'input_by',
])]
class BrilinkDailySheet extends Model
{
    protected function casts(): array
    {
        return [
            'sheet_date' => 'date:Y-m-d',
            'previous_total' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'profit' => 'decimal:2',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function inputter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'input_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BrilinkDailyLine::class)->orderBy('sort_order')->orderBy('id');
    }
}
