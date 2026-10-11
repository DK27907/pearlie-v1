<?php

namespace App\Models;

use App\Traits\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentHistory extends Model
{
    use BelongsToHospital;

    protected $fillable = [
        'hospital_id',
        'appointment_request_id',
        'actor_id',
        'actor_role',
        'action',
        'old_date',
        'new_date',
        'old_doctor_id',
        'new_doctor_id',
        'old_start_time',
        'new_start_time',
        'reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'old_date' => 'date',
            'new_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(AppointmentRequest::class, 'appointment_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
