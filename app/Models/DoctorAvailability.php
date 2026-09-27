<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoctorAvailability extends Model
{
    use \App\Traits\BelongsToHospital, HasFactory;

    protected $table = 'doctor_availabilities';

    protected $fillable = [
        'doctor_id',
        'day_of_week',
        'start_time',
        'end_time',
        'slot_duration_minutes',
        'max_patients_per_slot',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    /**
     * @return array<int, array{start: string, end: string}>
     */
    public function generateSlots(): array
    {
        if ($this->slot_duration_minutes < 1) {
            return [];
        }

        $start = CarbonImmutable::parse($this->start_time);
        $end = CarbonImmutable::parse($this->end_time);
        $slots = [];

        for ($slotStart = $start; $slotStart->addMinutes($this->slot_duration_minutes)->lessThanOrEqualTo($end); $slotStart = $slotStart->addMinutes($this->slot_duration_minutes)) {
            $slotEnd = $slotStart->addMinutes($this->slot_duration_minutes);
            $slots[] = [
                'start' => $slotStart->format('H:i'),
                'end' => $slotEnd->format('H:i'),
            ];
        }

        return $slots;
    }
}
