<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'branch_id',
    'employee_id',
    'year',
    'month',
    'status',
    'position_snapshot',
    'is_promotor',
    'is_technician',
    'present_days',
    'gapok',
    'closing_qty',
    'insentif_hp',
    'service_profit',
    'service_incentive',
    'insentif_acc',
    'bonus_absen',
    'hutang',
    'pengeluaran',
    'total',
    'note',
    'calculated_at',
    'locked_at',
    'locked_by',
    'paid_at',
    'paid_by',
    'input_by',
])]
class Payroll extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_LOCKED = 'locked';

    public const GAPOK_RATE = 50000;

    public const HP_RATE = 10000;

    public const SERVICE_PCT = 0.5;

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'is_promotor' => 'boolean',
            'is_technician' => 'boolean',
            'present_days' => 'integer',
            'gapok' => 'decimal:2',
            'closing_qty' => 'integer',
            'insentif_hp' => 'decimal:2',
            'service_profit' => 'decimal:2',
            'service_incentive' => 'decimal:2',
            'insentif_acc' => 'decimal:2',
            'bonus_absen' => 'decimal:2',
            'hutang' => 'decimal:2',
            'pengeluaran' => 'decimal:2',
            'total' => 'decimal:2',
            'calculated_at' => 'datetime',
            'locked_at' => 'datetime',
            'paid_at' => 'datetime',
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

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function inputter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'input_by');
    }

    public function isLocked(): bool
    {
        return $this->status === self::STATUS_LOCKED;
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    public static function normalizePosition(?string $position): string
    {
        return mb_strtolower(trim((string) $position));
    }

    public static function isPromotorPosition(?string $position): bool
    {
        return str_contains(self::normalizePosition($position), 'promotor');
    }

    public static function isTechnicianPosition(?string $position): bool
    {
        return str_contains(self::normalizePosition($position), 'teknisi');
    }

    public static function computeTotal(
        float|int|string $gapok,
        float|int|string $insentifHp,
        float|int|string $serviceIncentive,
        float|int|string $insentifAcc,
        float|int|string $bonusAbsen,
        float|int|string $hutang,
        float|int|string $pengeluaran,
    ): string {
        $credits = Money::add($gapok, $insentifHp, $serviceIncentive, $insentifAcc, $bonusAbsen);
        $debits = Money::add($hutang, $pengeluaran);

        return Money::sub($credits, $debits);
    }
}
