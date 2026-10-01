<?php

namespace App\Models;

use App\Traits\BelongsToHospital;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MpesaPayment extends Model
{
    use BelongsToHospital, HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_INITIATED = 'initiated';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_TIMEOUT = 'timeout';

    protected $table = 'mpesa_payments';

    protected $fillable = [
        'appointment_request_id',
        'checkout_request_id',
        'merchant_request_id',
        'phone',
        'amount',
        'account_reference',
        'transaction_desc',
        'status',
        'result_code',
        'result_description',
        'mpesa_receipt',
        'callback_payload',
        'processed_at',
        'notified_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'result_code' => 'integer',
        'callback_payload' => 'array',
        'processed_at' => 'datetime',
        'notified_at' => 'datetime',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(AppointmentRequest::class, 'appointment_request_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class, 'payment_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING,
            self::STATUS_INITIATED,
        ]);
    }

    public function scopeStale(Builder $query, int $seconds): Builder
    {
        return $query->pending()->where('created_at', '<=', now()->subSeconds($seconds));
    }

    /**
     * @param  array<string, mixed>  $receiptData
     */
    public function markAsCompleted(array $receiptData): self
    {
        $this->fill([
            'status' => self::STATUS_COMPLETED,
            'result_code' => $receiptData['result_code'] ?? $receiptData['ResultCode'] ?? 0,
            'result_description' => $receiptData['result_description']
                ?? $receiptData['ResultDesc']
                ?? 'Payment completed successfully.',
            'mpesa_receipt' => $receiptData['mpesa_receipt']
                ?? $receiptData['MpesaReceiptNumber']
                ?? null,
            'callback_payload' => $receiptData['callback_payload'] ?? null,
            'processed_at' => $receiptData['processed_at'] ?? now(),
        ])->save();

        return $this;
    }

    public function markAsFailed(string $reason, ?int $code = 1): self
    {
        $this->fill([
            'status' => self::STATUS_FAILED,
            'result_code' => $code,
            'result_description' => $reason,
            'processed_at' => now(),
        ])->save();

        return $this;
    }

    public function isPending(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_INITIATED,
        ], true);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
