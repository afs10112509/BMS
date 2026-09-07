<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'branch_id',
    'name',
    'nickname',
    'nik',
    'gender',
    'phone',
    'birth_place',
    'birth_date',
    'bank_name',
    'bank_account_name',
    'bank_account_number',
    'emergency_contact',
    'address',
    'position',
    'positions',
    'status',
    'joined_at',
    'notes',
    'kasbon_category_id',
])]
class Employee extends Model
{
    public const POS_OWNER = 'owner';

    public const POS_PIC = 'pic';

    public const POS_KASIR = 'kasir';

    public const POS_PROMOTOR = 'promotor';

    public const POS_FRONTLINER = 'fronliner';

    public const POS_TEKNISI = 'teknisi';

    public const GENDER_MALE = 'male';

    public const GENDER_FEMALE = 'female';

    /** @var list<string> */
    public const GENDER_CODES = [
        self::GENDER_MALE,
        self::GENDER_FEMALE,
    ];

    /** @var array<string, string> */
    public const GENDER_LABELS = [
        self::GENDER_MALE => 'Laki-laki',
        self::GENDER_FEMALE => 'Perempuan',
    ];

    /** @var list<string> */
    public const POSITION_CODES = [
        self::POS_OWNER,
        self::POS_PIC,
        self::POS_KASIR,
        self::POS_PROMOTOR,
        self::POS_FRONTLINER,
        self::POS_TEKNISI,
    ];

    /** @var list<string> Jabatan yang boleh dipilih sebagai penerima pengingat (bukan Owner). */
    public const REMINDER_POSITION_CODES = [
        self::POS_PIC,
        self::POS_KASIR,
        self::POS_PROMOTOR,
        self::POS_FRONTLINER,
        self::POS_TEKNISI,
    ];

    /** @var array<string, string> */
    public const POSITION_LABELS = [
        self::POS_OWNER => 'Owner',
        self::POS_PIC => 'PIC',
        self::POS_KASIR => 'Kasir',
        self::POS_PROMOTOR => 'Promotor',
        self::POS_FRONTLINER => 'Fronliner',
        self::POS_TEKNISI => 'Teknisi',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'date:Y-m-d',
            'birth_date' => 'date:Y-m-d',
            'positions' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Employee $employee) {
            $employee->positions = self::normalizePositions($employee->positions ?? []);
            $label = self::labelsFor($employee->positions);
            $employee->position = $label !== '' ? $label : null;
        });
    }

    /**
     * @param  mixed  $positions
     * @return list<string>
     */
    public static function normalizePositions(mixed $positions): array
    {
        if (! is_array($positions)) {
            return [];
        }

        $codes = [];
        foreach ($positions as $item) {
            $code = mb_strtolower(trim((string) $item));
            if (in_array($code, self::POSITION_CODES, true)) {
                $codes[] = $code;
            }
        }

        $ordered = [];
        foreach (self::POSITION_CODES as $code) {
            if (in_array($code, $codes, true)) {
                $ordered[] = $code;
            }
        }

        return $ordered;
    }

    /**
     * @param  list<string>  $codes
     */
    public static function labelsFor(array $codes): string
    {
        $out = [];
        foreach (self::POSITION_CODES as $code) {
            if (in_array($code, $codes, true)) {
                $out[] = self::POSITION_LABELS[$code];
            }
        }

        return implode(', ', $out);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function positionOptions(): array
    {
        $options = [];
        foreach (self::POSITION_CODES as $code) {
            $options[] = [
                'value' => $code,
                'label' => self::POSITION_LABELS[$code],
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function genderOptions(): array
    {
        $options = [];
        foreach (self::GENDER_CODES as $code) {
            $options[] = [
                'value' => $code,
                'label' => self::GENDER_LABELS[$code],
            ];
        }

        return $options;
    }

    public function genderLabel(): ?string
    {
        if ($this->gender === null || $this->gender === '') {
            return null;
        }

        return self::GENDER_LABELS[$this->gender] ?? $this->gender;
    }

    public function hasPosition(string $code): bool
    {
        return in_array(mb_strtolower(trim($code)), $this->positions ?? [], true);
    }

    /**
     * @param  list<string>  $codes
     */
    public function hasAnyPosition(array $codes): bool
    {
        foreach (self::normalizePositions($codes) as $code) {
            if ($this->hasPosition($code)) {
                return true;
            }
        }

        return false;
    }

    public function isPromotor(): bool
    {
        return $this->hasPosition(self::POS_PROMOTOR);
    }

    public function isTechnician(): bool
    {
        return $this->hasPosition(self::POS_TEKNISI);
    }

    public function isManagement(): bool
    {
        return $this->hasPosition(self::POS_OWNER) || $this->hasPosition(self::POS_PIC);
    }

    /**
     * Karyawan non-manajemen (bukan Owner/PIC) — dipakai ringkasan tertentu yang memang mengecualikan PIC.
     */
    public function scopeWithoutManagement(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('positions')
                ->orWhere(function (Builder $inner) {
                    $inner->whereJsonDoesntContain('positions', self::POS_OWNER)
                        ->whereJsonDoesntContain('positions', self::POS_PIC);
                });
        });
    }

    /**
     * Sembunyikan jabatan Owner saja (PIC tetap tampil).
     * Dipakai: absensi, target closingan, upah bengkel.
     */
    public function scopeWithoutOwner(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('positions')
                ->orWhereJsonDoesntContain('positions', self::POS_OWNER);
        });
    }

    public function scopeWithPosition(Builder $query, string $code): Builder
    {
        $column = $query->getModel()->getTable().'.positions';

        return $query->whereJsonContains($column, mb_strtolower(trim($code)));
    }

    /**
     * @param  list<string>  $codes
     */
    public function scopeWithAnyPosition(Builder $query, array $codes): Builder
    {
        $codes = self::normalizePositions($codes);
        if ($codes === []) {
            return $query->whereRaw('1 = 0');
        }

        $column = $query->getModel()->getTable().'.positions';

        return $query->where(function (Builder $q) use ($codes, $column) {
            foreach ($codes as $code) {
                $q->orWhereJsonContains($column, $code);
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function kasbonCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'kasbon_category_id');
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

    public function userAccount(): HasOne
    {
        return $this->hasOne(User::class, 'employee_id');
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
