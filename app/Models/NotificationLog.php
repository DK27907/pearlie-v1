<?php

namespace App\Models;

use Database\Factories\NotificationLogFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    /** @use HasFactory<NotificationLogFactory> */
    use HasFactory;

    protected $hidden = [
        'provider_response',
    ];

    protected $appends = [
        'scrubbed_provider_response',
    ];

    protected $fillable = [
        'hospital_id',
        'appointment_request_id',
        'channel',
        'provider',
        'status',
        'http_status',
        'provider_message_id',
        'failure_code',
        'provider_response',
    ];

    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
        ];
    }

    protected function providerResponse(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => self::scrubProviderResponseValue($value),
            set: fn (?string $value): ?string => self::scrubProviderResponseValue($value),
        );
    }

    protected function scrubbedProviderResponse(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->scrubProviderResponse());
    }

    public function scrubProviderResponse(): ?string
    {
        return self::scrubProviderResponseValue($this->attributes['provider_response'] ?? null);
    }

    private static function scrubProviderResponseValue(?string $response): ?string
    {
        if ($response === null) {
            return null;
        }

        $decoded = json_decode($response, true);
        if (! is_array($decoded)) {
            return '[REDACTED_PROVIDER_RESPONSE]';
        }

        $redacted = self::scrubProviderResponseValueRecursively($decoded);

        return json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function scrubProviderResponseValueRecursively(array $value): array
    {
        $sensitiveKeys = ['access_token', 'api_key', 'api_secret', 'auth_token', 'authorization', 'token'];

        foreach ($value as $key => $item) {
            $normalizedKey = is_string($key) ? strtolower($key) : '';

            if (in_array($normalizedKey, $sensitiveKeys, true)) {
                $value[$key] = '[REDACTED]';
            } elseif (in_array($normalizedKey, ['body', 'content', 'message_body'], true)) {
                $value[$key] = '[REDACTED_MESSAGE]';
            } elseif (is_array($item)) {
                $value[$key] = self::scrubProviderResponseValueRecursively($item);
            } elseif (is_string($item)) {
                $value[$key] = self::scrubProviderResponseString($item);
            }
        }

        return $value;
    }

    private static function scrubProviderResponseString(string $value): string
    {
        $value = preg_replace('/\bBearer\s+\S+/i', 'Bearer [REDACTED]', $value) ?? $value;

        return preg_replace(
            '/(?<!\d)\+?254(?:[\s-]?\d){9}(?!\d)|(?<!\d)0[17]\d{8}(?!\d)/',
            '[REDACTED_PHONE]',
            $value,
        ) ?? $value;
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(AppointmentRequest::class, 'appointment_request_id');
    }
}
