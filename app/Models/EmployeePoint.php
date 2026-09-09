<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeePoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'source_type',
        'source_id',
        'points',
        'type',
        'note',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
