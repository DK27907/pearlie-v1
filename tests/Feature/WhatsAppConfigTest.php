<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Services\NotificationService;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_whatsapp_message_uses_the_unified_whatsapp_config_token(): void
    {
        app()->instance('currentHospital', Hospital::factory()->create());

        config([
            'whatsapp.phone_number_id' => 'phone-id',
            'whatsapp.access_token' => 'test-token',
            'whatsapp.api_version' => 'v20.0',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v20.0/phone-id/messages' => Http::response([
                'messages' => [['id' => 'message-test']],
            ]),
        ]);

        $service = new WhatsAppService(new NotificationService);
        $result = $service->sendMessage('254712345678', 'Configuration test');

        $this->assertSame([
            'success' => true,
            'message' => 'WhatsApp message accepted for delivery.',
        ], $result);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://graph.facebook.com/v20.0/phone-id/messages'
            && $request->header('Authorization') === ['Bearer test-token']);
    }
}
