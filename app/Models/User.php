<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['branch_id', 'employee_id', 'name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
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

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(Reconciliation::class);
    }

    public function serviceRecords(): HasMany
    {
        return $this->hasMany(ServiceRecord::class);
    }

    public function requestedTransfers(): HasMany
    {
        return $this->hasMany(InterBranchTransfer::class, 'requested_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isEmployee(): bool
    {
        return $this->role === 'employee';
    }

    public function isPicEmployee(): bool
    {
        if (! $this->isEmployee()) {
            return false;
        }
        $employee = $this->relationLoaded('employee') ? $this->employee : $this->employee()->first();

        return $employee?->hasPosition(Employee::POS_PIC) ?? false;
    }

    /** PIC di cabang bengkel (boleh lihat laporan upah cabangnya). */
    public function isWorkshopPicEmployee(): bool
    {
        if (! $this->isPicEmployee()) {
            return false;
        }

        $branch = $this->picEmployeeBranch();

        return $branch?->isWorkshop() ?? false;
    }

    /** PIC di cabang konter (boleh input keuntungan pulsa cabangnya). */
    public function isCounterPicEmployee(): bool
    {
        if (! $this->isPicEmployee()) {
            return false;
        }

        $branch = $this->picEmployeeBranch();

        return $branch ? ! $branch->isWorkshop() : false;
    }

    protected function picEmployeeBranch(): ?Branch
    {
        $employee = $this->relationLoaded('employee') ? $this->employee : $this->employee()->first();
        if (! $employee) {
            return null;
        }

        $branch = $employee->relationLoaded('branch')
            ? $employee->branch
            : $employee->branch()->with('branchType')->first();

        if ($branch && ! $branch->relationLoaded('branchType')) {
            $branch->load('branchType');
        }

        return $branch;
    }

    /** Admin cabang bengkel (boleh lihat laporan upah cabangnya). */
    public function isWorkshopAdmin(): bool
    {
        if (! $this->isAdmin() || ! $this->branch_id) {
            return false;
        }

        $branch = $this->relationLoaded('branch')
            ? $this->branch
            : $this->branch()->with('branchType')->first();

        if ($branch && ! $branch->relationLoaded('branchType')) {
            $branch->load('branchType');
        }

        return $branch?->isWorkshop() ?? false;
    }

    public function employeeBranchId(): ?int
    {
        if (! $this->isEmployee()) {
            return $this->branch_id ? (int) $this->branch_id : null;
        }

        $employee = $this->relationLoaded('employee') ? $this->employee : $this->employee()->first();

        return $employee?->branch_id ? (int) $employee->branch_id : null;
    }
}
