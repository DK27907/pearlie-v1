<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use App\Services\NotificationService;

class NotificationServiceIntegrationTest extends TestCase
{
    public function test_send_whatsapp_when_credentials_present()
    {
        Http::fake();

        putenv('WHATSAPP_PHONE_NUMBER_ID=12345');
        putenv('WHATSAPP_ACCESS_TOKEN=secrettoken');

        $svc = new NotificationService();
        $svc->sendWhatsApp('+254700000000', 'Hello via WhatsApp');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'graph.facebook.com') && $request->method() === 'POST';
        });

        putenv('WHATSAPP_PHONE_NUMBER_ID');
        putenv('WHATSAPP_ACCESS_TOKEN');
    }

    public function test_africastalking_then_twilio_fallback_behavior()
    {
        Http::fake();

        // Case 1: Africa's Talking set
        putenv('AFRICASTALKING_USERNAME=testuser');
        putenv('AFRICASTALKING_API_KEY=testkey');

        $svc = new NotificationService();
        $svc->sendSms('+254700000000', 'Message AT');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.africastalking.com');
        });

        // Clean AT
        putenv('AFRICASTALKING_USERNAME');
        putenv('AFRICASTALKING_API_KEY');

        // Case 2: Twilio fallback
        putenv('TWILIO_SID=abc');
        putenv('TWILIO_TOKEN=def');
        putenv('TWILIO_FROM=+15005550006');

        $svc->sendSms('+15005550006', 'Message Twilio');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.twilio.com');
        });

        putenv('TWILIO_SID');
        putenv('TWILIO_TOKEN');
        putenv('TWILIO_FROM');
    }
}
