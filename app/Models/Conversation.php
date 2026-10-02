<?php

namespace App\Models;

use App\Traits\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use BelongsToHospital, HasFactory;

    protected $fillable = [
        'session_id',
        'user_message',
        'ai_response',
        'confidence_score',
        'channel',
        'escalated',
        'chat_state',
        'chat_state_updated_at',
    ];

    protected $casts = [
        'confidence_score' => 'float',
        'escalated' => 'boolean',
        'chat_state' => 'array',
        'chat_state_updated_at' => 'datetime',
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
