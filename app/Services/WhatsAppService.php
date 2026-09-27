<?php

namespace App\Services;

use App\Models\AppointmentRequest;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class WhatsAppService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * @return array{success: bool, message: string}
     */
    public function sendMessage(string $to, string $message): array
    {
        try {
            if (! $this->notifications->sendWhatsApp($this->formatPhone($to), $message)) {
                return ['success' => false, 'message' => 'WhatsApp message was not accepted for delivery.'];
            }

            return ['success' => true, 'message' => 'WhatsApp message accepted for delivery.'];
        } catch (Throwable $exception) {
            Log::error('Unable to send a WhatsApp message.', [
                'exception' => $exception,
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
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function markAsRead(string $messageId): void
    {
        try {
            $this->apiRequest()->post($this->messagesUrl(), [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => $messageId,
            ])->throw();
        } catch (Throwable $exception) {
            Log::error('Unable to mark a WhatsApp message as read.', [
                'exception' => $exception,
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

            $message = sprintf(
                'Hello %s, your %s appointment (ID %d) is confirmed. Your M-Pesa payment%s was received.',
                $appointment->name ?: 'Patient',
                pearlie_config('hospital.name'),
                $appointment->id,
                $appointment->mpesa_receipt ? ' '.$appointment->mpesa_receipt : '',
            );

            return $this->sendMessage($phone, $message)['success'];
        } catch (Throwable $exception) {
            Log::error('Unable to send the appointment WhatsApp confirmation.', [
                'appointment_id' => $appointment->id,
                'exception' => $exception,
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

        $usePearlFallback = $tenant->slug === 'pearl';
        $config['access_token'] = $tenant->whatsapp_access_token
            ?: ($usePearlFallback ? config('whatsapp.access_token') : null);
        $config['phone_number_id'] = $tenant->whatsapp_phone_number_id
            ?: ($usePearlFallback ? config('whatsapp.phone_number_id') : null);
        $config['api_version'] = $usePearlFallback
            ? config('whatsapp.api_version', 'v20.0')
            : $tenant->whatsapp_api_version;

        return $config;
    }
}
