<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\MpesaPayment;
use App\Services\WhatsAppBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MpesaWhatsAppBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_whatsapp_booking_collects_details_and_starts_an_stk_push(): void
    {
        config([
            'pearlie.booking_fee' => 500,
            'mpesa.consumer_key' => 'consumer',
            'mpesa.consumer_secret' => 'secret',
            'mpesa.shortcode' => '174379',
            'mpesa.passkey' => 'passkey',
            'mpesa.callback_url' => 'https://example.test/api/mpesa/callback',
        ]);

        Http::fake([
            '*oauth/v1/generate*' => Http::response(['access_token' => 'token']),
            '*mpesa/stkpush/v1/processrequest' => Http::response([
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success. Request accepted for processing',
                'MerchantRequestID' => 'merchant-1',
                'CheckoutRequestID' => 'checkout-1',
                'CustomerMessage' => 'Success',
            ]),
        ]);

        $result = app(WhatsAppBookingService::class)->handle(
            '254712345678',
            'Book an appointment. My name is Jane Doe, tomorrow, for a consultation.',
        );

        $this->assertSame('whatsapp_booking', $result['source']);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $result['appointment_id'],
            'name' => 'Jane Doe',
            'phone' => '254712345678',
            'booking_fee' => 500,
            'payment_status' => 'pending',
            'mpesa_checkout_request_id' => 'checkout-1',
        ]);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'stkpush/v1/processrequest'));
    }

    public function test_verified_callback_confirms_appointment_and_is_idempotent(): void
    {
        config([
            'services.whatsapp.phone_number_id' => 'phone-id',
            'services.whatsapp.access_token' => 'token',
            'mpesa.callback_url' => 'https://example.test/api/mpesa/callback',
        ]);

        $appointment = AppointmentRequest::create([
            'session_id' => 'whatsapp:254712345678',
            'name' => 'Jane Doe',
            'phone' => '254712345678',
            'mpesa_phone' => '254712345678',
            'preferred_date' => now()->addDay()->toDateString(),
            'reason' => 'consultation',
            'raw_message' => 'Book appointment',
            'status' => AppointmentRequest::STATUS_PENDING,
            'booking_fee' => 500,
            'payment_status' => 'pending',
            'mpesa_checkout_request_id' => 'checkout-1',
        ]);

        Http::fake();
        $payload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'merchant-1',
                    'CheckoutRequestID' => 'checkout-1',
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 500],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'ABC123'],
                            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/mpesa/callback', $payload)->assertOk();
        $this->postJson('/api/mpesa/callback', $payload)->assertOk();

        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'status' => AppointmentRequest::STATUS_CONFIRMED,
            'payment_status' => 'paid',
            'mpesa_receipt' => 'ABC123',
        ]);
        $this->assertDatabaseCount('mpesa_payments', 1);
        $this->assertDatabaseHas('mpesa_payments', [
            'checkout_request_id' => 'checkout-1',
            'status' => 'paid',
        ]);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
    }
}
