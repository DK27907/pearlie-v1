<?php

namespace App\Services\Chat;

class ChatResponse
{
    /**
     * @param  array<string, mixed>  $collectedFields
     */
    public function __construct(
        public readonly string $message,
        public readonly string $state,
        public readonly ?int $appointmentId = null,
        public readonly bool $paymentRequired = false,
        public readonly array $collectedFields = [],
        public readonly ?string $source = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $response = [
            'response' => $this->message,
            'state' => $this->state,
            'appointment_id' => $this->appointmentId,
            'payment_required' => $this->paymentRequired,
            'collected_fields' => $this->collectedFields,
        ];

        if ($this->source !== null) {
            $response['source'] = $this->source;
        }

        return $response;
    }
}
