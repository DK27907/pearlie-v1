<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentNotification;
use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\MpesaPayment;
use App\Models\PaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MpesaSimulateCallbackTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();

        app()->detectEnvironment(static fn (): string => 'local');
        config([
            'mpesa.environment' => 'sandbox',
            'mpesa.skip_callback_verification_in_local' => true,
        ]);

        $this->hospital = Hospital::factory()->create([
            'slug' => 'simulator-test',
        ]);
        app()->instance('currentHospital', $this->hospital);
    }

    public function test_simulate_callback_with_result_zero_completes_payment(): void
    {
        Http::preventStrayRequests();
        Queue::fake([SendPaymentNotification::class]);
        $this->hospital->update(['settings' => ['auto_confirm_paid_appointments' => true]]);
        [$appointment, $payment] = $this->createPaymentScenario('checkout-success');

        $this->artisan('mpesa:simulate-callback', [
            'checkout_request_id' => $payment->checkout_request_id,
            '--result' => '0',
            '--receipt' => 'TEST001',
        ])
            ->expectsOutputToContain('completed')
            ->assertExitCode(0);

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_COMPLETED,
            'mpesa_receipt' => 'TEST001',
            'result_code' => 0,
        ]);
        $this->assertNotNull($payment->fresh()->processed_at);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_CONFIRMED,
            'payment_status' => 'paid',
        ]);
        $this->assertSame(
            ['payment_initiated', 'payment_completed'],
            PaymentEvent::query()
                ->where('payment_id', $payment->id)
                ->orderBy('id')
                ->pluck('event')
                ->all(),
        );
    }

    public function test_simulate_callback_with_result_1032_fails_payment(): void
    {
        Http::preventStrayRequests();
        Queue::fake([SendPaymentNotification::class]);
        [$appointment, $payment] = $this->createPaymentScenario('checkout-cancelled');

        $this->artisan('mpesa:simulate-callback', [
            'checkout_request_id' => $payment->checkout_request_id,
            '--result' => '1032',
            '--receipt' => 'NOTUSED001',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_FAILED,
            'result_code' => 1032,
            'result_description' => 'Request cancelled by user.',
            'mpesa_receipt' => null,
        ]);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_CANCELLED,
            'payment_status' => 'unpaid',
        ]);
        $this->assertSame(
            ['payment_initiated', 'payment_failed'],
            PaymentEvent::query()
                ->where('payment_id', $payment->id)
                ->orderBy('id')
                ->pluck('event')
                ->all(),
        );
    }

    public function test_simulate_callback_rejects_a_payment_that_is_already_terminal(): void
    {
        Http::preventStrayRequests();
        $payment = MpesaPayment::factory()->create([
            'checkout_request_id' => 'checkout-already-failed',
            'status' => MpesaPayment::STATUS_FAILED,
            'result_code' => 1032,
        ]);

        $this->artisan('mpesa:simulate-callback', [
            'checkout_request_id' => $payment->checkout_request_id,
            '--result' => '0',
            '--receipt' => 'TEST002',
        ])
            ->expectsOutput('Payment checkout-already-failed is not pending; its current status is failed.')
            ->assertExitCode(1);

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_FAILED,
            'result_code' => 1032,
            'mpesa_receipt' => null,
        ]);
    }

    /**
     * @return array{AppointmentRequest, MpesaPayment}
     */
    private function createPaymentScenario(string $checkoutRequestId): array
    {
        $appointment = AppointmentRequest::factory()->create([
            'hospital_id' => $this->hospital->id,
            'phone' => '254712345678',
            'mpesa_phone' => '254712345678',
            'booking_fee' => 500,
            'payment_status' => 'unpaid',
        ]);
        $payment = MpesaPayment::factory()
            ->for($appointment, 'appointment')
            ->create([
                'checkout_request_id' => $checkoutRequestId,
                'status' => MpesaPayment::STATUS_INITIATED,
                'phone' => '254712345678',
                'amount' => 500,
            ]);
        PaymentEvent::query()->create([
            'hospital_id' => $this->hospital->id,
            'payment_id' => $payment->id,
            'event' => 'payment_initiated',
            'payload' => [],
        ]);

        return [$appointment, $payment];
    }
}
