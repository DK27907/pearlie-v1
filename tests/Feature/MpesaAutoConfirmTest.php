<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\MpesaPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MpesaAutoConfirmTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_callback_auto_confirms_paid_appointment_when_enabled(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario(true);
        $this->fakeCallback(0);
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
            'M-Pesa appointment auto-confirm decision.',
            \Mockery::on(fn (array $context): bool => $context['appointment_id'] === $appointment->id
                && $context['hospital_id'] === $hospital->id
                && $context['auto_confirmed'] === true
                && $context['result_code'] === 0),
        )->once();
    }

    public function test_successful_callback_leaves_appointment_pending_when_auto_confirm_is_disabled(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario(false);
        $this->fakeCallback(0);

        $this->postJson('/api/mpesa/callback/'.$hospital->slug, $this->callbackPayload($payment, 0))
            ->assertOk();

        $appointment = $appointment->fresh();
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_PENDING,
            'payment_status' => 'paid',
        ]);
        $this->assertNotNull($appointment->paid_at);
        $this->assertNull($appointment->status_updated_at);
    }

    public function test_failed_callback_does_not_auto_confirm_appointment(): void
    {
        [$hospital, $appointment, $payment] = $this->createPaymentScenario(true);
        $this->fakeCallback(1037);

        $this->postJson('/api/mpesa/callback/'.$hospital->slug, $this->callbackPayload($payment, 1037))
            ->assertOk();

        $appointment = $appointment->fresh();
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_PENDING,
            'payment_status' => 'unpaid',
        ]);
        $this->assertNull($appointment->paid_at);
        $this->assertNull($appointment->status_updated_at);
        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_FAILED,
            'result_code' => 1037,
        ]);
    }

    public function test_callback_uses_the_setting_for_the_appointment_hospital(): void
    {
        $hospitalWithAutoConfirm = Hospital::factory()->create([
            'settings' => ['auto_confirm_paid_appointments' => true],
        ]);
        [$hospital, $appointment, $payment] = $this->createPaymentScenario(false);
        $this->fakeCallback(0);

        $this->postJson('/api/mpesa/callback/'.$hospital->slug, $this->callbackPayload($payment, 0))
            ->assertOk();

        $this->assertTrue($hospitalWithAutoConfirm->shouldAutoConfirmPaidAppointments());
        $this->assertFalse($hospital->fresh()->shouldAutoConfirmPaidAppointments());
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'hospital_id' => $hospital->id,
            'status' => AppointmentRequest::STATUS_PENDING,
            'payment_status' => 'paid',
        ]);
        $this->assertNull($appointment->fresh()->status_updated_at);
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
