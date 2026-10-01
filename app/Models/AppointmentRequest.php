<?php

namespace App\Models;

use App\Traits\BelongsToHospital;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AppointmentRequest extends Model
{
    use BelongsToHospital, HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_NO_SHOW = 'no_show';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'session_id',
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
    ];

    public function mpesaPayment(): HasOne
    {
        return $this->hasOne(MpesaPayment::class)->latestOfMany();
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
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
