<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy(BranchScope::class)]
#[Fillable(['branch_id', 'invoice_number', 'sale_date', 'customer_name', 'customer_phone', 'subtotal', 'discount', 'tax', 'total', 'payment_method', 'status', 'created_by', 'notes'])]
class Sale extends Model
{
    protected $casts = [
        'sale_date' => 'date',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public static function generateInvoiceNumber(int $branchId): string
    {
        $prefix = 'INV-' . str_pad($branchId, 2, '0', STR_PAD_LEFT);
        $date = now()->format('Ymd');
        $lastSale = static::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('invoice_number', 'like', "{$prefix}-{$date}-%")
            ->orderByDesc('id')
            ->first();

        $seq = 1;
        if ($lastSale) {
            $parts = explode('-', $lastSale->invoice_number);
            $seq = ((int) end($parts)) + 1;
        }

        return "{$prefix}-{$date}-" . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
