<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'branch_id',
    'employee_id',
    'user_id',
    'service_date',
    'brand',
    'device_type',
    'damage',
    'cost',
    'price',
    'profit',
    'notes',
])]
class ServiceRecord extends Model
{
    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'cost' => 'decimal:2',
            'price' => 'decimal:2',
            'profit' => 'decimal:2',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function calcProfit(float|string $price, float|string $cost): string
    {
        return Money::sub($price, $cost);
    }
}
