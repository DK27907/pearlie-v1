<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppointmentRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'name',
        'phone',
        'preferred_date',
        'reason',
        'raw_message',
        'status',
    ];

    protected $casts = [
        'preferred_date' => 'date',
    ];
}
