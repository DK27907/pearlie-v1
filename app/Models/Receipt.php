<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class Receipt extends Model
{
    protected $fillable = [
        'hospital_id',
        'appointment_request_id',
        'mpesa_payment_id',
        'receipt_number',
        'amount',
        'payment_method',
        'issued_at',
        'issued_by_user_id',
        'pdf_path',
        'etims_status',
        'etims_invoice_number',
        'etims_qr_data',
        'etims_submitted_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'issued_at' => 'datetime',
        'etims_submitted_at' => 'datetime',
    ];

    protected $appends = ['pdf_url'];

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(AppointmentRequest::class, 'appointment_request_id');
    }

    public function mpesaPayment(): BelongsTo
    {
        return $this->belongsTo(MpesaPayment::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function getPdfUrlAttribute(): string
    {
        return $this->signedDownloadUrl();
    }

    public function signedDownloadUrl(): string
    {
        return URL::temporarySignedRoute(
            'receipts.download',
            now()->addHours(24),
            ['receipt' => $this->getKey()],
        );
    }

    public function hasPdf(): bool
    {
        return filled($this->pdf_path) && Storage::exists($this->pdf_path);
    }
}
