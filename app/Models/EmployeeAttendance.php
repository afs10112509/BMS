<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'attendance_date', 'status', 'note', 'input_by'])]
class EmployeeAttendance extends Model
{
    public const STATUS_PRESENT = 'present';

    public const STATUS_LEAVE = 'leave';

    public const STATUS_SICK = 'sick';

    public const STATUS_ABSENT = 'absent';

    public const STATUSES = [
        self::STATUS_PRESENT,
        self::STATUS_LEAVE,
        self::STATUS_SICK,
        self::STATUS_ABSENT,
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function inputter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'input_by');
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::STATUS_PRESENT => 'Hadir',
            self::STATUS_LEAVE => 'Izin',
            self::STATUS_SICK => 'Sakit',
            self::STATUS_ABSENT => 'Alpha',
            default => $status,
        };
    }

    public static function short(string $status): string
    {
        return match ($status) {
            self::STATUS_PRESENT => 'H',
            self::STATUS_LEAVE => 'I',
            self::STATUS_SICK => 'S',
            self::STATUS_ABSENT => 'A',
            default => '?',
        };
    }
}
