<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cashflow_workbook_id',
    'type',
    'name',
    'amount',
    'category_id',
    'source',
    'sort_order',
])]
class CashflowWorkbookLine extends Model
{
    public const TYPE_INCOME = 'income';

    public const TYPE_EXPENSE = 'expense';

    public const SOURCE_TRANSACTION = 'transaction';

    public const SOURCE_WORKSHOP_SHOP = 'workshop_shop';

    public const SOURCE_MANUAL = 'manual';

    public const SHOP_LINE_NAME = 'Bagian Toko Upah';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function workbook(): BelongsTo
    {
        return $this->belongsTo(CashflowWorkbook::class, 'cashflow_workbook_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
