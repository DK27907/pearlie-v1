<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentNotification;
use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\MpesaPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MpesaAutoConfirmTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_callback_auto_confirms_paid_appointment_when_enabled(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario(true);
        $this->fakeCallback(0);
        Queue::fake([SendPaymentNotification::class]);
        Log::spy();

        $this->postJson('/api/mpesa/callback/'.$hospital->slug, $this->callbackPayload($payment, 0))
            ->assertOk();

        $appointment = $appointment->fresh();
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_CONFIRMED,
            'payment_status' => 'paid',
        ]);
        $this->assertNotNull($appointment->paid_at);
        $this->assertNotNull($appointment->status_updated_at);
        Log::shouldHaveReceived('info')->with(
            'M-Pesa appointment lifecycle transition applied.',
            \Mockery::on(fn (array $context): bool => $context['appointment_id'] === $appointment->id
                && $context['hospital_id'] === $hospital->id
                && $context['appointment_status'] === AppointmentRequest::STATUS_CONFIRMED
                && $context['payment_status'] === 'paid'),
        )->once();
        Queue::assertPushed(SendPaymentNotification::class, fn (SendPaymentNotification $job): bool => $job->outcome === 'payment_completed');
    }

    public function test_successful_callback_leaves_paid_appointment_pending_when_auto_confirm_is_disabled(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario(false);
        $this->fakeCallback(0);
        Queue::fake([SendPaymentNotification::class]);

        $this->postJson('/api/mpesa/callback/'.$hospital->slug, $this->callbackPayload($payment, 0))
            ->assertOk();

        $appointment = $appointment->fresh();
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_PENDING,
            'payment_status' => 'paid',
        ]);
        $this->assertNotNull($appointment->paid_at);
        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $payment->id,
            'event' => 'payment_completed_awaiting_confirmation',
        ]);
        Queue::assertPushed(SendPaymentNotification::class, fn (SendPaymentNotification $job): bool => (
            $job->outcome === 'payment_completed_awaiting_confirmation'
            && $job->recipientRole === 'patient'
        ));
        Queue::assertPushed(SendPaymentNotification::class, fn (SendPaymentNotification $job): bool => (
            $job->outcome === 'payment_completed_awaiting_confirmation'
            && $job->recipientRole === 'admin'
        ));
    }

    public function test_failed_callback_cancels_the_unpaid_appointment(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario(true);
        $this->fakeCallback(1037);
        Queue::fake([SendPaymentNotification::class]);

        $this->postJson('/api/mpesa/callback/'.$hospital->slug, $this->callbackPayload($payment, 1037))
            ->assertOk();

        $appointment = $appointment->fresh();
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_CANCELLED,
            'payment_status' => 'unpaid',
        ]);
        $this->assertNull($appointment->paid_at);
        $this->assertNotNull($appointment->status_updated_at);
        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_FAILED,
            'result_code' => 1037,
        ]);
        Queue::assertPushed(SendPaymentNotification::class, fn (SendPaymentNotification $job): bool => $job->outcome === 'payment_failed');
    }

    public function test_callback_only_transitions_the_appointment_for_its_hospital(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario(true);
        $otherHospital = Hospital::factory()->create(['subscription_plan' => 'enterprise']);
        app()->instance('currentHospital', $otherHospital);
        $otherDoctor = User::factory()->for($otherHospital, 'hospital')->create([
            'is_doctor' => true,
            'role' => 'doctor',
        ]);
        $otherAppointment = AppointmentRequest::factory()
            ->for($otherHospital)
            ->for($otherDoctor, 'doctor')
            ->create();
        $this->fakeCallback(0);
        Queue::fake([SendPaymentNotification::class]);

        $this->postJson('/api/mpesa/callback/'.$hospital->slug, $this->callbackPayload($payment, 0))
            ->assertOk();

        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'hospital_id' => $hospital->id,
            'status' => AppointmentRequest::STATUS_CONFIRMED,
            'payment_status' => 'paid',
        ]);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $otherAppointment->id,
            'hospital_id' => $otherHospital->id,
            'status' => AppointmentRequest::STATUS_PENDING,
            'payment_status' => 'pending',
        ]);
        Queue::assertPushed(SendPaymentNotification::class, 1);
    }

    /**
     * @return array{Hospital, AppointmentRequest, MpesaPayment}
     */
    private function createPaymentScenario(bool $autoConfirm): array
    {
        $hospital = Hospital::factory()->create([
            'settings' => $autoConfirm
                ? ['auto_confirm_paid_appointments' => true]
                : [],
            'subscription_plan' => 'enterprise',
            'mpesa_consumer_key' => 'test-consumer',
            'mpesa_consumer_secret' => 'test-secret',
            'mpesa_passkey' => 'test-passkey',
            'mpesa_shortcode' => '174379',
        ]);
        app()->instance('currentHospital', $hospital);
        $doctor = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_doctor' => true,
            'role' => 'doctor',
        ]);
        $appointment = AppointmentRequest::factory()
            ->for($hospital)
            ->for($doctor, 'doctor')
            ->create([
                'mpesa_phone' => '254712345678',
                'payment_amount' => 500,
            ]);
        $payment = MpesaPayment::factory()
            ->for($appointment, 'appointment')
            ->create(['amount' => 500]);

        return [$hospital, $appointment, $payment];
    }

    private function fakeCallback(int $resultCode): void
    {
        config([
            'mpesa.environment' => 'sandbox',
            'mpesa.consumer_key' => 'test-consumer',
            'mpesa.consumer_secret' => 'test-secret',
            'mpesa.passkey' => 'test-passkey',
            'mpesa.shortcode' => '174379',
            'mpesa.timeout' => 5,
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'test-access-token',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => fn ($request) => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => $request['CheckoutRequestID'],
                'ResultCode' => (string) $resultCode,
            ]),
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'message-test']]]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function callbackPayload(MpesaPayment $payment, int $resultCode): array
    {
        return [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => $payment->merchant_request_id,
                    'CheckoutRequestID' => $payment->checkout_request_id,
                    'ResultCode' => $resultCode,
                    'ResultDesc' => $resultCode === 0
                        ? 'The service request is processed successfully.'
                        : 'The request is being processed.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => $payment->amount],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'TEST123'],
                            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                        ],
                    ],
                ],
            ],
        ];
    }
}
