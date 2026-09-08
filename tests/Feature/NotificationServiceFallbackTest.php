<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Artisan;
use App\Services\NotificationService;

class NotificationServiceFallbackTest extends TestCase
{
    public function test_sendSms_uses_africastalking_and_falls_back_to_twilio_on_failure()
    {
        // Ensure environment variables for both providers are present for this test
        putenv('AFRICASTALKING_USERNAME=testuser');
        putenv('AFRICASTALKING_API_KEY=testkey');
        // also populate superglobals so env() sees them reliably in tests
        $_ENV['AFRICASTALKING_USERNAME'] = 'testuser';
        $_ENV['AFRICASTALKING_API_KEY'] = 'testkey';
        $_SERVER['AFRICASTALKING_USERNAME'] = 'testuser';
        $_SERVER['AFRICASTALKING_API_KEY'] = 'testkey';

        putenv('TWILIO_SID=twiliosid');
        putenv('TWILIO_TOKEN=twiliotoken');
        putenv('TWILIO_FROM=+15550000000');
        $_ENV['TWILIO_SID'] = 'twiliosid';
        $_ENV['TWILIO_TOKEN'] = 'twiliotoken';
        $_ENV['TWILIO_FROM'] = '+15550000000';
        $_SERVER['TWILIO_SID'] = 'twiliosid';
        $_SERVER['TWILIO_TOKEN'] = 'twiliotoken';
        $_SERVER['TWILIO_FROM'] = '+15550000000';

        // Fake HTTP responses: AT returns 500 (non-exception); NotificationService now treats non-2xx as failure and should fall back to Twilio.
        Http::fake([
            'https://api.africastalking.com/version1/messaging' => Http::response('AT failure', 500),
            'https://api.twilio.com/2010-04-01/Accounts/*/Messages.json' => Http::response(['sid' => 'SM123'], 201),
        ]);

        $svc = new NotificationService();
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

    public function test_sendSms_uses_twilio_when_africastalking_not_configured()
    {
        // Unset Africa's Talking env
        putenv('AFRICASTALKING_USERNAME');
        putenv('AFRICASTALKING_API_KEY');
        unset($_ENV['AFRICASTALKING_USERNAME'], $_ENV['AFRICASTALKING_API_KEY'], $_SERVER['AFRICASTALKING_USERNAME'], $_SERVER['AFRICASTALKING_API_KEY']);

        // Set Twilio
        putenv('TWILIO_SID=twiliosid2');
        putenv('TWILIO_TOKEN=twiliotoken2');
        putenv('TWILIO_FROM=+15551112222');
        $_ENV['TWILIO_SID'] = 'twiliosid2';
        $_ENV['TWILIO_TOKEN'] = 'twiliotoken2';
        $_ENV['TWILIO_FROM'] = '+15551112222';
        $_SERVER['TWILIO_SID'] = 'twiliosid2';
        $_SERVER['TWILIO_TOKEN'] = 'twiliotoken2';
        $_SERVER['TWILIO_FROM'] = '+15551112222';

        Http::fake([
            'https://api.twilio.com/2010-04-01/Accounts/*/Messages.json' => Http::response(['sid' => 'SM456'], 201),
        ]);

        $svc = new NotificationService();
        $svc->sendSms('+254700000001', 'Fallback only test');

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'twilio.com/2010-04-01/Accounts');
        });
    }
}
