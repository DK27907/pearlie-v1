<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class NotificationService
{
    public function sendSms(string $to, string $message): bool
    {
        $smsTo = $to;
        $smsConfig = $this->smsConfig();
        $provider = (string) ($smsConfig['provider'] ?? (
            filled($smsConfig['username'] ?? null) && filled($smsConfig['api_key'] ?? null)
                ? 'africastalking'
                : 'twilio'
        ));
        if (! in_array($provider, ['africastalking', 'twilio'], true)) {
            throw new RuntimeException("SMS provider {$provider} is not configured for this hospital.");
        }

        // Africa's Talking
        $atUser = $provider === 'africastalking' ? ($smsConfig['username'] ?? null) : null;
        $atKey = $provider === 'africastalking' ? ($smsConfig['api_key'] ?? null) : null;
        $atFrom = $provider === 'africastalking' ? ($smsConfig['sender_id'] ?? null) : null;
        if ($provider === 'africastalking' && (blank($atUser) || blank($atKey))) {
            throw new RuntimeException("SMS provider {$provider} is not configured for this hospital.");
        }

        if ($provider === 'africastalking') {
            try {
                $url = 'https://api.africastalking.com/version1/messaging';
                $payload = [
                    'username' => $atUser,
                    'to' => $smsTo,
                    'message' => $message,
                ];
                if ($atFrom) {
                    $payload['from'] = $atFrom;
                }

                // Retry configuration
                $maxAttempts = config('pearlie.notification_retry_attempts', 3);
                $baseBackoffMs = config('pearlie.notification_backoff_ms', 500); // milliseconds

                $sent = false;
                for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                    try {
                        $resp = Http::withHeaders([
                            'apiKey' => $atKey,
                            'Content-Type' => 'application/x-www-form-urlencoded',
                        ])->connectTimeout(3)->timeout(10)->asForm()->post($url, $payload);

                        Log::info('AfricaTalking SMS attempt', ['status' => $resp->status(), 'attempt' => $attempt]);

                        if ($resp->successful()) {
                            $sent = true;
                            break;
                        }

                        // Non-2xx response; consider transient and retry unless last attempt
                        if ($attempt < $maxAttempts) {
                            $sleepMs = $baseBackoffMs * (2 ** ($attempt - 1));
                            Log::warning('AfricaTalking non-success response; will retry after backoff', ['attempt' => $attempt, 'sleep_ms' => $sleepMs]);
                            usleep($sleepMs * 1000);

                            continue;
                        }

                    } catch (\Throwable $e) {
                        Log::error('AfricaTalking SMS attempt exception: '.$e->getMessage(), ['attempt' => $attempt]);
                        if ($attempt < $maxAttempts) {
                            $sleepMs = $baseBackoffMs * (2 ** ($attempt - 1));
                            usleep($sleepMs * 1000);

                            continue;
                        }
                    }
                }

                if ($sent) {
                    return true;
                }

                Log::warning('AfricaTalking SMS not successful after retries; falling back to Twilio');

                // Metrics / reporting
                if (config('pearlie.report_failures', true)) {
                    $ex = new \Exception('AfricaTalking SMS not successful after retries.');
                    try {
                        if (function_exists('Sentry\\captureException')) {
                            \Sentry\captureException($ex);
                        } elseif (app()->bound('sentry')) {
                            app('sentry')->captureException($ex);
                        } else {
                            report($ex);
                        }
                    } catch (\Throwable $_e) {
                        Log::error('Failed to report AfricaTalking failure: '.$_e->getMessage());
                    }
                }
                Log::info('metric.notification_failure', ['provider' => 'africastalking']);

            } catch (\Throwable $e) {
                Log::error('AfricaTalking SMS failed: '.$e->getMessage());
            }
        }

        // Twilio fallback
        $sid = $smsConfig['account_sid'] ?? null;
        $token = $smsConfig['auth_token'] ?? null;
        $apiKey = $smsConfig['twilio_api_key'] ?? null;
        $apiSecret = $smsConfig['api_secret'] ?? null;
        $from = $smsConfig['from_number'] ?? null;
        if ($provider === 'twilio' && (
            blank($sid)
            || blank($from)
            || (! filled($token) && (! filled($apiKey) || ! filled($apiSecret)))
        )) {
            throw new RuntimeException("SMS provider {$provider} is not configured for this hospital.");
        }

        if ($sid && (($apiKey && $apiSecret) || $token) && $from) {
            try {
                $url = sprintf('https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', $sid);

                $maxAttempts = config('pearlie.notification_retry_attempts', 3);
                $baseBackoffMs = config('pearlie.notification_backoff_ms', 500);

                for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                    try {
                        $request = $apiKey && $apiSecret
                            ? Http::withBasicAuth($apiKey, $apiSecret)
                            : Http::withBasicAuth($sid, $token);
                        $resp = $request
                            ->connectTimeout(3)
                            ->timeout(10)
                            ->asForm()
                            ->post($url, [
                                'From' => $from,
                                'To' => $smsTo,
                                'Body' => $message,
                            ]);

                        Log::info('Twilio SMS attempt', ['status' => $resp->status(), 'attempt' => $attempt]);

                        if ($resp->successful()) {
                            return true;
                        }

                        if ($attempt < $maxAttempts) {
                            $sleepMs = $baseBackoffMs * (2 ** ($attempt - 1));
                            Log::warning('Twilio non-success response; will retry after backoff', ['attempt' => $attempt, 'sleep_ms' => $sleepMs]);
                            usleep($sleepMs * 1000);

                            continue;
                        }

                    } catch (\Throwable $e) {
                        Log::error('Twilio SMS attempt exception: '.$e->getMessage(), ['attempt' => $attempt]);
                        if ($attempt < $maxAttempts) {
                            $sleepMs = $baseBackoffMs * (2 ** ($attempt - 1));
                            usleep($sleepMs * 1000);

                            continue;
                        }
                    }
                }

                Log::warning('Twilio SMS not successful after retries.');

                if (config('pearlie.report_failures', true)) {
                    $ex = new \Exception('Twilio SMS not successful after retries.');
                    try {
                        if (function_exists('Sentry\\captureException')) {
                            \Sentry\captureException($ex);
                        } elseif (app()->bound('sentry')) {
                            app('sentry')->captureException($ex);
                        } else {
                            report($ex);
                        }
                    } catch (\Throwable $_e) {
                        Log::error('Failed to report Twilio failure: '.$_e->getMessage());
                    }
                }
                Log::info('metric.notification_failure', ['provider' => 'twilio']);

            } catch (\Throwable $e) {
                Log::error('Twilio SMS failed: '.$e->getMessage());
            }
        }

        Log::warning('No SMS provider configured; unable to send the message.');

        return false;
    }

    public function sendWhatsApp(string $to, string $message): bool
    {
        $whatsappConfig = $this->whatsappConfig();
        $waId = $whatsappConfig['phone_number_id'] ?? null;
        $waToken = $whatsappConfig['access_token'] ?? null;
        $smsTo = $to;

        if ($waId && $waToken) {
            try {
                $apiVersion = $whatsappConfig['api_version'] ?? 'v20.0';
                $waUrl = sprintf('https://graph.facebook.com/%s/%s/messages', $apiVersion, $waId);
                $waPayload = [
                    'messaging_product' => 'whatsapp',
                    'to' => preg_replace('/[^0-9+]/', '', $smsTo),
                    'type' => 'text',
                    'text' => ['body' => $message],
                ];

                $maxAttempts = config('pearlie.notification_retry_attempts', 3);
                $baseBackoffMs = config('pearlie.notification_backoff_ms', 500);

                for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                    try {
                        $waResp = Http::withHeaders([
                            'Authorization' => 'Bearer '.$waToken,
                            'Content-Type' => 'application/json',
                        ])->connectTimeout(3)->timeout(10)->post($waUrl, $waPayload);

                        Log::info('WhatsApp attempt', ['status' => $waResp->status(), 'attempt' => $attempt]);

                        if ($waResp->successful()) {
                            return true;
                        }

                        if ($attempt < $maxAttempts) {
                            $sleepMs = $baseBackoffMs * (2 ** ($attempt - 1));
                            Log::warning('WhatsApp non-success response; will retry after backoff', ['attempt' => $attempt, 'sleep_ms' => $sleepMs]);
                            usleep($sleepMs * 1000);

                            continue;
                        }

                    } catch (\Throwable $e) {
                        Log::error('WhatsApp attempt exception: '.$e->getMessage(), ['attempt' => $attempt]);
                        if ($attempt < $maxAttempts) {
                            $sleepMs = $baseBackoffMs * (2 ** ($attempt - 1));
                            usleep($sleepMs * 1000);

                            continue;
                        }
                    }
                }

                Log::warning('WhatsApp not successful after retries.');

                if (config('pearlie.report_failures', true)) {
                    $ex = new \Exception('WhatsApp not successful after retries.');
                    try {
                        if (function_exists('Sentry\\captureException')) {
                            \Sentry\captureException($ex);
                        } elseif (app()->bound('sentry')) {
                            app('sentry')->captureException($ex);
                        } else {
                            report($ex);
                        }
                    } catch (\Throwable $_e) {
                        Log::error('Failed to report WhatsApp failure: '.$_e->getMessage());
                    }
                }
                Log::info('metric.notification_failure', ['provider' => 'whatsapp']);

            } catch (\Throwable $e) {
                Log::error('WhatsApp send failed: '.$e->getMessage());
            }
        }

        Log::info('No WhatsApp credentials configured; skipping the message.');

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function smsConfig(): array
    {
        $hospitalSettings = HospitalSettings::currentOrNull();
        if ($hospitalSettings) {
            $credentials = $hospitalSettings->credential('sms');
            if ($credentials) {
                return $credentials;
            }
        }

        $legacyProvider = config('services.sms_provider');
        if ($legacyProvider === null) {
            $legacyProvider = filled(config('services.africastalking.username'))
                && filled(config('services.africastalking.api_key'))
                ? 'africastalking'
                : 'twilio';
        }

        return [
            'provider' => $legacyProvider,
            'username' => config('services.africastalking.username'),
            'api_key' => config('services.africastalking.api_key'),
            'sender_id' => config('services.africastalking.sender_id'),
            'account_sid' => config('services.twilio.account_sid'),
            'auth_token' => config('services.twilio.auth_token'),
            'twilio_api_key' => config('services.twilio.api_key'),
            'api_secret' => config('services.twilio.api_secret'),
            'from_number' => config('services.twilio.from_number'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function whatsappConfig(): array
    {
        $config = (array) config('whatsapp');
        $hospitalSettings = HospitalSettings::currentOrNull();
        $credentials = $hospitalSettings?->credential('whatsapp') ?? [];
        $tenant = hospital();

        foreach (['phone_number_id', 'access_token', 'api_version'] as $key) {
            if (isset($credentials[$key])) {
                $config[$key] = $credentials[$key];

                continue;
            }

            $legacyAttribute = match ($key) {
                'phone_number_id' => 'whatsapp_phone_number_id',
                'access_token' => 'whatsapp_access_token',
                'api_version' => 'whatsapp_api_version',
                default => null,
            };
            if ($tenant && $legacyAttribute && filled($tenant->getAttribute($legacyAttribute))) {
                $config[$key] = $tenant->getAttribute($legacyAttribute);
            }
        }

        return $config;
    }
}
