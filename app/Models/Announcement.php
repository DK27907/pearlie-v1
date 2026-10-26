<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Announcement extends Model
{
    protected $fillable = [
        'hospital_id',
        'sent_by_user_id',
        'reminder_appointment_id',
        'type',
        'message',
        'audience_filter',
        'channel',
        'scheduled_for',
        'sent_at',
        'recipients_count',
        'status',
    ];

    protected $casts = [
        'audience_filter' => 'array',
        'scheduled_for' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function reminderAppointment(): BelongsTo
    {
        return $this->belongsTo(AppointmentRequest::class, 'reminder_appointment_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(AnnouncementRecipient::class);
    }

    /**
     * @param  array<string, string>  $additionalPlaceholders
     */
    public function renderFor(
        string $patientName,
        string $doctorName,
        string $appointmentTime,
        array $additionalPlaceholders = [],
    ): string {
        $placeholders = [
            '{patient_name}' => $patientName,
            '{doctor_name}' => $doctorName,
            '{appointment_time}' => $appointmentTime,
        ];
        foreach ($additionalPlaceholders as $key => $value) {
            $placeholders['{'.$key.'}'] = $value;
        }

        return strtr((string) $this->message, $placeholders);
    }
}
