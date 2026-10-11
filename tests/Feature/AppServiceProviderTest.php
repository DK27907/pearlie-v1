<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AppServiceProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_boot_logs_warnings_for_each_missing_critical_integration_credential(): void
    {
        Config::set([
            'services.groq.api_key' => null,
            'mpesa.consumer_key' => null,
            'mpesa.consumer_secret' => null,
            'mpesa.passkey' => null,
            'whatsapp.access_token' => null,
            'whatsapp.phone_number_id' => null,
            'whatsapp.app_secret' => null,
            'services.africastalking.username' => null,
            'services.africastalking.api_key' => null,
            'services.twilio.account_sid' => null,
            'services.twilio.auth_token' => null,
            'services.twilio.api_key' => null,
            'services.twilio.api_secret' => null,
            'services.twilio.from_number' => null,
            'mail.default' => 'log',
        ]);
        Log::spy();

        (new AppServiceProvider($this->app))->boot();

        foreach ([
            'GROQ_API_KEY',
            'MPESA_CONSUMER_KEY',
            'MPESA_CONSUMER_SECRET',
            'MPESA_PASSKEY',
            'WHATSAPP_ACCESS_TOKEN',
            'WHATSAPP_PHONE_NUMBER_ID',
            'WHATSAPP_APP_SECRET',
        ] as $key) {
            Log::shouldHaveReceived('warning')
                ->with('Critical integration credential is missing.', ['key' => $key])
                ->once();
        }
        foreach (['AFRICASTALKING_USERNAME', 'AFRICASTALKING_API_KEY', 'TWILIO_ACCOUNT_SID'] as $key) {
            Log::shouldHaveReceived('warning')
                ->with('SMS provider credential is missing.', ['key' => $key])
                ->once();
        }
        Log::shouldHaveReceived('warning')
            ->with('Mail is configured without external delivery.', ['mailer' => 'log'])
            ->once();
    }
}
