<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SmsService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function send(string $phone, string $message, string $provider = 'log'): SmsResult
    {
        if ($provider === 'log' && ! app()->isProduction()) {
            Log::info('SMS reminder or announcement logged for local delivery.', [
                'phone' => $phone,
                'message' => $message,
            ]);

            return new SmsResult(true, 'sent', message: 'Logged locally.');
        }

        $sent = $this->notifications->sendSms($phone, $message);

        return new SmsResult(
            $sent,
            $sent ? 'sent' : 'failed',
            message: $sent ? 'SMS accepted by the configured provider.' : 'SMS provider is not configured or rejected delivery.',
        );
    }
}
