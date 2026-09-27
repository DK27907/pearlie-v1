<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hospital extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'logo_url',
        'primary_color',
        'secondary_color',
        'address',
        'city',
        'county',
        'phone',
        'emergency_phone',
        'email',
        'website',
        'whatsapp_number',
        'whatsapp_phone_number_id',
        'whatsapp_access_token',
        'whatsapp_app_secret',
        'whatsapp_verify_token',
        'whatsapp_api_version',
        'mpesa_shortcode',
        'mpesa_consumer_key',
        'mpesa_consumer_secret',
        'mpesa_passkey',
        'deposit_amount',
        'slot_duration_minutes',
        'no_show_grace_minutes',
        'hours_emergency',
        'hours_outpatient',
        'default_language',
        'supported_languages',
        'subscription_plan',
        'subscription_status',
        'trial_ends_at',
        'is_active',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'mpesa_consumer_key' => 'encrypted',
            'mpesa_consumer_secret' => 'encrypted',
            'mpesa_passkey' => 'encrypted',
            'whatsapp_access_token' => 'encrypted',
            'whatsapp_app_secret' => 'encrypted',
            'whatsapp_verify_token' => 'encrypted',
            'deposit_amount' => 'decimal:2',
            'supported_languages' => 'array',
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected $hidden = [
        'mpesa_consumer_key',
        'mpesa_consumer_secret',
        'mpesa_passkey',
        'whatsapp_access_token',
        'whatsapp_app_secret',
        'whatsapp_verify_token',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function doctors(): HasMany
    {
        return $this->users()->where(fn ($query) => $query
            ->where('is_doctor', true)
            ->orWhere('role', 'doctor'));
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(AppointmentRequest::class);
    }

    public function knowledgeBases(): HasMany
    {
        return $this->hasMany(KnowledgeBase::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(Escalation::class);
    }

    public function mpesaPayments(): HasMany
    {
        return $this->hasMany(MpesaPayment::class);
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(DoctorAvailability::class);
    }

    public function invites(): HasMany
    {
        return $this->hasMany(Invite::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('subscription_status', '!=', 'suspended');
    }

    public function isSubscribed(): bool
    {
        return $this->is_active
            && $this->subscription_status === 'active';
    }

    public function isOnTrial(): bool
    {
        return $this->is_active
            && $this->subscription_status === 'trial'
            && ($this->trial_ends_at === null || $this->trial_ends_at->isFuture());
    }

    public function hasFeature(string $feature): bool
    {
        $plans = [
            'starter' => ['chat', 'whatsapp', 'knowledge_base'],
            'professional' => ['chat', 'whatsapp', 'knowledge_base', 'booking', 'doctors', 'dashboard'],
            'enterprise' => [
                'chat',
                'whatsapp',
                'knowledge_base',
                'booking',
                'doctors',
                'dashboard',
                'mpesa',
                'sms',
                'escalation',
                'hmis_api',
                'analytics',
            ],
        ];

        return in_array($feature, $plans[$this->subscription_plan] ?? [], true);
    }
}
