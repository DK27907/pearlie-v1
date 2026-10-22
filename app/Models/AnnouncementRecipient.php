<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnouncementRecipient extends Model
{
    protected $fillable = [
        'announcement_id',
        'appointment_request_id',
        'recipient_name',
        'recipient_phone',
        'rendered_message',
        'sent_at',
        'delivery_status',
        'provider_message_id',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(AppointmentRequest::class, 'appointment_request_id');
    }
}
