<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function sendSms(string $to, string $message): void
    {
        $smsTo = $to;

        // Africa's Talking
        $atUser = env('AFRICASTALKING_USERNAME');
        $atKey = env('AFRICASTALKING_API_KEY');
        $atFrom = env('AFRICASTALKING_SENDER_ID', null);

        if ($atUser && $atKey) {
            try {
                $url = 'https://api.africastalking.com/version1/messaging';
                $payload = [
                    'username' => $atUser,
                    'to' => $smsTo,
                    'message' => $message,
                ];
                if ($atFrom) $payload['from'] = $atFrom;

                // Retry configuration
                $maxAttempts = config('pearlie.notification_retry_attempts', 3);
                $baseBackoffMs = config('pearlie.notification_backoff_ms', 500); // milliseconds

                $sent = false;
                for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                    try {
                        $resp = Http::withHeaders([
                            'apiKey' => $atKey,
                            'Content-Type' => 'application/x-www-form-urlencoded',
                        ])->asForm()->post($url, $payload);

                        Log::info('AfricaTalking SMS attempt', ['status' => $resp->status(), 'body' => $resp->body(), 'attempt' => $attempt]);

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
                        Log::error('AfricaTalking SMS attempt exception: ' . $e->getMessage(), ['attempt' => $attempt]);
                        if ($attempt < $maxAttempts) {
                            $sleepMs = $baseBackoffMs * (2 ** ($attempt - 1));
                            usleep($sleepMs * 1000);
                            continue;
                        }
                    }
                }

                if ($sent) {
                    return;
                }

                Log::warning('AfricaTalking SMS not successful after retries; falling back to Twilio');

                // Metrics / reporting
                if (config('pearlie.report_failures', true)) {
                    $ex = new \Exception('AfricaTalking SMS not successful after retries for ' . $smsTo);
                    try {
                        if (function_exists('Sentry\\captureException')) {
                            \Sentry\captureException($ex);
                        } elseif (app()->bound('sentry')) {
                            app('sentry')->captureException($ex);
                        } else {
                            report($ex);
                        }
                    } catch (\Throwable $_e) {
                        Log::error('Failed to report AfricaTalking failure: ' . $_e->getMessage());
                    }
                }
                Log::info('metric.notification_failure', ['provider' => 'africastalking', 'to' => $smsTo]);

            } catch (\Throwable $e) {
                Log::error('AfricaTalking SMS failed: ' . $e->getMessage());
            }
        }

        // Twilio fallback
        if (env('TWILIO_SID') && env('TWILIO_TOKEN') && env('TWILIO_FROM')) {
            try {
                $sid = env('TWILIO_SID');
                $token = env('TWILIO_TOKEN');
                $from = env('TWILIO_FROM');
                $url = sprintf('https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', $sid);

                $maxAttempts = config('pearlie.notification_retry_attempts', 3);
                $baseBackoffMs = config('pearlie.notification_backoff_ms', 500);

                for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                    try {
                        $resp = Http::withBasicAuth($sid, $token)
                            ->asForm()
                            ->post($url, [
                                'From' => $from,
                                'To' => $smsTo,
                                'Body' => $message,
                            ]);

                        Log::info('Twilio SMS attempt', ['status' => $resp->status(), 'body' => $resp->body(), 'attempt' => $attempt]);

                        if ($resp->successful()) {
                            return;
                        }

                        if ($attempt < $maxAttempts) {
                            $sleepMs = $baseBackoffMs * (2 ** ($attempt - 1));
                            Log::warning('Twilio non-success response; will retry after backoff', ['attempt' => $attempt, 'sleep_ms' => $sleepMs]);
                            usleep($sleepMs * 1000);
                            continue;
                        }

                    } catch (\Throwable $e) {
                        Log::error('Twilio SMS attempt exception: ' . $e->getMessage(), ['attempt' => $attempt]);
                        if ($attempt < $maxAttempts) {
                            $sleepMs = $baseBackoffMs * (2 ** ($attempt - 1));
                            usleep($sleepMs * 1000);
                            continue;
                        }
                    }
                }

                Log::warning('Twilio SMS not successful after retries.');

                if (config('pearlie.report_failures', true)) {
                    $ex = new \Exception('Twilio SMS not successful after retries for ' . $smsTo);
                    try {
                        if (function_exists('Sentry\\captureException')) {
                            \Sentry\captureException($ex);
                        } elseif (app()->bound('sentry')) {
                            app('sentry')->captureException($ex);
                        } else {
                            report($ex);
                        }
                    } catch (\Throwable $_e) {
                        Log::error('Failed to report Twilio failure: ' . $_e->getMessage());
                    }
                }
                Log::info('metric.notification_failure', ['provider' => 'twilio', 'to' => $smsTo]);

            } catch (\Throwable $e) {
                Log::error('Twilio SMS failed: ' . $e->getMessage());
            }
        }

        Log::warning('No SMS provider configured; unable to send SMS to ' . $smsTo);
    }

    public function sendWhatsApp(string $to, string $message): void
    {
        $waId = env('WHATSAPP_PHONE_NUMBER_ID');
        $waToken = env('WHATSAPP_ACCESS_TOKEN');
        $smsTo = $to;

        if ($waId && $waToken) {
            try {
                $waUrl = sprintf('https://graph.facebook.com/v16.0/%s/messages', $waId);
                $waPayload = [
                    'messaging_product' => 'whatsapp',
                    'to' => preg_replace('/[^0-9+]/', '', $smsTo),
                    'type' => 'text',
                    'text' => [ 'body' => $message ],
                ];

                $maxAttempts = config('pearlie.notification_retry_attempts', 3);
                $baseBackoffMs = config('pearlie.notification_backoff_ms', 500);

                for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                    try {
                        $waResp = Http::withHeaders([
                            'Authorization' => 'Bearer ' . $waToken,
                            'Content-Type' => 'application/json',
                        ])->post($waUrl, $waPayload);

                        Log::info('WhatsApp attempt', ['status' => $waResp->status(), 'body' => $waResp->body(), 'attempt' => $attempt]);

                        if ($waResp->successful()) {
                            return;
                        }

                        if ($attempt < $maxAttempts) {
                            $sleepMs = $baseBackoffMs * (2 ** ($attempt - 1));
                            Log::warning('WhatsApp non-success response; will retry after backoff', ['attempt' => $attempt, 'sleep_ms' => $sleepMs]);
                            usleep($sleepMs * 1000);
                            continue;
                        }

                    } catch (\Throwable $e) {
                        Log::error('WhatsApp attempt exception: ' . $e->getMessage(), ['attempt' => $attempt]);
                        if ($attempt < $maxAttempts) {
                            $sleepMs = $baseBackoffMs * (2 ** ($attempt - 1));
                            usleep($sleepMs * 1000);
                            continue;
                        }
                    }
                }

                Log::warning('WhatsApp not successful after retries.');

                if (config('pearlie.report_failures', true)) {
                    $ex = new \Exception('WhatsApp not successful after retries for ' . $smsTo);
                    try {
                        if (function_exists('Sentry\\captureException')) {
                            \Sentry\captureException($ex);
                        } elseif (app()->bound('sentry')) {
                            app('sentry')->captureException($ex);
                        } else {
                            report($ex);
                        }
                    } catch (\Throwable $_e) {
                        Log::error('Failed to report WhatsApp failure: ' . $_e->getMessage());
                    }
                }
                Log::info('metric.notification_failure', ['provider' => 'whatsapp', 'to' => $smsTo]);

            } catch (\Throwable $e) {
                Log::error('WhatsApp send failed: ' . $e->getMessage());
            }
        }

        Log::info('No WhatsApp credentials configured; skipping WhatsApp to ' . $smsTo);
    }
}
