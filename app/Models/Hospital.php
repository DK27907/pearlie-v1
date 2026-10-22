<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Hospital extends Model
{
    use HasFactory;

    protected $attributes = [
        'supported_languages' => '["en","sw"]',
    ];

    protected $fillable = [
        'name',
        'slug',
        'chatbot_name',
        'logo_url',
        'site_header_text',
        'site_footer_text',
        'site_logo_path',
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
        'receipt_letterhead_path',
        'receipt_footer_path',
        'receipt_footer_text',
        'receipt_signature_path',
        'receipt_prefix',
        'receipt_title',
        'receipt_show_kra_pin',
        'receipt_kra_pin',
        'etims_enabled',
        'etims_kra_pin',
        'etims_environment',
        'etims_device_id',
        'reminder_enabled',
        'reminder_message_template',
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
            'auto_confirm_paid_appointments' => 'boolean',
            'business_hours' => 'array',
            'notification_preferences' => 'array',
            'patient_cancellation_enabled' => 'boolean',
            'patient_rescheduling_enabled' => 'boolean',
            'cancellation_cutoff_hours' => 'integer',
            'rescheduling_cutoff_hours' => 'integer',
            'late_cancellation_fee' => 'decimal:2',
            'deposits_refundable' => 'boolean',
            'supported_languages' => 'array',
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
            'is_active' => 'boolean',
            'receipt_show_kra_pin' => 'boolean',
            'etims_enabled' => 'boolean',
            'reminder_enabled' => 'boolean',
        ];
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::get(function (?string $value): ?string {
            if (filled($this->site_logo_path)) {
                return Storage::disk('public')->url($this->site_logo_path);
            }

            if (! filled($value)) {
                return null;
            }

            if (preg_match('#^https?://#i', $value) === 1) {
                return $value;
            }

            return Storage::disk('public')->url($value);
        });
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

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class);
    }

    public function shouldAutoConfirmPaidAppointments(): bool
    {
        $legacyValue = data_get($this->settings, 'auto_confirm_paid_appointments');
        if ($legacyValue !== null) {
            $parsedValue = filter_var($legacyValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsedValue !== null) {
                return $parsedValue;
            }
        }

        return (bool) $this->auto_confirm_paid_appointments;
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function chatbotName(): ?string
    {
        if (filled($this->chatbot_name)) {
            return $this->chatbot_name;
        }

        $hospitalName = Str::of($this->name)->trim()->before(' ')->toString();
        if ($hospitalName === '') {
            return null;
        }

        $hospitalName = preg_replace('/a$/i', '', $hospitalName) ?? $hospitalName;

        return Str::ucfirst($hospitalName).'ie';
    }

    public function integrationCredentials(): HasMany
    {
        return $this->hasMany(HospitalIntegrationCredential::class);
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
