<?php

namespace App\Observers;

use App\Jobs\DeliverWebhookDelivery;
use App\Models\AppointmentRequest;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Support\Str;

class AppointmentRequestObserver
{
    public function created(AppointmentRequest $appointment): void
    {
        $this->queueWebhookEvent($appointment, 'appointment.created');
    }

    public function updated(AppointmentRequest $appointment): void
    {
        if ($appointment->wasChanged([
            'status',
            'payment_status',
            'preferred_date',
            'slot_start_time',
            'slot_end_time',
            'doctor_id',
            'service_id',
        ])) {
            $this->queueWebhookEvent($appointment, 'appointment.updated');
        }
    }

    private function queueWebhookEvent(AppointmentRequest $appointment, string $event): void
    {
        $subscriptions = WebhookSubscription::withoutGlobalScopes()
            ->where('hospital_id', $appointment->hospital_id)
            ->where('is_active', true)
            ->get();

        foreach ($subscriptions as $subscription) {
            if (! in_array($event, $subscription->events ?? [], true)) {
                continue;
            }

            $delivery = WebhookDelivery::query()->create([
                'hospital_id' => $appointment->hospital_id,
                'webhook_subscription_id' => $subscription->id,
                'delivery_id' => (string) Str::uuid(),
                'event' => $event,
                'payload' => [
                    'id' => (int) $appointment->id,
                    'hospital_id' => (int) $appointment->hospital_id,
                    'booking_reference' => $appointment->booking_reference,
                    'service_id' => $appointment->service_id,
                    'doctor_id' => $appointment->doctor_id,
                    'preferred_date' => $appointment->preferred_date?->toDateString(),
                    'slot_start_time' => $appointment->slot_start_time,
                    'slot_end_time' => $appointment->slot_end_time,
                    'status' => $appointment->status,
                    'payment_status' => $appointment->payment_status,
                    'updated_at' => $appointment->updated_at?->toIso8601String(),
                ],
                'status' => WebhookDelivery::STATUS_PENDING,
            ]);

            DeliverWebhookDelivery::dispatch($delivery->id)->afterCommit();
        }
    }
}
