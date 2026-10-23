<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\HospitalIntegrationCredential;
use App\Models\NotificationLog;
use App\Services\HospitalSettings;
use App\Services\NotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MessagingProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_whatsapp_delivery_logs_provider_receipt(): void
    {
        $this->useHospitalWithWhatsAppCredentials();
        $appointment = AppointmentRequest::factory()->create([
            'hospital_id' => hospital()->id,
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.test-message']],
            ], 200),
        ]);

        $sent = app(NotificationService::class)->sendWhatsApp(
            '254748249882',
            'Appointment update',
            $appointment->id,
        );

        $this->assertTrue($sent);
        $this->assertDatabaseHas('notification_logs', [
            'hospital_id' => hospital()->id,
            'channel' => 'whatsapp',
            'provider' => 'meta_whatsapp',
            'status' => 'sent',
            'http_status' => 200,
            'provider_message_id' => 'wamid.test-message',
            'appointment_request_id' => $appointment->id,
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
        $appointment = AppointmentRequest::factory()->create([
            'hospital_id' => $hospital->id,
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.twilio.com/2010-04-01/Accounts/*/Messages.json' => Http::response([
                'sid' => 'SM-test-message',
            ], 201),
        ]);

        $sent = app(NotificationService::class)->sendSms(
            '254748249882',
            'Appointment update',
            $appointment->id,
        );

        $this->assertTrue($sent);
        $this->assertDatabaseHas('notification_logs', [
            'hospital_id' => $hospital->id,
            'channel' => 'sms',
            'provider' => 'twilio',
            'status' => 'sent',
            'http_status' => 201,
            'provider_message_id' => 'SM-test-message',
            'appointment_request_id' => $appointment->id,
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

    public function test_notification_logs_reject_rows_without_a_hospital(): void
    {
        $this->expectException(QueryException::class);

        NotificationLog::query()->create([
            'channel' => 'sms',
            'provider' => 'twilio',
            'status' => 'failed',
        ]);
    }

    public function test_hospitals_with_notification_logs_cannot_be_deleted(): void
    {
        $hospital = Hospital::factory()->create();
        NotificationLog::query()->create([
            'hospital_id' => $hospital->id,
            'channel' => 'sms',
            'provider' => 'twilio',
            'status' => 'sent',
        ]);
        $this->expectException(QueryException::class);

        $hospital->delete();
    }

    public function test_provider_response_scrubs_phone_tokens_and_message_bodies(): void
    {
        $hospital = Hospital::factory()->create();
        $log = NotificationLog::factory()->for($hospital)->create([
            'provider_response' => json_encode([
                'to' => '254748249882',
                'authorization' => 'Bearer stored-access-token',
                'details' => 'Bearer inline-secret for 254748249882',
                'body' => 'Sensitive appointment message',
            ], JSON_THROW_ON_ERROR),
        ]);
        $storedResponse = (string) DB::table('notification_logs')
            ->where('id', $log->id)
            ->value('provider_response');

        $this->assertStringContainsString('[REDACTED_PHONE]', $storedResponse);
        $this->assertStringContainsString('[REDACTED_MESSAGE]', $storedResponse);
        $this->assertStringContainsString('[REDACTED]', $storedResponse);
        $this->assertStringNotContainsString('254748249882', $storedResponse);
        $this->assertStringNotContainsString('stored-access-token', $storedResponse);
        $this->assertStringNotContainsString('inline-secret', $storedResponse);
        $this->assertStringNotContainsString('Sensitive appointment message', $storedResponse);
        $this->assertArrayNotHasKey('provider_response', $log->fresh()->toArray());
        $this->assertSame($storedResponse, $log->fresh()->toArray()['scrubbed_provider_response']);
    }

    public function test_messaging_without_a_hospital_fails_before_sending(): void
    {
        config([
            'whatsapp.phone_number_id' => 'test-phone-number-id',
            'whatsapp.access_token' => 'test-access-token',
        ]);
        Http::preventStrayRequests();
        Http::fake();

        try {
            app(NotificationService::class)->sendWhatsApp('254748249882', 'Appointment update');
            $this->fail('Sending a tenant notification without a hospital must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'A hospital context is required to send tenant notifications.',
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('notification_logs', 0);
    }

    public function test_messaging_with_a_deleted_hospital_context_fails_before_sending(): void
    {
        $hospital = Hospital::factory()->create();
        $hospital->delete();
        app()->instance('currentHospital', $hospital);
        config([
            'whatsapp.phone_number_id' => 'test-phone-number-id',
            'whatsapp.access_token' => 'test-access-token',
        ]);
        Http::preventStrayRequests();
        Http::fake();

        try {
            app(NotificationService::class)->sendWhatsApp('254748249882', 'Appointment update');
            $this->fail('Sending with a deleted hospital context must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'A hospital context is required to send tenant notifications.',
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('notification_logs', 0);
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
