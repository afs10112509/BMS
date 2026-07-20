<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'type', 'address', 'status'])]
class Branch extends Model
{
    public const TYPE_KONTER = 'konter';

    public const TYPE_BENGKEL = 'bengkel';

    protected $appends = ['type_label', 'allows_service'];

    public function branchType(): BelongsTo
    {
        return $this->belongsTo(BranchType::class, 'type', 'code');
    }

    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'account_branch');
    }

    public function getTypeLabelAttribute(): string
    {
        return $this->branchType?->name ?? (string) $this->type;
    }

    public function getAllowsServiceAttribute(): bool
    {
        return (bool) ($this->branchType?->allows_service ?? true);
    }

    public function isWorkshop(): bool
    {
        return ! $this->allows_service;
    }

    public function allowsService(): bool
    {
        return $this->allows_service;
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function serviceRecords(): HasMany
    {
        return $this->hasMany(ServiceRecord::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(Reconciliation::class);
    }

    public function periodLocks(): HasMany
    {
        return $this->hasMany(PeriodLock::class);
    }

    public function outgoingTransfers(): HasMany
    {
        return $this->hasMany(InterBranchTransfer::class, 'from_branch_id');
    }

    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(InterBranchTransfer::class, 'to_branch_id');
    }
}
