<?php

namespace App\Models;

use App\Traits\BelongsToHospital;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlotHold extends Model
{
    use BelongsToHospital;

    protected $fillable = [
        'hospital_id',
        'doctor_id',
        'appointment_request_id',
        'slot_start_at',
        'slot_end_at',
        'expires_at',
    ];

    protected $casts = [
        'slot_start_at' => 'datetime',
        'slot_end_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(AppointmentRequest::class, 'appointment_request_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }
}
