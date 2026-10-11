<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\MpesaPayment;
use App\Models\PaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrunePaymentEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_events_are_pruned_without_removing_active_payment_or_booking_events(): void
    {
        $hospital = Hospital::factory()->create();
        app()->instance('currentHospital', $hospital);

        $completedAppointment = AppointmentRequest::factory()->create([
            'hospital_id' => $hospital->id,
            'status' => AppointmentRequest::STATUS_COMPLETED,
        ]);
        $confirmedAppointment = AppointmentRequest::factory()->create([
            'hospital_id' => $hospital->id,
            'status' => AppointmentRequest::STATUS_CONFIRMED,
        ]);
        $terminalPayment = MpesaPayment::factory()->create([
            'hospital_id' => $hospital->id,
            'appointment_request_id' => $completedAppointment->id,
            'status' => MpesaPayment::STATUS_COMPLETED,
        ]);
        $activePayment = MpesaPayment::factory()->create([
            'hospital_id' => $hospital->id,
            'appointment_request_id' => null,
            'status' => MpesaPayment::STATUS_PENDING,
        ]);
        $confirmedBookingPayment = MpesaPayment::factory()->create([
            'hospital_id' => $hospital->id,
            'appointment_request_id' => $confirmedAppointment->id,
            'status' => MpesaPayment::STATUS_COMPLETED,
        ]);

        $terminalEvent = $this->createEvent($hospital, $terminalPayment);
        $activePaymentEvent = $this->createEvent($hospital, $activePayment);
        $confirmedBookingEvent = $this->createEvent($hospital, $confirmedBookingPayment);
        foreach ([$terminalEvent, $activePaymentEvent, $confirmedBookingEvent] as $event) {
            $event->forceFill(['created_at' => now()->subDays(400)])->save();
        }

        $this->artisan('app:prune-payment-events', ['--days' => 365])
            ->expectsOutput('Pruned 1 old payment event(s).')
            ->assertSuccessful();

        $this->assertDatabaseMissing('payment_events', ['id' => $terminalEvent->id]);
        $this->assertDatabaseHas('payment_events', ['id' => $activePaymentEvent->id]);
        $this->assertDatabaseHas('payment_events', ['id' => $confirmedBookingEvent->id]);
    }

    private function createEvent(Hospital $hospital, MpesaPayment $payment): PaymentEvent
    {
        return PaymentEvent::query()->create([
            'hospital_id' => $hospital->id,
            'payment_id' => $payment->id,
            'event' => 'payment_completed',
            'payload' => ['record' => true],
        ]);
    }
}
