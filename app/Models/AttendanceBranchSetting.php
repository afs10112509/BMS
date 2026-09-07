<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'branch_id',
    'check_in_start',
    'check_in_end',
    'check_out_start',
    'check_out_end',
    'updated_by',
])]
class AttendanceBranchSetting extends Model
{
    protected function casts(): array
    {
        return [
            // time columns stay as string H:i:s from DB
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public static function defaults(): array
    {
        return [
            'check_in_start' => '07:00:00',
            'check_in_end' => '09:00:00',
            'check_out_start' => '16:00:00',
            'check_out_end' => '20:00:00',
        ];
    }

    public static function forBranch(int $branchId): self
    {
        $row = self::query()->where('branch_id', $branchId)->first();
        if ($row) {
            return $row;
        }

        $row = new self(array_merge(['branch_id' => $branchId], self::defaults()));

        return $row;
    }

    /** @return array{check_in_start:string,check_in_end:string,check_out_start:string,check_out_end:string} */
    public function windowPayload(): array
    {
        return [
            'check_in_start' => substr((string) $this->check_in_start, 0, 5),
            'check_in_end' => substr((string) $this->check_in_end, 0, 5),
            'check_out_start' => substr((string) $this->check_out_start, 0, 5),
            'check_out_end' => substr((string) $this->check_out_end, 0, 5),
        ];
    }
}
