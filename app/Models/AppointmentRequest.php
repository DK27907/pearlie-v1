<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppointmentRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'session_id',
        'name',
        'phone',
        'preferred_date',
        'reason',
        'raw_message',
        'status',
        'booking_fee',
        'payment_status',
        'mpesa_phone',
        'mpesa_checkout_request_id',
        'mpesa_merchant_request_id',
        'mpesa_result_code',
        'mpesa_result_description',
        'mpesa_receipt',
        'paid_at',
    ];

    protected $casts = [
        'preferred_date' => 'date',
        'booking_fee' => 'integer',
        'mpesa_result_code' => 'integer',
        'paid_at' => 'datetime',
    ];

    public function mpesaPayment()
    {
        return $this->hasOne(MpesaPayment::class);
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_CONFIRMED,
            self::STATUS_CANCELLED,
            self::STATUS_COMPLETED,
        ];
    }
}
