<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use App\Services\NotificationService;

class NotificationServiceRetryTest extends TestCase
{
    public function test_africastalking_retries_then_succeeds_and_skips_twilio()
    {
        // Configure env for AT and Twilio
        $_ENV['AFRICASTALKING_USERNAME'] = 'testuser';
        $_ENV['AFRICASTALKING_API_KEY'] = 'testkey';
        $_ENV['TWILIO_SID'] = 'twiliosid';
        $_ENV['TWILIO_TOKEN'] = 'twiliotoken';
        $_ENV['TWILIO_FROM'] = '+15550000000';

        $atCount = 0;

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

        $svc = new NotificationService();
        $svc->sendSms('+254700000000', 'retry test');

        // AT should have been called 3 times (two failures then success)
        $this->assertEquals(3, $atCount, 'AfricaTalking should have been attempted 3 times');

        // Twilio should NOT have been called because AT eventually succeeded
        Http::assertSent(function ($req) {
            return str_contains($req->url(), 'africastalking.com/version1/messaging');
        });
        Http::assertSentCount(3);
    }

    public function test_twilio_retries_when_africastalking_missing_or_fails_completely()
    {
        // Remove AT env
        unset($_ENV['AFRICASTALKING_USERNAME'], $_ENV['AFRICASTALKING_API_KEY']);

        $_ENV['TWILIO_SID'] = 'twiliosid2';
        $_ENV['TWILIO_TOKEN'] = 'twiliotoken2';
        $_ENV['TWILIO_FROM'] = '+15551112222';

        $twCount = 0;

        Http::fake([
            'https://api.twilio.com/2010-04-01/Accounts/*/Messages.json' => function ($request) use (&$twCount) {
                $twCount++;
                if ($twCount < 2) {
                    return Http::response('error', 500);
                }
                return Http::response(['sid' => 'SMOK'], 201);
            },
        ]);

        $svc = new NotificationService();
        $svc->sendSms('+254700000001', 'twilio retry test');

        $this->assertGreaterThanOrEqual(2, $twCount, 'Twilio should have been attempted at least twice');

        Http::assertSent(function ($req) {
            return str_contains($req->url(), 'twilio.com/2010-04-01/Accounts');
        });
    }
}
