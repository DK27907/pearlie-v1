<?php

namespace Tests\Feature;

use App\Services\NotificationService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationServiceRetryTest extends TestCase
{
    public function test_africastalking_retries_then_succeeds_and_skips_twilio()
    {
        config([
            'services.sms_provider' => 'africastalking',
            'services.africastalking.username' => 'testuser',
            'services.africastalking.api_key' => 'testkey',
            'services.twilio.account_sid' => 'twiliosid',
            'services.twilio.auth_token' => 'twiliotoken',
            'services.twilio.from_number' => '+15550000000',
        ]);

        $atCount = 0;

        Http::preventStrayRequests();
        Http::fake([
            'https://api.africastalking.com/version1/messaging' => function ($request) use (&$atCount) {
                $atCount++;
                if ($atCount < 3) {
                    return Http::response('error', 500);
                }

                return Http::response('ok', 200);
            },
            'https://api.twilio.com/2010-04-01/Accounts/*/Messages.json' => Http::response('twilio', 201),
        ]);

        $svc = new NotificationService;
        $svc->sendSms('+254700000000', 'retry test');

        // AT should have been called 3 times (two failures then success)
        $this->assertEquals(3, $atCount, 'AfricaTalking should have been attempted 3 times');

        // Twilio should NOT have been called because AT eventually succeeded
        Http::assertSent(function ($req) {
            return str_contains($req->url(), 'africastalking.com/version1/messaging');
        });
        Http::assertSentCount(3);
    }

    public function test_twilio_retries_when_selected_as_the_provider()
    {
        config([
            'services.sms_provider' => 'twilio',
            'services.africastalking.username' => null,
            'services.africastalking.api_key' => null,
            'services.twilio.account_sid' => 'twiliosid2',
            'services.twilio.auth_token' => 'twiliotoken2',
            'services.twilio.from_number' => '+15551112222',
        ]);

        $twCount = 0;

        Http::preventStrayRequests();
        Http::fake([
            'https://api.twilio.com/2010-04-01/Accounts/*/Messages.json' => function ($request) use (&$twCount) {
                $twCount++;
                if ($twCount < 2) {
                    return Http::response('error', 500);
                }

                return Http::response(['sid' => 'SMOK'], 201);
            },
        ]);

        $svc = new NotificationService;
        $svc->sendSms('+254700000001', 'twilio retry test');

        $this->assertGreaterThanOrEqual(2, $twCount, 'Twilio should have been attempted at least twice');

        Http::assertSent(function ($req) {
            return str_contains($req->url(), 'twilio.com/2010-04-01/Accounts');
        });
    }
}
