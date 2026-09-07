<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'branch_id',
    'table_name',
    'record_id',
    'action',
    'old_values',
    'new_values',
])]
class AuditLog extends Model
{
    /** @var array<string, string> */
    public const TABLE_LABELS = [
        'transactions' => 'Transaksi',
        'inter_branch_transfers' => 'Transfer Antar Cabang',
        'employees' => 'Karyawan',
        'employee_attendances' => 'Absensi',
        'employee_daily_closings' => 'Closing Harian',
        'employee_monthly_targets' => 'Target Closing',
        'closing_period_locks' => 'Kunci Closing',
        'payrolls' => 'Gaji Konter',
        'period_locks' => 'Kunci Periode',
        'users' => 'Akun Login',
    ];

    /** @var array<string, string> */
    public const ACTION_LABELS = [
        'CREATE' => 'Tambah',
        'UPDATE' => 'Ubah',
        'DELETE' => 'Hapus',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function actionLabel(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }

    public function moduleLabel(): string
    {
        return self::TABLE_LABELS[$this->table_name] ?? $this->table_name;
    }

    public function summary(): string
    {
        $module = $this->moduleLabel();
        $action = $this->actionLabel();
        $new = is_array($this->new_values) ? $this->new_values : [];
        $old = is_array($this->old_values) ? $this->old_values : [];

        return match ($this->table_name) {
            'employees' => $this->employeeSummary($action, $old, $new),
            'employee_attendances' => $this->attendanceSummary($action, $old, $new),
            'employee_daily_closings' => $this->closingDailySummary($action, $old, $new),
            'employee_monthly_targets' => $this->closingTargetSummary($action, $old, $new),
            'closing_period_locks' => $this->closingLockSummary($action, $old, $new),
            'payrolls' => $this->payrollSummary($action, $old, $new),
            'period_locks' => $this->periodLockSummary($action, $old, $new),
            'transactions' => $this->transactionSummary($action, $old, $new),
            'inter_branch_transfers' => $this->transferSummary($action, $old, $new),
            'users' => $this->userAccountSummary($action, $old, $new),
            default => "{$action} {$module} #{$this->record_id}",
        };
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected function employeeSummary(string $action, array $old, array $new): string
    {
        $name = (string) ($new['name'] ?? $old['name'] ?? '#'.$this->record_id);

        return "{$action} karyawan {$name}";
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected function attendanceSummary(string $action, array $old, array $new): string
    {
        if (isset($new['batch']) || isset($new['employee_count'])) {
            $date = (string) ($new['date'] ?? '—');
            $count = (int) ($new['employee_count'] ?? 0);

            return "Simpan absensi {$date} ({$count} karyawan)";
        }

        $date = (string) ($new['attendance_date'] ?? $old['attendance_date'] ?? '—');
        $status = (string) ($new['status'] ?? $old['status'] ?? '—');
        $emp = (string) ($new['employee_name'] ?? $old['employee_name'] ?? 'karyawan #'.($new['employee_id'] ?? $old['employee_id'] ?? $this->record_id));

        if ($this->action === 'DELETE') {
            return "Hapus absensi {$emp} ({$date})";
        }

        return "{$action} absensi {$emp} · {$date} · {$status}";
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected function closingDailySummary(string $action, array $old, array $new): string
    {
        $emp = (string) ($new['employee_name'] ?? $old['employee_name'] ?? 'karyawan #'.($new['employee_id'] ?? $old['employee_id'] ?? ''));
        $date = (string) ($new['closing_date'] ?? $old['closing_date'] ?? '—');
        $qty = $new['qty'] ?? $old['qty'] ?? '—';

        return "{$action} closing {$emp} · {$date} · qty {$qty}";
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected function closingTargetSummary(string $action, array $old, array $new): string
    {
        $emp = (string) ($new['employee_name'] ?? $old['employee_name'] ?? 'karyawan #'.($new['employee_id'] ?? $old['employee_id'] ?? ''));
        $ym = sprintf('%04d-%02d', (int) ($new['year'] ?? $old['year'] ?? 0), (int) ($new['month'] ?? $old['month'] ?? 0));
        $target = $new['target'] ?? $old['target'] ?? '—';

        return "{$action} target {$emp} · {$ym} · {$target}";
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected function closingLockSummary(string $action, array $old, array $new): string
    {
        $ym = sprintf('%04d-%02d', (int) ($new['year'] ?? $old['year'] ?? 0), (int) ($new['month'] ?? $old['month'] ?? 0));
        $locked = (bool) ($new['is_locked'] ?? false);

        return ($locked ? 'Kunci' : 'Buka kunci')." closing {$ym}";
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected function payrollSummary(string $action, array $old, array $new): string
    {
        if (isset($new['batch_action'])) {
            $ym = sprintf('%04d-%02d', (int) ($new['year'] ?? 0), (int) ($new['month'] ?? 0));
            $label = match ((string) $new['batch_action']) {
                'save' => 'Simpan draft gaji',
                'lock' => 'Kunci gaji',
                'unlock' => 'Buka kunci gaji',
                'mark_paid' => 'Tandai gaji lunas',
                'mark_unpaid' => 'Batalkan status lunas gaji',
                default => 'Ubah gaji',
            };
            $count = (int) ($new['employee_count'] ?? 0);

            return "{$label} {$ym}".($count > 0 ? " ({$count} karyawan)" : '');
        }

        $emp = (string) ($new['employee_name'] ?? $old['employee_name'] ?? 'karyawan #'.($new['employee_id'] ?? $old['employee_id'] ?? ''));
        $ym = sprintf('%04d-%02d', (int) ($new['year'] ?? $old['year'] ?? 0), (int) ($new['month'] ?? $old['month'] ?? 0));
        $total = $new['total'] ?? $old['total'] ?? null;
        $extra = $total !== null ? ' · total '.$total : '';

        return "{$action} gaji {$emp} · {$ym}{$extra}";
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected function periodLockSummary(string $action, array $old, array $new): string
    {
        $period = (string) ($new['period'] ?? $old['period'] ?? '—');
        $locked = (bool) ($new['is_locked'] ?? false);

        return ($locked ? 'Kunci' : 'Buka kunci')." periode {$period}";
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected function transactionSummary(string $action, array $old, array $new): string
    {
        $amount = $new['amount'] ?? $old['amount'] ?? null;
        $date = (string) ($new['transaction_date'] ?? $old['transaction_date'] ?? '—');
        $desc = trim((string) ($new['description'] ?? $old['description'] ?? ''));
        $parts = ["{$action} transaksi {$date}"];
        if ($amount !== null) {
            $parts[] = (string) $amount;
        }
        if ($desc !== '') {
            $parts[] = mb_strimwidth($desc, 0, 40, '…');
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected function transferSummary(string $action, array $old, array $new): string
    {
        $status = (string) ($new['status'] ?? $old['status'] ?? '');
        $amount = $new['amount'] ?? $old['amount'] ?? null;

        return trim("{$action} transfer {$status}".($amount !== null ? " · {$amount}" : ''));
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected function userAccountSummary(string $action, array $old, array $new): string
    {
        $email = (string) ($new['email'] ?? $old['email'] ?? '—');
        $emp = (string) ($new['employee_name'] ?? $old['employee_name'] ?? '');

        return trim("{$action} akun login {$email}".($emp !== '' ? " ({$emp})" : ''));
    }
}
