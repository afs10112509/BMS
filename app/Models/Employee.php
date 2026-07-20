<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'branch_id',
    'name',
    'phone',
    'position',
    'status',
    'joined_at',
    'notes',
])]
class Employee extends Model
{
    protected function casts(): array
    {
        return [
            'joined_at' => 'date',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function serviceRecords(): HasMany
    {
        return $this->hasMany(ServiceRecord::class);
    }

    public function dailyClosings(): HasMany
    {
        return $this->hasMany(EmployeeDailyClosing::class);
    }

    public function monthlyTargets(): HasMany
    {
        return $this->hasMany(EmployeeMonthlyTarget::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(EmployeeAttendance::class);
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
