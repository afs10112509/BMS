<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'branch_id',
    'name',
    'default_amount',
    'status',
    'sort_order',
    'created_by',
])]
class WorkshopJobType extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /** @var list<string> */
    public const DEFAULT_NAMES = [
        'ONGKER',
        'GANTI OLI',
        'GANTI BAN DALAM',
        'GANTI KAMPAS',
        'GANTI LAHAR',
        'TUBLES',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    protected function casts(): array
    {
        return [
            'default_amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public static function normalizeName(string $name): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $name) ?? ''));
    }

    /**
     * @return array{id:int,name:string,default_amount:float|null,status:string,sort_order:int}
     */
    public function toCatalogPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'default_amount' => $this->default_amount !== null ? (float) $this->default_amount : null,
            'status' => $this->status,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
