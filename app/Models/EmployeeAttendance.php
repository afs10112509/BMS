<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'attendance_date',
    'status',
    'note',
    'input_by',
    'check_in_at',
    'check_out_at',
    'check_in_photo',
    'check_out_photo',
    'self_state',
    'reviewed_at',
    'reviewed_by',
    'review_note',
])]
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

    public const SELF_CHECKED_IN = 'checked_in';

    public const SELF_PENDING_REVIEW = 'pending_review';

    public const SELF_APPROVED_INCOMPLETE = 'approved_incomplete';

    public const SELF_REJECTED = 'rejected';

    public const SELF_COMPLETED = 'completed';

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date:Y-m-d',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'reviewed_at' => 'datetime',
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
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

    public function countsAsPresent(): bool
    {
        return $this->status === self::STATUS_PRESENT;
    }

    /** Absen masuk tanpa pulang yang sudah lewat hari → pending tinjau (C1). */
    public function promoteStaleCheckInToPending(): bool
    {
        if ($this->self_state !== self::SELF_CHECKED_IN) {
            return false;
        }
        if (! $this->check_in_at || $this->check_out_at) {
            return false;
        }
        $today = now()->toDateString();
        if ($this->attendance_date?->toDateString() >= $today) {
            return false;
        }
        $this->self_state = self::SELF_PENDING_REVIEW;
        $this->status = null;
        $this->save();

        return true;
    }
}
