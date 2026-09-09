<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MpesaPayment extends Model
{
    protected $fillable = [
        'appointment_request_id',
        'checkout_request_id',
        'merchant_request_id',
        'phone',
        'amount',
        'status',
        'result_code',
        'result_description',
        'mpesa_receipt',
        'callback_payload',
        'processed_at',
        'notified_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'result_code' => 'integer',
        'callback_payload' => 'array',
        'processed_at' => 'datetime',
        'notified_at' => 'datetime',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(AppointmentRequest::class, 'appointment_request_id');
    }
}
