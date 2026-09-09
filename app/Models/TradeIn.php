<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TradeIn extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'traded_product_name',
        'traded_serial_number',
        'appraised_value',
        'notes',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
}
