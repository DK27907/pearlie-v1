<?php
ECHO is on.
namespace App\Models;
ECHO is on.
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
ECHO is on.
class Hospital extends Model
{
    use HasFactory;
ECHO is on.
    protected $attributes = [
        'supported_languages' => '["en","sw"]',
    ];

    protected $fillable = [
        'name',
        'chatbot_name',
        'site_header_text',
        'site_footer_text',
        'site_logo_path',
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
        'auto_confirm_paid_appointments',
        'business_hours',
        'notification_preferences',
        'no_show_grace_minutes',
        'patient_cancellation_enabled',
        'patient_rescheduling_enabled',
        'cancellation_cutoff_hours',
        'rescheduling_cutoff_hours',
        'late_cancellation_fee',
        'deposits_refundable',
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
ECHO is on.
    protected function casts(): array
    {
        return [
            'mpesa_consumer_key' => 'encrypted',
            'mpesa_consumer_secret' => 'encrypted',
            'mpesa_passkey' => 'encrypted',
            'whatsapp_access_token' => 'encrypted',
            'whatsapp_app_secret' => 'encrypted',
            'whatsapp_verify_token' => 'encrypted',
            'deposit_amount' => 'integer',
            'slot_duration_minutes' => 'integer',
            'auto_confirm_paid_appointments' => 'boolean',
            'business_hours' => 'array',
            'notification_preferences' => 'array',
            'supported_languages' => 'array',
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
            'is_active' => 'boolean',
            'patient_cancellation_enabled' => 'boolean',
            'patient_rescheduling_enabled' => 'boolean',
            'cancellation_cutoff_hours' => 'integer',
            'rescheduling_cutoff_hours' => 'integer',
            'late_cancellation_fee' => 'decimal:2',
            'deposits_refundable' => 'boolean',
        ];
    }
ECHO is on.
    protected $hidden = [
        'mpesa_consumer_key',
        'mpesa_consumer_secret',
        'mpesa_passkey',
        'whatsapp_access_token',
        'whatsapp_app_secret',
        'whatsapp_verify_token',
    ];
ECHO is on.
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
ECHO is on.
    public function doctors(): HasMany
    {
        return $this->users()->where(fn ($query) => $query
            ->where('is_doctor', true)
            ->orWhere('role', 'doctor'));
    }
ECHO is on.
    public function appointments(): HasMany
    {
        return $this->hasMany(AppointmentRequest::class);
    }
ECHO is on.
    public function integrationCredentials(): HasMany
    {
        return $this->hasMany(HospitalIntegrationCredential::class);
    }
ECHO is on.
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }
ECHO is on.
    public function knowledgeBases(): HasMany
    {
        return $this->hasMany(KnowledgeBase::class);
    }
ECHO is on.
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
ECHO is on.
    public function escalations(): HasMany
    {
        return $this->hasMany(Escalation::class);
    }
ECHO is on.
    public function mpesaPayments(): HasMany
    {
        return $this->hasMany(MpesaPayment::class);
    }
ECHO is on.
    public function availabilities(): HasMany
    {
        return $this->hasMany(DoctorAvailability::class);
    }
ECHO is on.
    public function invites(): HasMany
    {
        return $this->hasMany(Invite::class);
    }
ECHO is on.
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('subscription_status', '!=', 'suspended');
    }
ECHO is on.
    public function isSubscribed(): bool
    {
        return $this->is_active
            && $this->subscription_status === 'active';
    }
ECHO is on.
    public function chatbotName(): string
    {
        $customName = trim((string) $this->chatbot_name);
        if ($customName !== '') {
            return $customName;
        }
ECHO is on.
        $name = trim((string) $this->name);
        if (preg_match('/^(?:The\s+)?([\p{L}][\p{L}\p{M}\x{27}-]*)/iu', $name, $matches) === 1) {
            $stem = preg_replace('/a$/i', '', $matches[1]) ?? $matches[1];
ECHO is on.
            return $stem.'ie';
        }
ECHO is on.
        return 'Assistant';
    }
ECHO is on.
    public function isOnTrial(): bool
    {
        return $this->is_active
            && $this->subscription_status === 'trial'
            && ($this->trial_ends_at === null || $this->trial_ends_at->isFuture());
    }
ECHO is on.
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
ECHO is on.
        return in_array($feature, $plans[$this->subscription_plan] ?? [], true);
    }
ECHO is on.
    public function shouldAutoConfirmPaidAppointments(): bool
    {
        $settings = $this->settings ?? [];
ECHO is on.
        if (array_key_exists('auto_confirm_paid_appointments', $settings)) {
            return (bool) $settings['auto_confirm_paid_appointments'];
        }
ECHO is on.
        if ($this->hasAttribute('auto_confirm_paid_appointments')) {
            $columnValue = $this->getAttribute('auto_confirm_paid_appointments');
ECHO is on.
            return $columnValue !== null ? (bool) $columnValue : false;
        }
ECHO is on.
        return false;
    }
}
