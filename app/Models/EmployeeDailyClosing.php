<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'closing_date', 'qty', 'input_by'])]
class EmployeeDailyClosing extends Model
{
    protected function casts(): array
    {
        return [
            'closing_date' => 'date',
            'qty' => 'integer',
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
}
