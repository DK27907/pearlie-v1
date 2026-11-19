<?php

namespace App\Models;

use App\Traits\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use BelongsToHospital, HasFactory;

    protected static function booted(): void
    {
        static::creating(static function (self $conversation): void {
            if (blank($conversation->language)) {
                $stateLanguage = data_get($conversation->chat_state, 'language');
                $languageText = trim((string) $conversation->user_message.' '.(string) $conversation->ai_response);
                $conversation->language = $stateLanguage === 'sw'
                    || preg_match('/\b(habari|hujambo|sijambo|jambo|mambo|miadi|daktari|asante|karibu|tafadhali|samahani|ndiyo|hapana|kesho|leo|huduma|namba|simu)\b/iu', $languageText) === 1
                    ? 'sw'
                    : 'en';
            }
        });
    }

    protected $fillable = [
        'session_id',
        'user_message',
        'ai_response',
        'confidence_score',
        'channel',
        'language',
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
