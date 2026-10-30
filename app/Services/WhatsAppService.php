<?php

namespace App\Services;

use App\Exceptions\MissingProviderCredentialsException;
use App\Models\AppointmentRequest;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class WhatsAppService
{
    private readonly ProviderCredentialResolver $credentialResolver;

    public function __construct(
        private readonly NotificationService $notifications,
        ?ProviderCredentialResolver $credentialResolver = null,
    ) {
        $this->credentialResolver = $credentialResolver
            ?? new ProviderCredentialResolver(new HospitalSettings);
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function sendMessage(string $to, string $message, ?int $appointmentRequestId = null): array
    {
        try {
            if (! $this->notifications->sendWhatsApp(
                $this->formatPhone($to),
                $message,
                $appointmentRequestId,
            )) {
                return ['success' => false, 'message' => 'WhatsApp message was not accepted for delivery.'];
            }

            return ['success' => true, 'message' => 'WhatsApp message accepted for delivery.'];
        } catch (Throwable $exception) {
            Log::error('Unable to send a WhatsApp message.', [
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array<int, array{id: string, label: string, value: string}>  $quickReplies
     * @return array{success: bool, message: string}
     */
    public function sendInteractiveList(string $to, string $message, array $quickReplies): array
    {
        if ($quickReplies === []) {
            throw new InvalidArgumentException('At least one WhatsApp list option is required.');
        }

        $rows = array_map(static fn (array $reply): array => [
            'id' => $reply['value'],
            'title' => Str::limit($reply['label'], 24, ''),
        ], array_slice($quickReplies, 0, 10));

        try {
            $response = $this->apiRequest()->post($this->messagesUrl(), [
                'messaging_product' => 'whatsapp',
                'to' => $this->formatPhone($to),
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'list',
                    'body' => ['text' => Str::limit($message, 1024, '')],
                    'action' => [
                        'button' => 'Choose an option',
                        'sections' => [[
                            'title' => 'Available options',
                            'rows' => $rows,
                        ]],
                    ],
                ],
            ])->throw();

            return [
                'success' => true,
                'message' => (string) ($response->json('messages.0.id') ?? 'WhatsApp list accepted.'),
            ];
        } catch (Throwable $exception) {
            Log::error('Unable to send a WhatsApp interactive list.', [
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array<int, string>  $variables
     * @return array{success: bool, message: string}
     */
    public function sendTemplate(string $to, string $templateName, array $variables): array
    {
        try {
            $response = $this->apiRequest()->post($this->messagesUrl(), [
                'messaging_product' => 'whatsapp',
                'to' => $this->formatPhone($to),
                'type' => 'template',
                'template' => [
                    'name' => $templateName,
                    'language' => ['code' => 'en'],
                    'components' => [[
                        'type' => 'body',
                        'parameters' => array_map(
                            fn (string $value): array => ['type' => 'text', 'text' => $value],
                            $variables,
                        ),
                    ]],
                ],
            ])->throw();

            return [
                'success' => true,
                'message' => (string) ($response->json('messages.0.id') ?? 'WhatsApp template accepted.'),
            ];
        } catch (Throwable $exception) {
            Log::error('Unable to send a WhatsApp template.', [
                'template' => $templateName,
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }
    }

    public function markAsRead(string $messageId, bool $showTypingIndicator = false): void
    {
        try {
            $payload = [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => $messageId,
            ];
            if ($showTypingIndicator) {
                $payload['typing_indicator'] = ['type' => 'text'];
            }

            $this->apiRequest()->post($this->messagesUrl(), $payload)->throw();
        } catch (Throwable $exception) {
            Log::error('Unable to mark a WhatsApp message as read.', [
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }
    }

    public function formatPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '0')) {
            $digits = '254'.substr($digits, 1);
        } elseif (str_starts_with($digits, '7') && strlen($digits) === 9) {
            $digits = '254'.$digits;
        }

        if (! preg_match('/^2547\d{8}$/', $digits)) {
            throw new InvalidArgumentException('The phone number must be a valid Kenyan mobile number.');
        }

        return $digits;
    }

    public function isConfigured(): bool
    {
        try {
            $config = $this->whatsappConfig();
        } catch (MissingProviderCredentialsException) {
            return false;
        }

        return filled($config['phone_number_id'] ?? null)
            && filled($config['access_token'] ?? null);
    }

    public function sendAppointmentConfirmation(AppointmentRequest $appointment): bool
    {
        try {
            $phone = $appointment->mpesa_phone ?: $appointment->phone;
            if (! $phone) {
                Log::warning('Unable to send WhatsApp confirmation without a patient phone number.', [
                    'appointment_id' => $appointment->id,
                ]);

                return false;
            }

            $reference = filled($appointment->booking_reference)
                ? ' (reference '.$appointment->booking_reference.')'
                : '';
            $message = sprintf(
                'Hello %s, your %s appointment%s is confirmed. Your M-Pesa payment%s was received.',
                $appointment->name ?: 'Patient',
                pearlie_config('hospital.name'),
                $reference,
                $appointment->mpesa_receipt ? ' '.$appointment->mpesa_receipt : '',
            );

            return $this->sendMessage($phone, $message, $appointment->id)['success'];
        } catch (Throwable $exception) {
            Log::error('Unable to send the appointment WhatsApp confirmation.', [
                'appointment_id' => $appointment->id,
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }
    }

    private function apiRequest(): PendingRequest
    {
        $config = $this->whatsappConfig();
        $token = (string) $config['access_token'];
        if ($token === '') {
            throw new \RuntimeException('WhatsApp access token is not configured.');
        }

        return Http::withToken($token)
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(10);
    }

    private function messagesUrl(): string
    {
        $config = $this->whatsappConfig();
        $phoneNumberId = (string) $config['phone_number_id'];
        if ($phoneNumberId === '') {
            throw new \RuntimeException('WhatsApp phone number ID is not configured.');
        }

        return sprintf(
            '%s/%s/%s/messages',
            rtrim((string) $config['base_url'], '/'),
            trim((string) $config['api_version'], '/'),
            $phoneNumberId,
        );
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
