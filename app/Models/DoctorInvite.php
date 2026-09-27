<?php

namespace App\Models;

use Database\Factories\DoctorInviteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoctorInvite extends Model
{
    /** @use HasFactory<DoctorInviteFactory> */
    use \App\Traits\BelongsToHospital, HasFactory;

    protected $fillable = [
        'doctor_id',
        'token',
        'expires_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }
}
