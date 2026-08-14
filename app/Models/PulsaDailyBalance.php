<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'pulsa_daily_sheet_id',
    'pulsa_provider_id',
    'provider_name',
    'opening_balance',
    'topup_amount',
    'closing_balance',
    'used_amount',
    'sort_order',
])]
class PulsaDailyBalance extends Model
{
    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'topup_amount' => 'decimal:2',
            'closing_balance' => 'decimal:2',
            'used_amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(PulsaDailySheet::class, 'pulsa_daily_sheet_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(PulsaProvider::class, 'pulsa_provider_id');
    }
}
