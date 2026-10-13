<?php

namespace App\Services;

use App\Exceptions\MissingProviderCredentialsException;
use App\Models\Hospital;
use App\Models\NotificationLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class NotificationService
{
    private readonly ProviderCredentialResolver $credentialResolver;

    public function __construct(?ProviderCredentialResolver $credentialResolver = null)
    {
        $this->credentialResolver = $credentialResolver
            ?? new ProviderCredentialResolver(new HospitalSettings);
    }

    public function sendSms(string $to, string $message): bool
    {
        $this->ensureTenantContext();

        $smsTo = $to;
        $smsConfig = $this->smsConfig();
        $httpStatus = null;
        $providerResponse = null;
        $failureCode = 'delivery_failed';
        $lastProvider = null;
        $provider = (string) ($smsConfig['provider'] ?? (
            filled($smsConfig['username'] ?? null) && filled($smsConfig['api_key'] ?? null)
                ? 'africastalking'
                : 'twilio'
        ));
        if (! in_array($provider, ['africastalking', 'twilio'], true)) {
            $this->recordDelivery('sms', $provider, 'not_configured', null, null, 'unsupported_provider');

            return false;
        }
        $lastProvider = $provider;

        // Africa's Talking
        $atUser = $provider === 'africastalking' ? ($smsConfig['username'] ?? null) : null;
        $atKey = $provider === 'africastalking' ? ($smsConfig['api_key'] ?? null) : null;
        $atFrom = $provider === 'africastalking' ? ($smsConfig['sender_id'] ?? null) : null;
        if ($provider === 'africastalking' && (blank($atUser) || blank($atKey))) {
            $this->recordDelivery('sms', $provider, 'not_configured', null, null, 'credentials_missing');

            return false;
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

                for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                    try {
                        $resp = Http::withHeaders([
                            'apiKey' => $atKey,
                            'Content-Type' => 'application/x-www-form-urlencoded',
                        ])->connectTimeout(3)->timeout(10)->asForm()->post($url, $payload);
                        $httpStatus = $resp->status();
                        $providerResponse = $resp->body();

                        Log::info('AfricaTalking SMS attempt', ['status' => $resp->status(), 'attempt' => $attempt]);

                        if ($resp->successful()) {
                            $this->recordDelivery(
                                'sms',
                                'africastalking',
                                'sent',
                                $httpStatus,
                                $this->responseMessageId($providerResponse, 'SMSMessageData.Recipients.0.messageId'),
                            );

                            return true;
                        }
                        $failureCode = 'http_'.$httpStatus;

                        // Non-2xx response; consider transient and retry unless last attempt
                        if ($attempt < $maxAttempts) {
                            $sleepMs = $baseBackoffMs * (2 ** ($attempt - 1));
                            Log::warning('AfricaTalking non-success response; will retry after backoff', ['attempt' => $attempt, 'sleep_ms' => $sleepMs]);
                            usleep($sleepMs * 1000);

                            continue;
                        }

                    } catch (\Throwable $e) {
                        $failureCode = 'transport_error';
                        Log::error('AfricaTalking SMS attempt failed.', [
                            'provider' => 'africastalking',
                            'attempt' => $attempt,
                            'exception_class' => $e::class,
                        ]);
                        if ($attempt < $maxAttempts) {
                            $sleepMs = $baseBackoffMs * (2 ** ($attempt - 1));
                            usleep($sleepMs * 1000);

                            continue;
                        }
                    }
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
                        Log::error('Failed to report AfricaTalking failure.', ['exception_class' => $_e::class]);
                    }
                }
                Log::info('metric.notification_failure', ['provider' => 'africastalking']);

            } catch (\Throwable $e) {
                Log::error('AfricaTalking SMS delivery failed.', ['exception_class' => $e::class]);
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
            $this->recordDelivery('sms', $provider, 'not_configured', null, null, 'credentials_missing');

            return false;
        }

        if ($sid && (($apiKey && $apiSecret) || $token) && $from) {
            try {
                $lastProvider = 'twilio';
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
                        $httpStatus = $resp->status();
                        $providerResponse = $resp->body();

                        Log::info('Twilio SMS attempt', ['status' => $resp->status(), 'attempt' => $attempt]);

                        if ($resp->successful()) {
                            $this->recordDelivery(
                                'sms',
                                'twilio',
                                'sent',
                                $httpStatus,
                                $this->responseMessageId($providerResponse, 'sid'),
                            );

                            return true;
                        }
                        $failureCode = 'http_'.$httpStatus;

                        if ($attempt < $maxAttempts) {
                            $sleepMs = $baseBackoffMs * (2 ** ($attempt - 1));
                            Log::warning('Twilio non-success response; will retry after backoff', ['attempt' => $attempt, 'sleep_ms' => $sleepMs]);
                            usleep($sleepMs * 1000);

                            continue;
                        }

                    } catch (\Throwable $e) {
                        $failureCode = 'transport_error';
                        Log::error('Twilio SMS attempt failed.', [
                            'provider' => 'twilio',
                            'attempt' => $attempt,
                            'exception_class' => $e::class,
                        ]);
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
                        Log::error('Failed to report Twilio failure.', ['exception_class' => $_e::class]);
                    }
                }
                Log::info('metric.notification_failure', ['provider' => 'twilio']);

            } catch (\Throwable $e) {
                Log::error('Twilio SMS delivery failed.', ['exception_class' => $e::class]);
            }
        }

        Log::warning('No SMS provider configured; unable to send the message.');
        $this->recordDelivery(
            'sms',
            $lastProvider ?? $provider,
            'failed',
            $httpStatus,
            null,
            $failureCode,
            $providerResponse,
        );

        return false;
    }

    public function sendWhatsApp(string $to, string $message): bool
    {
        $this->ensureTenantContext();

        $whatsappConfig = $this->whatsappConfig();
        $waId = $whatsappConfig['phone_number_id'] ?? null;
        $waToken = $whatsappConfig['access_token'] ?? null;
        $smsTo = $to;
        $httpStatus = null;
        $providerResponse = null;
        $failureCode = 'delivery_failed';

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
                        $httpStatus = $waResp->status();
                        $providerResponse = $waResp->body();

                        Log::info('WhatsApp attempt', ['status' => $waResp->status(), 'attempt' => $attempt]);

                        if ($waResp->successful()) {
                            $this->recordDelivery(
                                'whatsapp',
                                'meta_whatsapp',
                                'sent',
                                $httpStatus,
                                $this->responseMessageId($providerResponse, 'messages.0.id'),
                            );

                            return true;
                        }
                        $failureCode = 'http_'.$httpStatus;

                        if ($attempt < $maxAttempts) {
                            $sleepMs = $baseBackoffMs * (2 ** ($attempt - 1));
                            Log::warning('WhatsApp non-success response; will retry after backoff', ['attempt' => $attempt, 'sleep_ms' => $sleepMs]);
                            usleep($sleepMs * 1000);

                            continue;
                        }

                    } catch (\Throwable $e) {
                        $failureCode = 'transport_error';
                        Log::error('WhatsApp attempt failed.', [
                            'provider' => 'meta_whatsapp',
                            'attempt' => $attempt,
                            'exception_class' => $e::class,
                        ]);
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
                        Log::error('Failed to report WhatsApp failure.', ['exception_class' => $_e::class]);
                    }
                }
                Log::info('metric.notification_failure', ['provider' => 'whatsapp']);

            } catch (\Throwable $e) {
                $failureCode = 'transport_error';
                Log::error('WhatsApp delivery failed.', ['exception_class' => $e::class]);
            }
            $this->recordDelivery(
                'whatsapp',
                'meta_whatsapp',
                'failed',
                $httpStatus,
                null,
                $failureCode,
                $providerResponse,
            );
        } else {
            $this->recordDelivery(
                'whatsapp',
                'meta_whatsapp',
                'not_configured',
                null,
                null,
                'credentials_missing',
            );
            Log::info('No WhatsApp credentials configured; skipping the message.');
        }

        return false;
    }

    private function recordDelivery(
        string $channel,
        string $provider,
        string $status,
        ?int $httpStatus = null,
        ?string $providerMessageId = null,
        ?string $failureCode = null,
        ?string $providerResponse = null,
    ): void {
        $this->ensureTenantContext();

        NotificationLog::query()->create([
            'hospital_id' => hospital()->id,
            'channel' => $channel,
            'provider' => $provider,
            'status' => $status,
            'http_status' => $httpStatus,
            'provider_message_id' => $providerMessageId,
            'failure_code' => $failureCode,
            'provider_response' => $status === 'failed' ? $providerResponse : null,
        ]);
        app(ExternalProviderLogger::class)->record(
            $provider,
            $channel,
            $status,
            $httpStatus,
            $failureCode,
        );
    }

    private function ensureTenantContext(): void
    {
        $tenantHospital = hospital();

        if (
            ! $tenantHospital?->exists
            || $tenantHospital->getKey() === null
            || ! Hospital::query()->whereKey($tenantHospital->getKey())->exists()
        ) {
            throw new RuntimeException('A hospital context is required to send tenant notifications.');
        }
    }

    private function messageId(mixed $messageId): ?string
    {
        return is_scalar($messageId) ? (string) $messageId : null;
    }

    private function responseMessageId(string $body, string $key): ?string
    {
        $payload = json_decode($body, true);

        return is_array($payload)
            ? $this->messageId(data_get($payload, $key))
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function smsConfig(): array
    {
        $hospital = hospital();
        $hospitalSettings = HospitalSettings::currentOrNull();
        $credentials = $hospitalSettings?->credential('sms') ?? [];
        $globalConfig = $this->globalSmsConfig();

        if (! $hospital) {
            return $globalConfig;
        }

        $allowGlobalFallback = (bool) config('integrations.allow_global_fallback', false);
        if ($credentials !== [] && $allowGlobalFallback) {
            return $credentials;
        }

        $provider = (string) ($credentials['provider'] ?? (
            $credentials !== []
                ? (filled($credentials['username'] ?? null) && filled($credentials['api_key'] ?? null)
                    ? 'africastalking'
                    : 'twilio')
                : $globalConfig['provider']
        ));
        if ($allowGlobalFallback) {
            return $credentials !== [] ? $credentials : $globalConfig;
        }

        $tenantCredentials = array_replace($credentials, ['provider' => $provider]);
        $missingCredentials = match ($provider) {
            'africastalking' => array_values(array_filter(
                ['username', 'api_key'],
                fn (string $key): bool => blank($tenantCredentials[$key] ?? null),
            )),
            'twilio' => $this->missingTwilioCredentials($tenantCredentials),
            default => [],
        };

        if ($missingCredentials !== []) {
            throw MissingProviderCredentialsException::forHospital(
                $hospital,
                'SMS',
                $missingCredentials,
            );
        }

        return $tenantCredentials;
    }

    /**
     * @return array<string, mixed>
     */
    private function globalSmsConfig(): array
    {
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
     * @param  array<string, mixed>  $credentials
     * @return array<int, string>
     */
    private function missingTwilioCredentials(array $credentials): array
    {
        $missing = [];
        if (blank($credentials['from_number'] ?? null)) {
            $missing[] = 'from_number';
        }
        if (blank($credentials['account_sid'] ?? null)) {
            $missing[] = 'account_sid';
        }
        if (blank($credentials['auth_token'] ?? null)
            && (blank($credentials['twilio_api_key'] ?? null)
                || blank($credentials['api_secret'] ?? null))
        ) {
            $missing[] = 'auth_token or twilio_api_key/api_secret';
        }

        return $missing;
    }

    /**
     * @return array<string, mixed>
     */
    private function whatsappConfig(): array
    {
        $config = (array) config('whatsapp');
        $tenant = hospital();
        if (! $tenant) {
            return $config;
        }

        return $this->credentialResolver->resolve(
            $tenant,
            'WhatsApp',
            $config,
            [
                'phone_number_id' => 'whatsapp_phone_number_id',
                'access_token' => 'whatsapp_access_token',
                'verify_token' => 'whatsapp_verify_token',
                'app_secret' => 'whatsapp_app_secret',
                'api_version' => 'whatsapp_api_version',
            ],
            ['phone_number_id', 'access_token'],
        );
    }
}
