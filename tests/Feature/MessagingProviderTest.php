<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\HospitalIntegrationCredential;
use App\Models\NotificationLog;
use App\Services\HospitalSettings;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MessagingProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_whatsapp_delivery_logs_provider_receipt(): void
    {
        $this->useHospitalWithWhatsAppCredentials();
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.test-message']],
            ], 200),
        ]);

        $sent = app(NotificationService::class)->sendWhatsApp(
            '254748249882',
            'Appointment update',
        );

        $this->assertTrue($sent);
        $this->assertDatabaseHas('notification_logs', [
            'hospital_id' => hospital()->id,
            'channel' => 'whatsapp',
            'provider' => 'meta_whatsapp',
            'status' => 'sent',
            'http_status' => 200,
            'provider_message_id' => 'wamid.test-message',
        ]);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'graph.facebook.com')
            && $request->method() === 'POST');
    }

    #[DataProvider('failedResponses')]
    public function test_provider_error_logs_failure_without_throwing(int $httpStatus): void
    {
        $this->useHospitalWithWhatsAppCredentials();
        config(['pearlie.notification_retry_attempts' => 1]);
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Provider rejected the message.'],
            ], $httpStatus),
        ]);

        $sent = app(NotificationService::class)->sendWhatsApp(
            '254748249882',
            'Appointment update',
        );

        $this->assertFalse($sent);
        $this->assertDatabaseHas('notification_logs', [
            'hospital_id' => hospital()->id,
            'channel' => 'whatsapp',
            'provider' => 'meta_whatsapp',
            'status' => 'failed',
            'http_status' => $httpStatus,
            'failure_code' => 'http_'.$httpStatus,
        ]);
        $notificationLog = NotificationLog::query()
            ->where('hospital_id', hospital()->id)
            ->where('http_status', $httpStatus)
            ->firstOrFail();
        $this->assertSame(
            json_encode(['error' => ['message' => 'Provider rejected the message.']]),
            $notificationLog->provider_response,
        );
        Http::assertSentCount(1);
    }

    public static function failedResponses(): array
    {
        return [
            'client error' => [400],
            'server error' => [503],
        ];
    }

    public function test_missing_whatsapp_credentials_are_logged_without_http_request(): void
    {
        $this->useHospitalWithWhatsAppCredentials();
        HospitalIntegrationCredential::query()
            ->where('hospital_id', hospital()->id)
            ->where('provider', HospitalIntegrationCredential::PROVIDER_WHATSAPP)
            ->delete();
        config([
            'whatsapp.phone_number_id' => null,
            'whatsapp.access_token' => null,
        ]);
        Http::preventStrayRequests();
        Http::fake();

        $sent = app(NotificationService::class)->sendWhatsApp(
            '254748249882',
            'Appointment update',
        );

        $this->assertFalse($sent);
        $this->assertDatabaseHas('notification_logs', [
            'hospital_id' => hospital()->id,
            'channel' => 'whatsapp',
            'provider' => 'meta_whatsapp',
            'status' => 'not_configured',
            'failure_code' => 'credentials_missing',
        ]);
        Http::assertNothingSent();
    }

    public function test_successful_sms_delivery_logs_provider_receipt(): void
    {
        $hospital = Hospital::factory()->create();
        app()->instance('currentHospital', $hospital);
        (new HospitalSettings)->for($hospital)->setCredential(
            HospitalIntegrationCredential::PROVIDER_SMS,
            [
                'provider' => 'twilio',
                'account_sid' => 'ACtest',
                'auth_token' => 'test-token',
                'from_number' => '+15005550006',
            ],
        );
        Http::preventStrayRequests();
        Http::fake([
            'https://api.twilio.com/2010-04-01/Accounts/*/Messages.json' => Http::response([
                'sid' => 'SM-test-message',
            ], 201),
        ]);

        $sent = app(NotificationService::class)->sendSms('254748249882', 'Appointment update');

        $this->assertTrue($sent);
        $this->assertDatabaseHas('notification_logs', [
            'hospital_id' => $hospital->id,
            'channel' => 'sms',
            'provider' => 'twilio',
            'status' => 'sent',
            'http_status' => 201,
            'provider_message_id' => 'SM-test-message',
        ]);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.twilio.com')
            && $request->method() === 'POST');
    }

    public function test_missing_sms_credentials_are_logged_without_http_request(): void
    {
        $hospital = Hospital::factory()->create();
        app()->instance('currentHospital', $hospital);
        config([
            'services.sms_provider' => 'twilio',
            'services.twilio.account_sid' => null,
            'services.twilio.auth_token' => null,
            'services.twilio.api_key' => null,
            'services.twilio.api_secret' => null,
            'services.twilio.from_number' => null,
        ]);
        Http::preventStrayRequests();
        Http::fake();

        $sent = app(NotificationService::class)->sendSms('254748249882', 'Appointment update');

        $this->assertFalse($sent);
        $this->assertDatabaseHas('notification_logs', [
            'hospital_id' => $hospital->id,
            'channel' => 'sms',
            'provider' => 'twilio',
            'status' => 'not_configured',
            'failure_code' => 'credentials_missing',
        ]);
        Http::assertNothingSent();
    }

    public function test_live_whatsapp_delivery_can_be_verified_when_explicitly_enabled(): void
    {
        if (filter_var(env('MESSAGING_LIVE_TEST', false), FILTER_VALIDATE_BOOL) !== true) {
            $this->markTestSkipped('Set MESSAGING_LIVE_TEST=true with a configured provider and test phone.');
        }

        $phone = (string) env('MESSAGING_LIVE_PHONE', '');
        $this->assertNotSame('', $phone, 'MESSAGING_LIVE_PHONE must be provided for a live delivery test.');
        $this->useHospitalWithWhatsAppCredentials();

        $sent = app(NotificationService::class)->sendWhatsApp(
            $phone,
            'MediDesk messaging delivery verification. No action is required.',
        );

        $this->assertTrue($sent);
        $this->assertDatabaseHas('notification_logs', [
            'hospital_id' => hospital()->id,
            'channel' => 'whatsapp',
            'provider' => 'meta_whatsapp',
            'status' => 'sent',
        ]);
    }

    private function useHospitalWithWhatsAppCredentials(): Hospital
    {
        $hospital = Hospital::factory()->create();
        app()->instance('currentHospital', $hospital);
        (new HospitalSettings)->for($hospital)->setCredential(
            HospitalIntegrationCredential::PROVIDER_WHATSAPP,
            [
                'phone_number_id' => 'test-phone-number-id',
                'access_token' => 'test-access-token',
                'api_version' => 'v20.0',
            ],
        );

        return $hospital;
    }
}
