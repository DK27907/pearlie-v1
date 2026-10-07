<?php

namespace Tests\Feature;

use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_africas_talking_used_when_credentials_present()
    {
        config([
            'services.sms_provider' => 'africastalking',
            'services.africastalking.username' => 'testuser',
            'services.africastalking.api_key' => 'testkey',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.africastalking.com/version1/messaging' => Http::response('ok', 200),
        ]);

        $svc = new NotificationService;
        $svc->sendSms('+254700000000', 'Test message');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.africastalking.com') && $request->method() === 'POST';
        });

    }

    public function test_twilio_is_used_when_selected_as_the_provider()
    {
        config([
            'services.sms_provider' => 'twilio',
            'services.africastalking.username' => null,
            'services.africastalking.api_key' => null,
            'services.twilio.account_sid' => 'testsid',
            'services.twilio.auth_token' => 'testtoken',
            'services.twilio.from_number' => '+15005550006',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.twilio.com/2010-04-01/Accounts/*/Messages.json' => Http::response(['sid' => 'SM123'], 201),
        ]);

        $svc = new NotificationService;
        $svc->sendSms('+15005550006', 'Fallback message');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.twilio.com') && $request->method() === 'POST';
        });

    }

    public function test_twilio_api_key_credentials_are_supported(): void
    {
        config([
            'services.sms_provider' => 'twilio',
            'services.africastalking.username' => null,
            'services.africastalking.api_key' => null,
            'services.twilio.account_sid' => 'ACtest',
            'services.twilio.api_key' => 'SKtest',
            'services.twilio.api_secret' => 'test-secret',
            'services.twilio.from_number' => '+15005550006',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.twilio.com/2010-04-01/Accounts/*/Messages.json' => Http::response(['sid' => 'SM123'], 201),
        ]);

        app(NotificationService::class)->sendSms('+254700000000', 'Confirmed');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.twilio.com/2010-04-01/Accounts/ACtest/Messages.json')
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization');
        });
    }
}
