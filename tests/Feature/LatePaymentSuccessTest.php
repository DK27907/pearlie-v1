<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentNotification;
use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\MpesaPayment;
use App\Models\PaymentEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LatePaymentSuccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_after_timeout_is_flagged_for_refund_without_confirming_appointment(): void
    {
        $hospital = Hospital::factory()->create([
            'slug' => 'late-payment-test',
            'subscription_plan' => 'enterprise',
            'mpesa_consumer_key' => 'test-consumer',
            'mpesa_consumer_secret' => 'test-secret',
            'mpesa_passkey' => 'test-passkey',
            'mpesa_shortcode' => '174379',
        ]);
        app()->instance('currentHospital', $hospital);
        $doctor = User::factory()->for($hospital, 'hospital')->create([
            'is_doctor' => true,
            'role' => 'doctor',
        ]);
        $appointment = AppointmentRequest::factory()
            ->for($hospital)
            ->for($doctor, 'doctor')
            ->create([
                'status' => AppointmentRequest::STATUS_CANCELLED,
                'payment_status' => 'unpaid',
                'mpesa_phone' => '254748249882',
                'payment_amount' => 500,
                'booking_fee' => 500,
            ]);
        $payment = MpesaPayment::factory()
            ->for($appointment, 'appointment')
            ->create([
                'checkout_request_id' => 'ws_CO_LATE_TEST',
                'status' => MpesaPayment::STATUS_TIMEOUT,
                'phone' => '254748249882',
                'amount' => 500,
            ]);
        app()->detectEnvironment(static fn (): string => 'local');
        config([
            'mpesa.environment' => 'sandbox',
            'mpesa.skip_callback_verification_in_local' => true,
        ]);
        Http::preventStrayRequests();
        Queue::fake([SendPaymentNotification::class]);

        $response = $this->postJson(route('tenant.mpesa.callback', $hospital->slug), [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => $payment->merchant_request_id,
                    'CheckoutRequestID' => $payment->checkout_request_id,
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 500],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'LATE_RECEIPT'],
                            ['Name' => 'PhoneNumber', 'Value' => 254748249882],
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertOk()->assertExactJson([
            'ResultCode' => 0,
            'ResultDesc' => 'Accepted',
        ]);
        $payment->refresh();
        $appointment->refresh();

        $this->assertSame('late_success_refund_pending', $payment->status);
        $this->assertSame('LATE_RECEIPT', $payment->mpesa_receipt);
        $this->assertSame(AppointmentRequest::STATUS_CANCELLED, $appointment->status);
        $this->assertSame('refund_pending', $appointment->payment_status);
        $this->assertSame(
            1,
            PaymentEvent::query()
                ->where('payment_id', $payment->id)
                ->where('event', 'late_success')
                ->count(),
        );
        Queue::assertNothingPushed();
    }
}
