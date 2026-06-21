<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    const REFERENCE_PREFIX = 'BK-';
    const STATUS_CONFIRMED = 'confirmed';

    protected $fillable = [
        'reference',
        'flight_id',
        'flight_data',
        'passengers',
        'total_price',
        'currency',
        'status',
    ];

    protected $casts = [
        'flight_data' => 'array',
        'passengers'  => 'array',
        'total_price' => 'float',
    ];
}
