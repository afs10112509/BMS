<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'branch_id',
    'sheet_date',
    'cash_on_hand',
    'total_used_balance',
    'total_expense',
    'total_cash',
    'profit',
    'note',
    'input_by',
])]
class PulsaDailySheet extends Model
{
    protected function casts(): array
    {
        return [
            'sheet_date' => 'date:Y-m-d',
            'cash_on_hand' => 'decimal:2',
            'total_used_balance' => 'decimal:2',
            'total_expense' => 'decimal:2',
            'total_cash' => 'decimal:2',
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

    public function balances(): HasMany
    {
        return $this->hasMany(PulsaDailyBalance::class)->orderBy('sort_order')->orderBy('id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(PulsaDailyExpense::class)->orderBy('sort_order')->orderBy('id');
    }

    public static function calcUsed(float|string $opening, float|string $topup, float|string $closing): float
    {
        return round(((float) $opening + (float) $topup) - (float) $closing, 2);
    }
}
