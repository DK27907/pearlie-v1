<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppInboundMessage;
use App\Models\Hospital;
use App\Services\BookingChatService;
use App\Services\Chat\ChatResponse;
use App\Services\WhatsAppBookingService;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WhatsAppIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_verification(): void
    {
        config(['whatsapp.verify_token' => 'verify-secret']);

        $this->get('/api/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=verify-secret&hub.challenge=challenge-123')
            ->assertOk()
            ->assertSeeText('challenge-123');
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        config(['whatsapp.app_secret' => 'webhook-secret']);

        $this->postJson('/api/whatsapp/webhook', ['object' => 'whatsapp_business_account'], [
            'X-Hub-Signature-256' => 'sha256=invalid',
        ])->assertUnauthorized();
    }

    public function test_whatsapp_message_uses_the_configured_access_token(): void
    {
        app()->instance('currentHospital', Hospital::factory()->create());

        config([
            'whatsapp.access_token' => 'test-token',
            'whatsapp.phone_number_id' => 'test-phone-number-id',
            'whatsapp.api_version' => 'v20.0',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]]),
        ]);

        $result = app(WhatsAppService::class)->sendMessage('254712345678', 'Test message');

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_whatsapp_interactive_list_is_bounded_and_uses_quick_reply_values(): void
    {
        config([
            'whatsapp.access_token' => 'test-token',
            'whatsapp.phone_number_id' => 'test-phone-number-id',
            'whatsapp.api_version' => 'v20.0',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.list']]]),
        ]);
        $quickReplies = collect(range(1, 12))
            ->map(fn (int $number): array => [
                'id' => 'service-'.$number,
                'label' => 'Service '.$number,
                'value' => '__service:'.$number.'__',
            ])
            ->all();

        $result = app(WhatsAppService::class)->sendInteractiveList(
            '254712345678',
            'Choose a service.',
            $quickReplies,
        );

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request): bool => $request['type'] === 'interactive'
            && $request['interactive']['type'] === 'list'
            && count($request['interactive']['action']['sections'][0]['rows']) === 10
            && $request['interactive']['action']['sections'][0]['rows'][0]['id'] === '__service:1__');
    }

    public function test_whatsapp_webhook_passes_interactive_list_reply_id_to_booking_flow(): void
    {
        config([
            'whatsapp.app_secret' => 'webhook-secret',
            'whatsapp.phone_number_id' => 'phone-number-id',
            'whatsapp.access_token' => 'whatsapp-token',
        ]);
        Queue::fake();
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'id' => 'wamid.interactive-42',
                            'from' => '254712345678',
                            'type' => 'interactive',
                            'interactive' => [
                                'type' => 'list_reply',
                                'list_reply' => ['id' => '__service:42__', 'title' => 'Consultation'],
                            ],
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

        Queue::assertPushed(ProcessWhatsAppInboundMessage::class, fn (ProcessWhatsAppInboundMessage $job): bool => $job->from === '254712345678'
            && $job->message === '__service:42__'
            && $job->providerMessageId === 'wamid.interactive-42');
    }

    public function test_whatsapp_webhook_queues_button_and_template_replies_for_chat_processing(): void
    {
        config([
            'whatsapp.app_secret' => 'webhook-secret',
            'whatsapp.phone_number_id' => 'phone-number-id',
            'whatsapp.access_token' => 'whatsapp-token',
        ]);
        Queue::fake();
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [
                            [
                                'id' => 'wamid.button-reply',
                                'from' => '254712345678',
                                'type' => 'interactive',
                                'interactive' => [
                                    'type' => 'button_reply',
                                    'button_reply' => ['id' => 'directions', 'title' => 'Directions'],
                                ],
                            ],
                            [
                                'id' => 'wamid.template-button',
                                'from' => '254712345679',
                                'type' => 'button',
                                'button' => ['payload' => 'Hello', 'text' => 'Send Message'],
                            ],
                        ],
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

        Queue::assertPushed(ProcessWhatsAppInboundMessage::class, fn (ProcessWhatsAppInboundMessage $job): bool => $job->providerMessageId === 'wamid.button-reply'
            && $job->message === 'directions');
        Queue::assertPushed(ProcessWhatsAppInboundMessage::class, fn (ProcessWhatsAppInboundMessage $job): bool => $job->providerMessageId === 'wamid.template-button'
            && $job->message === 'Hello');
    }

    public function test_incoming_message_triggers_the_booking_flow(): void
    {
        config([
            'whatsapp.app_secret' => 'webhook-secret',
            'whatsapp.phone_number_id' => 'phone-number-id',
            'whatsapp.access_token' => 'whatsapp-token',
        ]);
        Queue::fake();

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'id' => 'wamid.incoming',
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

        $this->assertDatabaseMissing('appointment_requests', [
            'phone' => '254712345678',
            'session_id' => 'whatsapp:254712345678',
        ]);
        $this->assertDatabaseMissing('conversations', [
            'session_id' => 'whatsapp:254712345678',
            'channel' => 'whatsapp',
        ]);
        Queue::assertPushed(ProcessWhatsAppInboundMessage::class, fn (ProcessWhatsAppInboundMessage $job): bool => $job->message === 'Book an appointment'
            && $job->providerMessageId === 'wamid.incoming');
    }

    public function test_queued_whatsapp_job_shows_typing_before_processing_and_restores_tenant_context(): void
    {
        $hospital = Hospital::factory()->create();
        $previousHospital = Hospital::factory()->create();
        app()->instance('currentHospital', $previousHospital);
        $booking = \Mockery::mock(BookingChatService::class);
        $booking->shouldReceive('handle')
            ->once()
            ->with('254712345678', 'Do you have ultrasound?', 'whatsapp')
            ->andReturnUsing(function () use ($hospital): ChatResponse {
                $this->assertSame($hospital->id, hospital()->id);

                return new ChatResponse('Yes, we offer Ultrasound Scan.', 'IDLE');
            });
        $whatsApp = \Mockery::mock(WhatsAppService::class);
        $whatsApp->shouldReceive('markAsRead')
            ->once()
            ->with('wamid.typing', true);
        $whatsApp->shouldReceive('sendMessage')
            ->once()
            ->with('254712345678', 'Yes, we offer Ultrasound Scan.')
            ->andReturn(['success' => true, 'message' => 'sent']);
        $job = new ProcessWhatsAppInboundMessage(
            (int) $hospital->id,
            '254712345678',
            'Do you have ultrasound?',
            'wamid.typing',
            '1793380000',
        );

        $job->handle($booking, $whatsApp);

        $this->assertSame($previousHospital->id, hospital()->id);
    }

    public function test_whatsapp_typing_indicator_is_sent_with_the_read_receipt(): void
    {
        app()->instance('currentHospital', Hospital::factory()->create());
        config([
            'whatsapp.access_token' => 'test-token',
            'whatsapp.phone_number_id' => 'test-phone-number-id',
            'whatsapp.api_version' => 'v20.0',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['success' => true]),
        ]);

        app(WhatsAppService::class)->markAsRead('wamid.typing', showTypingIndicator: true);

        Http::assertSent(fn ($request): bool => $request['status'] === 'read'
            && $request['message_id'] === 'wamid.typing'
            && $request['typing_indicator'] === ['type' => 'text']);
    }

    public function test_booking_flow_collects_email_and_creates_an_appointment_with_payment(): void
    {
        app()->instance(
            'currentHospital',
            Hospital::query()->where('slug', 'pearl')->firstOrFail(),
        );
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
