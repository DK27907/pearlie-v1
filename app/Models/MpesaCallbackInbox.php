<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MpesaCallbackInbox extends Model
{
    protected $table = 'mpesa_callback_inbox';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_RETRY = 'retry';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'checkout_request_id',
        'hospital_slug',
        'payload_hash',
        'payload',
        'status',
        'attempts',
        'available_at',
        'processing_started_at',
        'processed_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_RETRY])
            ->where('available_at', '<=', now());
    }
}
