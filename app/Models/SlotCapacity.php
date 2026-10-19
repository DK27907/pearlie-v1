<?php

namespace App\Models;

use App\Traits\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlotCapacity extends Model
{
    use BelongsToHospital;

    protected $table = 'slot_capacity';

    protected $fillable = [
        'hospital_id',
        'doctor_id',
        'slot_date',
        'slot_time',
        'capacity',
        'booked_count',
    ];

    protected function casts(): array
    {
        return [
            'slot_date' => 'date',
            'capacity' => 'integer',
            'booked_count' => 'integer',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }
}
