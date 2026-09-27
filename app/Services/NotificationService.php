<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function sendSms(string $to, string $message): bool
    {
        $smsTo = $to;

        // Africa's Talking
        $atUser = config('services.africastalking.username');
        $atKey = config('services.africastalking.api_key');
        $atFrom = config('services.africastalking.sender_id');

        if ($atUser && $atKey) {
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
        $sid = config('services.twilio.account_sid');
        $token = config('services.twilio.auth_token');
        $apiKey = config('services.twilio.api_key');
        $apiSecret = config('services.twilio.api_secret');
        $from = config('services.twilio.from_number');

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
        $tenant = hospital();
        $usePearlFallback = ! $tenant || $tenant->slug === 'pearl';
        $waId = $tenant?->whatsapp_phone_number_id
            ?: ($usePearlFallback ? config('services.whatsapp.phone_number_id') : null);
        $waToken = $tenant?->whatsapp_access_token
            ?: ($usePearlFallback ? config('services.whatsapp.access_token') : null);
        $smsTo = $to;

        if ($waId && $waToken) {
            try {
                $apiVersion = $tenant && $tenant->slug !== 'pearl'
                    ? $tenant->whatsapp_api_version
                    : config('services.whatsapp.api_version', 'v20.0');
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
}
