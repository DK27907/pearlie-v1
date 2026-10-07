<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\MpesaPayment;
use Tests\TestCase;

class MpesaLiveIntegrationTest extends TestCase
{
    public function test_completed_live_payment_preserves_appointment_and_tenant_invariants(): void
    {
        if (filter_var(env('MPESA_LIVE_TEST', false), FILTER_VALIDATE_BOOL) !== true) {
            $this->markTestSkipped('Set MPESA_LIVE_TEST=true after completing a real payment.');
        }

        $checkoutRequestId = (string) env('MPESA_LIVE_CHECKOUT_REQUEST_ID', '');
        $this->assertNotSame('', $checkoutRequestId, 'MPESA_LIVE_CHECKOUT_REQUEST_ID must identify the completed live payment.');

        $payment = MpesaPayment::withoutGlobalScopes()
            ->where('checkout_request_id', $checkoutRequestId)
            ->firstOrFail();
        $appointment = AppointmentRequest::withoutGlobalScopes()
            ->findOrFail($payment->appointment_request_id);

        $this->assertSame('completed', $payment->status);
        $this->assertNotSame('', (string) $payment->mpesa_receipt);
        $this->assertSame((int) $appointment->hospital_id, (int) $payment->hospital_id);
        $this->assertSame('paid', $appointment->payment_status);
        $this->assertSame('confirmed', $appointment->status);
        $this->assertDatabaseHas('payment_events', [
            'hospital_id' => $payment->hospital_id,
            'payment_id' => $payment->id,
            'event' => 'payment_completed',
        ]);
    }
}
