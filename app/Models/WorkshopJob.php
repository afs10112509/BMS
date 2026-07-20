<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'branch_id',
    'employee_id',
    'job_date',
    'job_type',
    'amount',
    'note',
    'input_by',
])]
class WorkshopJob extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    protected function casts(): array
    {
        return [
            'job_date' => 'date',
            'amount' => 'decimal:2',
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

    public function inputter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'input_by');
    }
}
