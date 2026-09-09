<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use App\Services\NotificationService;

class NotificationServiceTest extends TestCase
{
    public function test_africas_talking_used_when_credentials_present()
    {
        Http::fake();

        putenv('AFRICASTALKING_USERNAME=testuser');
        putenv('AFRICASTALKING_API_KEY=testkey');

        $svc = new NotificationService();
        $svc->sendSms('+254700000000', 'Test message');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.africastalking.com') && $request->method() === 'POST';
        });

        // Clean up
        putenv('AFRICASTALKING_USERNAME');
        putenv('AFRICASTALKING_API_KEY');
    }

    public function test_twilio_fallback_used_when_africas_talking_not_present()
    {
        Http::fake();

        putenv('TWILIO_SID=testsid');
        putenv('TWILIO_TOKEN=testtoken');
        putenv('TWILIO_FROM=+15005550006');

        // Ensure Africa's Talking not set
        putenv('AFRICASTALKING_USERNAME');
        putenv('AFRICASTALKING_API_KEY');

        $svc = new NotificationService();
        $svc->sendSms('+15005550006', 'Fallback message');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.twilio.com') && $request->method() === 'POST';
        });

        putenv('TWILIO_SID');
        putenv('TWILIO_TOKEN');
        putenv('TWILIO_FROM');
    }

    public function test_twilio_api_key_credentials_are_supported(): void
    {
        Http::fake();

        putenv('AFRICASTALKING_USERNAME');
        putenv('AFRICASTALKING_API_KEY');
        putenv('TWILIO_ACCOUNT_SID=ACtest');
        putenv('TWILIO_API_KEY=SKtest');
        putenv('TWILIO_API_SECRET=secret');
        putenv('TWILIO_FROM_NUMBER=+15005550006');

        app(NotificationService::class)->sendSms('+254700000000', 'Confirmed');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.twilio.com/2010-04-01/Accounts/ACtest/Messages.json')
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization');
        });

        putenv('TWILIO_ACCOUNT_SID');
        putenv('TWILIO_API_KEY');
        putenv('TWILIO_API_SECRET');
        putenv('TWILIO_FROM_NUMBER');
    }
}
