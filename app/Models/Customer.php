<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'customer_type',
        'whatsapp_opt_in',
    ];

    public function points()
    {
        return $this->hasMany(CustomerPoint::class);
    }

    public function messages()
    {
        return $this->hasMany(CustomerMessage::class);
    }
}
