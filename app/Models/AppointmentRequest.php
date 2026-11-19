<?php

namespace App\Models;

use App\Jobs\SendAppointmentPortalLink;
use App\Traits\BelongsToHospital;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AppointmentRequest extends Model
{
    use BelongsToHospital, HasFactory;

    protected static function booted(): void
    {
        static::creating(static function (self $appointment): void {
            if (blank($appointment->booking_reference)) {
                $appointment->booking_reference = 'BK-'.strtoupper(bin2hex(random_bytes(16)));
            }
        });

        static::created(static function (self $appointment): void {
            if ($appointment->status === self::STATUS_CONFIRMED && blank($appointment->intake_token)) {
                $appointment->forceFill([
                    'intake_token' => $appointment->id.'-'.Str::random(48),
                ])->saveQuietly();
            }

            DB::afterCommit(static function () use ($appointment): void {
                SendAppointmentPortalLink::dispatch(
                    (int) $appointment->id,
                    (int) $appointment->hospital_id,
                )->onConnection('database')->onQueue('portal-links');
            });
        });

        static::updating(static function (self $appointment): void {
            if ($appointment->isDirty('status')
                && $appointment->status === self::STATUS_CONFIRMED
                && blank($appointment->intake_token)
            ) {
                $appointment->intake_token = $appointment->id.'-'.Str::random(48);
            }
        });

        static::updated(static function (self $appointment): void {
            if ($appointment->wasChanged('status') && $appointment->status === self::STATUS_CONFIRMED) {
                DB::afterCommit(static function () use ($appointment): void {
                    SendAppointmentPortalLink::dispatch(
                        (int) $appointment->id,
                        (int) $appointment->hospital_id,
                    )->onConnection('database')->onQueue('portal-links');
                });
            }
        });
    }

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_NO_SHOW = 'no_show';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'session_id',
        'patient_id',
        'name',
        'phone',
        'email',
        'preferred_date',
        'reason',
        'raw_message',
        'status',
        'booking_fee',
        'payment_amount',
        'payment_status',
        'mpesa_phone',
        'mpesa_checkout_request_id',
        'mpesa_merchant_request_id',
        'mpesa_result_code',
        'mpesa_result_description',
        'mpesa_receipt',
        'paid_at',
        'status_updated_at',
        'doctor_id',
        'slot_start_time',
        'slot_end_time',
        'confirmed_by_doctor_at',
        'marked_no_show_at',
        'no_show_reason',
        'service_id',
        'cancelled_at',
        'cancelled_by_id',
        'cancellation_reason',
        'forfeited_amount',
        'payment_method',
        'payment_due_at',
        'paid_by_user_id',
        'payment_collected_amount',
        'payment_notes',
        'booking_source',
    ];

    protected $hidden = [
        'feedback_identity_hash',
        'intake_token',
        'intake_data',
    ];

    protected $casts = [
        'preferred_date' => 'date',
        'booking_fee' => 'integer',
        'payment_amount' => 'float',
        'mpesa_result_code' => 'integer',
        'paid_at' => 'datetime',
        'status_updated_at' => 'datetime',
        'confirmed_by_doctor_at' => 'datetime',
        'marked_no_show_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'forfeited_amount' => 'decimal:2',
        'payment_collected_amount' => 'decimal:2',
        'feedback_requested_at' => 'datetime',
        'feedback_score' => 'integer',
        'intake_token' => 'encrypted',
        'intake_completed_at' => 'datetime',
    ];

    protected function intakeData(): Attribute
    {
        return Attribute::make(
            get: static function (?string $value): ?array {
                if ($value === null) {
                    return null;
                }

                $payload = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

                return decrypt($payload['payload']);
            },
            set: static fn (?array $value): ?string => $value === null
                ? null
                : json_encode(['payload' => encrypt($value)], JSON_THROW_ON_ERROR),
        );
    }

    public function mpesaPayment(): HasOne
    {
        return $this->hasOne(MpesaPayment::class)->latestOfMany();
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class);
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function isPendingPayment(): bool
    {
        return $this->payment_status === 'pending';
    }

    public function canBeMarkedAsNoShow(): bool
    {
        if ($this->status !== self::STATUS_CONFIRMED
            || ($this->payment_method === 'mpesa' && $this->payment_status === 'pending')
        ) {
            return false;
        }

        return $this->payment_status === 'paid'
            || $this->payment_status === 'not_required'
            || $this->payment_method === 'cash'
            || $this->hospital?->bookingPaymentMode() === Hospital::BOOKING_PAYMENT_MODE_CASH;
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(AppointmentHistory::class, 'appointment_request_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_CONFIRMED,
            self::STATUS_CANCELLED,
            self::STATUS_COMPLETED,
            self::STATUS_NO_SHOW,
            self::STATUS_EXPIRED,
        ];
    }

    public function markAsNoShow(?string $reason = null): self
    {
        $this->forceFill([
            'status' => self::STATUS_NO_SHOW,
            'marked_no_show_at' => now(),
            'no_show_reason' => $reason,
        ])->save();

        return $this;
    }

    public function isNoShow(): bool
    {
        return $this->status === self::STATUS_NO_SHOW;
    }

    public function scopeNoShow(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_NO_SHOW);
    }

    public static function getNoShowCount(string $phone): int
    {
        return static::noShow()->where('phone', $phone)->count();
    }
}
