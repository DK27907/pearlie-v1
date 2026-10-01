<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentNotification;
use App\Models\AppointmentRequest;
use App\Models\DoctorAvailability;
use App\Models\Hospital;
use App\Models\MpesaPayment;
use App\Models\User;
use App\Services\MpesaService;
use App\Services\PearlieService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MpesaBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->instance(
            'currentHospital',
            Hospital::query()->where('slug', 'pearl')->firstOrFail(),
        );
    }

    public function test_stk_push_is_initiated(): void
    {
        $this->configureDaraja();
        $this->fakeStkPush();

        $result = app(MpesaService::class)->stkPush(
            '0712345678',
            500,
            'APT-1',
            'Appointment Deposit',
        );

        $this->assertTrue($result['success']);
        $this->assertSame('ws_CO_test-1', $result['checkout_request_id']);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/mpesa/stkpush/v1/processrequest')
            && $request['PartyA'] === '254712345678'
            && $request['AccountReference'] === 'APT-1');
    }

    public function test_mpesa_payment_record_is_created(): void
    {
        $this->configureDaraja();
        $this->fakeStkPush();
        $appointment = $this->createAppointment();

        $result = app(MpesaService::class)->stkPush(
            '0712345678',
            500,
            'APT-'.$appointment->id,
            'Appointment Deposit',
            $appointment->id,
        );

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $result['payment_id'],
            'appointment_request_id' => $appointment->id,
            'checkout_request_id' => 'ws_CO_test-1',
            'phone' => '254712345678',
            'amount' => 500,
            'account_reference' => 'APT-'.$appointment->id,
            'transaction_desc' => 'Appointment Deposit',
            'status' => MpesaPayment::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'payment_status' => 'pending',
            'payment_amount' => 500,
            'mpesa_checkout_request_id' => 'ws_CO_test-1',
        ]);
    }

    public function test_callback_marks_payment_as_completed(): void
    {
        $payment = MpesaPayment::factory()->create([
            'checkout_request_id' => 'checkout-success',
        ]);
        $this->fakeSuccessfulCallback();

        $this->postJson('/api/mpesa/callback', $this->successfulCallback('checkout-success'))
            ->assertOk();

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_COMPLETED,
            'result_code' => 0,
            'mpesa_receipt' => 'QWE123',
        ]);
    }

    public function test_completed_payment_updates_appointment(): void
    {
        $appointment = $this->createAppointment();
        MpesaPayment::factory()->for($appointment, 'appointment')->create([
            'checkout_request_id' => 'checkout-appointment',
        ]);
        $this->fakeSuccessfulCallback();

        $this->postJson('/api/mpesa/callback', $this->successfulCallback('checkout-appointment'))
            ->assertOk();

        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'payment_status' => 'paid',
            'mpesa_receipt' => 'QWE123',
            'status' => AppointmentRequest::STATUS_CONFIRMED,
        ]);
        $this->assertNotNull($appointment->fresh()->paid_at);
    }

    public function test_failed_payment_marks_appointment_unpaid(): void
    {
        $appointment = $this->createAppointment();
        MpesaPayment::factory()->for($appointment, 'appointment')->create([
            'checkout_request_id' => 'checkout-failed',
        ]);
        $this->configureDaraja();
        Http::preventStrayRequests();
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'test-access-token',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => 'checkout-failed',
                'ResultCode' => '1032',
            ]),
        ]);

        $this->postJson('/api/mpesa/callback', [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'merchant-failed',
                    'CheckoutRequestID' => 'checkout-failed',
                    'ResultCode' => 1032,
                    'ResultDesc' => 'Request cancelled by user.',
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('mpesa_payments', [
            'checkout_request_id' => 'checkout-failed',
            'status' => MpesaPayment::STATUS_FAILED,
            'result_code' => 1032,
        ]);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'payment_status' => 'unpaid',
            'status' => AppointmentRequest::STATUS_CANCELLED,
        ]);
    }

    public function test_callback_returns_result_code_zero(): void
    {
        $appointment = $this->createAppointment();
        MpesaPayment::factory()->for($appointment, 'appointment')->create([
            'checkout_request_id' => 'checkout-accepted',
        ]);
        $this->fakeSuccessfulCallback();

        $this->postJson('/api/mpesa/callback', $this->successfulCallback('checkout-accepted'))
            ->assertOk()
            ->assertExactJson([
                'ResultCode' => 0,
                'ResultDesc' => 'Accepted',
            ]);
    }

    public function test_phone_number_is_normalized_to_254(): void
    {
        $service = app(MpesaService::class);

        $this->assertSame('254712345678', $service->formatPhone('0712345678'));
        $this->assertSame('254712345678', $service->formatPhone('+254 712 345 678'));
        $this->assertSame('254712345678', $service->formatPhone('712345678'));
    }

    public function test_payment_is_deleted_when_its_appointment_is_deleted(): void
    {
        $appointment = $this->createAppointment();
        $payment = MpesaPayment::factory()->for($appointment, 'appointment')->create();

        $appointment->delete();

        $this->assertModelMissing($payment);
    }

    public function test_admin_can_view_payment_details_and_verify_the_checkout(): void
    {
        $this->withoutVite();
        $admin = User::factory()->create(['is_admin' => true]);
        $appointment = $this->createAppointment();
        $appointment->forceFill([
            'payment_status' => 'paid',
            'payment_amount' => 500,
            'mpesa_checkout_request_id' => 'checkout-admin',
            'mpesa_receipt' => 'QWE123',
            'paid_at' => now(),
        ])->save();

        $this->actingAs($admin)
            ->get(route('admin.appointments.show', $appointment->id))
            ->assertOk()
            ->assertSee('paid')
            ->assertSee('KSh 500.00')
            ->assertSee('QWE123')
            ->assertSee('Verify Payment')
            ->assertSee('/api/mpesa/status/checkout-admin');
    }

    public function test_payment_status_endpoint_returns_the_provider_response(): void
    {
        $this->configureDaraja();
        MpesaPayment::factory()->create([
            'checkout_request_id' => 'checkout-status',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'test-access-token',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'ResultDesc' => 'The service request is processed successfully.',
            ]),
        ]);

        $this->getJson('/api/mpesa/status/checkout-status')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ResponseCode', '0');
    }

    public function test_payment_status_endpoint_rejects_unknown_checkout_ids(): void
    {
        Http::preventStrayRequests();

        $this->getJson('/api/mpesa/status/not-a-known-checkout')
            ->assertNotFound();
    }

    public function test_callback_does_not_complete_a_payment_when_provider_status_differs(): void
    {
        $payment = MpesaPayment::factory()->create([
            'checkout_request_id' => 'checkout-unverified',
        ]);
        $this->configureDaraja();
        Http::preventStrayRequests();
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'test-access-token',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => 'checkout-unverified',
                'ResultCode' => '1032',
            ]),
        ]);

        $this->postJson('/api/mpesa/callback', $this->successfulCallback('checkout-unverified'))
            ->assertOk();

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_PENDING,
        ]);
    }

    public function test_callback_amount_mismatch_fails_the_payment_and_cancels_the_appointment(): void
    {
        $appointment = $this->createAppointment();
        $payment = MpesaPayment::factory()->for($appointment, 'appointment')->create([
            'checkout_request_id' => 'checkout-amount',
            'amount' => 500,
        ]);
        $callback = $this->successfulCallback('checkout-amount');
        $callback['Body']['stkCallback']['CallbackMetadata']['Item'][0]['Value'] = 1;
        $this->fakeSuccessfulCallback();
        Queue::fake([SendPaymentNotification::class]);

        $this->postJson('/api/mpesa/callback', $callback)->assertOk();

        $this->assertDatabaseHas('mpesa_payments', [
            'id' => $payment->id,
            'status' => MpesaPayment::STATUS_FAILED,
            'result_description' => 'amount_mismatch',
        ]);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'payment_status' => 'unpaid',
            'status' => AppointmentRequest::STATUS_CANCELLED,
        ]);
        Queue::assertPushed(SendPaymentNotification::class, 1);
    }

    public function test_ai_booking_shows_slots_then_charges_for_the_selected_slot(): void
    {
        $this->travelTo('2026-09-26 08:00:00');
        $this->configureDaraja();
        Http::preventStrayRequests();
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'test-access-token',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success.',
                'MerchantRequestID' => 'merchant-ai',
                'CheckoutRequestID' => 'checkout-ai',
            ]),
        ]);

        $doctor = User::factory()->create([
            'is_doctor' => true,
            'name' => 'Dr. Ada Kamau',
        ]);
        DoctorAvailability::factory()->for($doctor, 'doctor')->create([
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration_minutes' => 30,
        ]);

        $pearlie = app(PearlieService::class);
        $sessionId = 'ai-payment-session';
        $bookingStart = $pearlie->processMessage('I want to book', $sessionId);
        $nameReply = $pearlie->processMessage('Jane Doe', $sessionId);
        $phoneReply = $pearlie->processMessage('0712345678', $sessionId);
        $serviceReply = $pearlie->processMessage('consultation', $sessionId);
        $dateReply = $pearlie->processMessage('2026-09-28', $sessionId);
        $slotReply = $pearlie->processMessage('Dr. Ada Kamau at 9:00 AM', $sessionId);

        $this->assertSame('booking_details', $bookingStart['source']);
        $this->assertStringContainsString('full name', $bookingStart['response']);
        $this->assertStringContainsString('phone number', $nameReply['response']);
        $this->assertStringContainsString('service', $phoneReply['response']);
        $this->assertStringContainsString('date', $serviceReply['response']);
        $this->assertSame('availability', $dateReply['source']);
        $this->assertStringContainsString('09:00', $dateReply['response']);
        $this->assertSame('booking_confirmation', $slotReply['source']);
        $this->assertStringContainsString('Reply YES to confirm', $slotReply['response']);
        $this->assertDatabaseMissing('appointment_requests', [
            'session_id' => $sessionId,
        ]);

        $booking = $pearlie->processMessage('YES', $sessionId);

        $this->assertSame('appointment_payment', $booking['source']);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $booking['appointment_id'],
            'doctor_id' => $doctor->id,
            'preferred_date' => '2026-09-28 00:00:00',
            'slot_start_time' => '09:00',
            'payment_status' => 'pending',
            'mpesa_checkout_request_id' => 'checkout-ai',
        ]);
        $this->assertDatabaseHas('mpesa_payments', [
            'appointment_request_id' => $booking['appointment_id'],
            'account_reference' => 'APT-'.$booking['appointment_id'],
            'transaction_desc' => 'Appointment Deposit',
        ]);
    }

    public function test_declining_the_booking_summary_does_not_create_an_appointment_or_charge(): void
    {
        $this->travelTo('2026-09-26 08:00:00');
        Http::preventStrayRequests();

        $doctor = User::factory()->create([
            'is_doctor' => true,
            'name' => 'Dr. Ada Kamau',
        ]);
        DoctorAvailability::factory()->for($doctor, 'doctor')->create([
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration_minutes' => 30,
        ]);

        $service = app(PearlieService::class);
        $summary = $service->processMessage(
            'I want to book an appointment on 2026-09-28 at 9:00 AM. My name is Jane Doe and my phone is 0712345678 for consultation.',
            'cancelled-booking-session',
        );
        $cancellation = $service->processMessage('NO', 'cancelled-booking-session');

        $this->assertSame('booking_confirmation', $summary['source']);
        $this->assertSame('booking_cancelled', $cancellation['source']);
        $this->assertDatabaseMissing('appointment_requests', [
            'session_id' => 'cancelled-booking-session',
        ]);
        $this->assertDatabaseCount('mpesa_payments', 0);
    }

    private function configureDaraja(): void
    {
        config([
            'mpesa.environment' => 'sandbox',
            'mpesa.consumer_key' => 'test-consumer',
            'mpesa.consumer_secret' => 'test-secret',
            'mpesa.passkey' => 'test-passkey',
            'mpesa.shortcode' => '174379',
            'mpesa.callback_url' => 'https://example.test/api/mpesa/callback',
            'mpesa.timeout' => 5,
        ]);
    }

    private function fakeStkPush(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'test-access-token',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success. Request accepted for processing.',
                'MerchantRequestID' => 'merchant-test-1',
                'CheckoutRequestID' => 'ws_CO_test-1',
                'CustomerMessage' => 'Success. Request accepted for processing.',
            ]),
        ]);
    }

    private function fakeSuccessfulCallback(): void
    {
        $this->configureDaraja();
        Http::preventStrayRequests();
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'test-access-token',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => fn ($request) => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => $request['CheckoutRequestID'],
                'ResultCode' => '0',
            ]),
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'message-test']]]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function successfulCallback(string $checkoutRequestId): array
    {
        return [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'merchant-test',
                    'CheckoutRequestID' => $checkoutRequestId,
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 500],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'QWE123'],
                            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function createAppointment(): AppointmentRequest
    {
        return AppointmentRequest::query()->create([
            'session_id' => 'mpesa-test-session',
            'name' => 'Jane Doe',
            'phone' => '0712345678',
            'mpesa_phone' => '0712345678',
            'preferred_date' => now()->addDay()->toDateString(),
            'reason' => 'Consultation',
            'raw_message' => 'Book an appointment',
            'status' => AppointmentRequest::STATUS_PENDING,
            'booking_fee' => 500,
            'payment_status' => 'pending',
        ]);
    }
}
