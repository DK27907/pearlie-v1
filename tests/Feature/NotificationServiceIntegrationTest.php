<?php

namespace Tests\Feature;

use App\Services\NotificationService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationServiceIntegrationTest extends TestCase
{
    public function test_send_whatsapp_when_credentials_present()
    {
        config([
            'services.whatsapp.phone_number_id' => '12345',
            'services.whatsapp.access_token' => 'test-access-token',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'message-test']]]),
        ]);

        $svc = new NotificationService;
        $svc->sendWhatsApp('+254700000000', 'Hello via WhatsApp');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'graph.facebook.com') && $request->method() === 'POST';
        });

    }

    public function test_africastalking_then_twilio_fallback_behavior()
    {
        config([
            'services.africastalking.username' => 'testuser',
            'services.africastalking.api_key' => 'testkey',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.africastalking.com/version1/messaging' => Http::response('ok', 200),
        ]);

        $svc = new NotificationService;
        $svc->sendSms('+254700000000', 'Message AT');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.africastalking.com');
        });

        config([
            'services.africastalking.username' => null,
            'services.africastalking.api_key' => null,
            'services.twilio.account_sid' => 'abc',
            'services.twilio.auth_token' => 'def',
            'services.twilio.from_number' => '+15005550006',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.twilio.com/2010-04-01/Accounts/*/Messages.json' => Http::response(['sid' => 'SM123'], 201),
        ]);

        $svc->sendSms('+15005550006', 'Message Twilio');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.twilio.com');
        });

    }
}
