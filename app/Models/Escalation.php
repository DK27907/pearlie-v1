<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Escalation extends Model
{
    use \App\Traits\BelongsToHospital, HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'session_id',
        'user_message',
        'ai_response',
        'status',
        'assigned_to',
        'assigned_worker_id',
        'claimed_at',
        'resolved_by_id',
        'resolved_at',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function assignedWorker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_worker_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'session_id', 'session_id');
    }

    public function appointmentRequests(): HasMany
    {
        return $this->hasMany(AppointmentRequest::class, 'session_id', 'session_id');
    }

    public function latestConversation(): HasOne
    {
        return $this->hasOne(Conversation::class, 'session_id', 'session_id')->latestOfMany();
    }

    public function latestScoredConversation(): HasOne
    {
        return $this->hasOne(Conversation::class, 'session_id', 'session_id')
            ->whereNotNull('confidence_score')
            ->latestOfMany();
    }

    public function latestAppointment(): HasOne
    {
        return $this->hasOne(AppointmentRequest::class, 'session_id', 'session_id')->latestOfMany();
    }

    public function getPatientNameAttribute(): string
    {
        return $this->latestAppointment?->name ?: 'Patient';
    }

    public function getPatientPhoneAttribute(): ?string
    {
        $phone = $this->latestAppointment?->mpesa_phone ?: $this->latestAppointment?->phone;

        if ($phone) {
            return $phone;
        }

        return str_starts_with($this->session_id, 'whatsapp:')
            ? substr($this->session_id, strlen('whatsapp:'))
            : null;
    }

    public function getConfidenceScoreAttribute(): ?float
    {
        $score = $this->latestScoredConversation?->confidence_score;

        return $score === null ? null : (float) $score;
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_IN_PROGRESS,
            self::STATUS_RESOLVED,
            self::STATUS_CLOSED,
        ];
    }
}
