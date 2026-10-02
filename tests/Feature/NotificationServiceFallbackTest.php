<?php

namespace Tests\Feature;

use App\Services\NotificationService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationServiceFallbackTest extends TestCase
{
    public function test_send_sms_uses_africastalking_and_falls_back_to_twilio_on_failure()
    {
        config([
            'services.africastalking.username' => 'testuser',
            'services.africastalking.api_key' => 'testkey',
            'services.twilio.account_sid' => 'twiliosid',
            'services.twilio.auth_token' => 'twiliotoken',
            'services.twilio.from_number' => '+15550000000',
        ]);

        // Fake HTTP responses: AT returns 500 (non-exception); NotificationService now treats non-2xx as failure and should fall back to Twilio.
        Http::preventStrayRequests();
        Http::fake([
            'https://api.africastalking.com/version1/messaging' => Http::response('AT failure', 500),
            'https://api.twilio.com/2010-04-01/Accounts/*/Messages.json' => Http::response(['sid' => 'SM123'], 201),
        ]);

        $svc = new NotificationService;
        $to = '+254700000000';
        $message = 'New escalation: test message';

        $svc->sendSms($to, $message);

        // Both Africa's Talking and Twilio should have been attempted because AT returned non-2xx
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'africastalking.com/version1/messaging');
        });
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'twilio.com/2010-04-01/Accounts');
        });

        // Now simulate an exception in AT so the service falls back to Twilio as well
        Http::preventStrayRequests();
        Http::fake([
            'https://api.africastalking.com/version1/messaging' => function ($request) {
                throw new \Exception('Network error to AT');
            },
            'https://api.twilio.com/2010-04-01/Accounts/*/Messages.json' => Http::response(['sid' => 'SM123'], 201),
        ]);

        $svc->sendSms($to, $message);

        // Twilio fallback should be invoked
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'twilio.com/2010-04-01/Accounts');
        });
    }

    public function test_send_sms_uses_explicitly_selected_twilio_provider()
    {
        config([
            'services.sms_provider' => 'twilio',
            'services.africastalking.username' => null,
            'services.africastalking.api_key' => null,
            'services.twilio.account_sid' => 'twiliosid2',
            'services.twilio.auth_token' => 'twiliotoken2',
            'services.twilio.from_number' => '+15551112222',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.twilio.com/2010-04-01/Accounts/*/Messages.json' => Http::response(['sid' => 'SM456'], 201),
        ]);

        $svc = new NotificationService;
        $svc->sendSms('+254700000001', 'Fallback only test');

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'twilio.com/2010-04-01/Accounts');
        });
    }
}
