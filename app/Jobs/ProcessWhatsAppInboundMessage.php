<?php

namespace App\Jobs;

use App\Models\Hospital;
use App\Services\BookingChatService;
use App\Services\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ProcessWhatsAppInboundMessage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 50;

    public int $uniqueFor = 86400;

    public array $backoff = [1, 5, 10];

    public function __construct(
        public readonly int $hospitalId,
        public readonly string $from,
        public readonly string $message,
        public readonly ?string $providerMessageId,
        public readonly ?string $receivedAt,
    ) {}

    public function uniqueId(): string
    {
        return $this->providerMessageId
            ? $this->hospitalId.':'.$this->providerMessageId
            : hash('sha256', implode(':', [
                $this->hospitalId,
                $this->from,
                $this->message,
                $this->receivedAt ?? '',
            ]));
    }

    public function handle(BookingChatService $booking, WhatsAppService $whatsApp): void
    {
        $hospital = Hospital::withoutGlobalScopes()->find($this->hospitalId);
        if (! $hospital) {
            throw new RuntimeException('The WhatsApp message hospital no longer exists.');
        }

        $hadCurrentHospital = app()->bound('currentHospital');
        $previousHospital = $hadCurrentHospital ? app('currentHospital') : null;
        app()->instance('currentHospital', $hospital);

        try {
            if ($this->providerMessageId !== null) {
                try {
                    $whatsApp->markAsRead($this->providerMessageId, showTypingIndicator: true);
                } catch (Throwable $exception) {
                    Log::warning('Unable to show a WhatsApp typing indicator.', [
                        'hospital_id' => $this->hospitalId,
                        'provider_message_id' => $this->providerMessageId,
                        'exception_class' => $exception::class,
                    ]);
                }
            }

            $response = $booking->handle($this->from, $this->message, 'whatsapp');
            try {
                $delivery = $response->quickReplies !== []
                    ? $whatsApp->sendInteractiveList($this->from, $response->message, $response->quickReplies)
                    : $whatsApp->sendMessage($this->from, $response->message);

                if (! $delivery['success']) {
                    Log::warning('WhatsApp chat response was not accepted for delivery.', [
                        'hospital_id' => $this->hospitalId,
                        'provider_message_id' => $this->providerMessageId,
                    ]);
                }
            } catch (Throwable $exception) {
                Log::error('Unable to send a WhatsApp chat response.', [
                    'hospital_id' => $this->hospitalId,
                    'provider_message_id' => $this->providerMessageId,
                    'exception_class' => $exception::class,
                ]);
            }
        } finally {
            if ($hadCurrentHospital) {
                app()->instance('currentHospital', $previousHospital);
            } else {
                app()->forgetInstance('currentHospital');
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('WhatsApp inbound message exhausted its processing retries.', [
            'hospital_id' => $this->hospitalId,
            'provider_message_id' => $this->providerMessageId,
            'exception_class' => $exception::class,
        ]);
    }
}
