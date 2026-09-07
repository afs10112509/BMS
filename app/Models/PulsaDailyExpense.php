<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'pulsa_daily_sheet_id',
    'name',
    'amount',
    'sort_order',
])]
class PulsaDailyExpense extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(PulsaDailySheet::class, 'pulsa_daily_sheet_id');
    }
}
