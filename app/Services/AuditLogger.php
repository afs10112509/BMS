<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'remember_token',
        'current_password',
    ];

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(
        User $user,
        string $action,
        Model $model,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $branchId = null,
    ): AuditLog {
        return $this->write(
            $user,
            $action,
            $model->getTable(),
            (int) $model->getKey(),
            $oldValues,
            $newValues,
            $branchId ?? $this->resolveBranchId($model, $oldValues, $newValues),
        );
    }

    /**
     * Log tanpa model Eloquent (ringkasan batch).
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function logTable(
        User $user,
        string $action,
        string $tableName,
        int $recordId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $branchId = null,
    ): AuditLog {
        return $this->write(
            $user,
            $action,
            $tableName,
            $recordId,
            $oldValues,
            $newValues,
            $branchId ?? $this->resolveBranchId(null, $oldValues, $newValues),
        );
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    protected function write(
        User $user,
        string $action,
        string $tableName,
        int $recordId,
        ?array $oldValues,
        ?array $newValues,
        ?int $branchId,
    ): AuditLog {
        return AuditLog::query()->create([
            'user_id' => $user->id,
            'branch_id' => $branchId,
            'table_name' => $tableName,
            'record_id' => $recordId,
            'action' => $action,
            'old_values' => $this->sanitize($oldValues),
            'new_values' => $this->sanitize($newValues),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    protected function resolveBranchId(?Model $model, ?array $oldValues, ?array $newValues): ?int
    {
        if ($model !== null && isset($model->branch_id) && $model->branch_id !== null) {
            return (int) $model->branch_id;
        }

        foreach ([$newValues, $oldValues] as $payload) {
            if (is_array($payload) && isset($payload['branch_id']) && $payload['branch_id'] !== null && $payload['branch_id'] !== '') {
                return (int) $payload['branch_id'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    protected function sanitize(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $out = [];
        foreach ($values as $key => $value) {
            if (in_array((string) $key, self::SENSITIVE_KEYS, true)) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
