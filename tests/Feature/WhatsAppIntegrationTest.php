<?php

namespace Tests\Feature;

use App\Services\WhatsAppBookingService;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_verification(): void
    {
        config(['services.whatsapp.verify_token' => 'verify-secret']);

        $this->get('/api/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=verify-secret&hub.challenge=challenge-123')
            ->assertOk()
            ->assertSeeText('challenge-123');
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        config(['services.whatsapp.app_secret' => 'webhook-secret']);

        $this->postJson('/api/whatsapp/webhook', ['object' => 'whatsapp_business_account'], [
            'X-Hub-Signature-256' => 'sha256=invalid',
        ])->assertUnauthorized();
    }

    public function test_incoming_message_triggers_the_booking_flow(): void
    {
        config([
            'services.whatsapp.app_secret' => 'webhook-secret',
            'services.whatsapp.phone_number_id' => 'phone-number-id',
            'services.whatsapp.access_token' => 'whatsapp-token',
            'whatsapp.phone_number_id' => 'phone-number-id',
            'whatsapp.access_token' => 'whatsapp-token',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.response']]]),
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'from' => '254712345678',
                            'type' => 'text',
                            'text' => ['body' => 'Book an appointment'],
                        ]],
                    ],
                ]],
            ]],
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'webhook-secret');

        $this->call('POST', '/api/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $body)->assertOk();

        $this->assertDatabaseHas('appointment_requests', [
            'phone' => '254712345678',
            'session_id' => 'whatsapp:254712345678',
        ]);
        $this->assertSame(
            'ask_name',
            Cache::get('whatsapp_booking_254712345678')['state'],
        );
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/messages')
            && $request['messaging_product'] === 'whatsapp');
    }

    public function test_booking_flow_collects_email_and_creates_an_appointment_with_payment(): void
    {
        config([
            'pearlie.booking_fee' => 500,
            'mpesa.consumer_key' => 'consumer',
            'mpesa.consumer_secret' => 'secret',
            'mpesa.shortcode' => '174379',
            'mpesa.passkey' => 'passkey',
            'mpesa.callback_url' => 'https://example.test/api/mpesa/callback',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            '*oauth/v1/generate*' => Http::response(['access_token' => 'token']),
            '*mpesa/stkpush/v1/processrequest' => Http::response([
                'ResponseCode' => '0',
                'MerchantRequestID' => 'merchant-booking',
                'CheckoutRequestID' => 'checkout-booking',
                'CustomerMessage' => 'Success',
            ]),
        ]);

        $service = app(WhatsAppBookingService::class);
        $firstReply = $service->handleMessage(
            '254712345678',
            'Book appointment. My name is Jane Doe tomorrow for consultation.',
        );
        $this->assertStringContainsString('email address', $firstReply['response']);

        $result = $service->handleMessage('254712345678', 'jane@example.com');

        $this->assertSame('whatsapp_booking', $result['source']);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $result['appointment_id'],
            'email' => 'jane@example.com',
            'payment_status' => 'pending',
            'mpesa_checkout_request_id' => 'checkout-booking',
        ]);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'stkpush/v1/processrequest'));
    }

    public function test_swahili_booking_starts_a_localized_booking_flow(): void
    {
        $result = app(WhatsAppBookingService::class)->handleMessage(
            '254712345678',
            'Ninataka kuweka miadi',
        );

        $this->assertSame('whatsapp_booking', $result['source']);
        $this->assertStringContainsString('Tafadhali', $result['response']);
        $this->assertStringContainsString('jina lako kamili', $result['response']);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $result['appointment_id'],
            'phone' => '254712345678',
            'status' => 'pending',
        ]);
        $this->assertSame(
            'sw',
            Cache::get('whatsapp_booking_254712345678')['language'],
        );
    }

    public function test_phone_number_is_normalized_to_254_format(): void
    {
        $this->assertSame('254712345678', app(WhatsAppService::class)->formatPhone('0712 345 678'));
    }
}
