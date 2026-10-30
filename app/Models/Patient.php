<?php

namespace App\Models;

use App\Traits\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Patient extends Model
{
    use BelongsToHospital, HasFactory;

    protected $fillable = [
        'channel',
        'identity_hash',
        'name',
        'preferred_language',
        'last_interaction_at',
    ];

    protected function casts(): array
    {
        return [
            'last_interaction_at' => 'datetime',
        ];
    }
}
