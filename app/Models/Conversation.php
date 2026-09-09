<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        'session_id',
        'user_message',
        'ai_response',
        'confidence_score',
        'channel',
        'escalated',
    ];

    protected $casts = [
        'confidence_score' => 'float',
        'escalated' => 'boolean',
    ];

    public function escalations(): HasMany
    {
        return $this->hasMany(Escalation::class, 'session_id', 'session_id');
    }

    public function appointmentRequests(): HasMany
    {
        return $this->hasMany(AppointmentRequest::class, 'session_id', 'session_id');
    }
}