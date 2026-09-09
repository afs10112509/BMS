<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerPoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'source_type',
        'source_id',
        'points',
        'type',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'date',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
