<?php

namespace Tests\Feature;

use App\Console\Commands\MpesaHealthCheck;
use App\Console\Commands\ReconcileMpesaPayments;
use App\Exceptions\MissingProviderCredentialsException;
use App\Http\Controllers\WhatsAppWebhookController;
use App\Mail\MarketingDemoRequest;
use App\Models\Hospital;
use App\Services\HospitalMailService;
use App\Services\HospitalSettings;
use App\Services\MpesaService;
use App\Services\NotificationService;
use App\Services\WhatsAppService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PerHospitalIntegrationCredentialsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mpesa_service_uses_hospital_credentials_when_configured(): void
    {
        $hospital = Hospital::factory()->create();
        $this->setCurrentHospital($hospital);
        app(HospitalSettings::class)->for($hospital)->setCredential('mpesa', [
            'consumer_key' => 'hospital-a-key',
            'consumer_secret' => 'hospital-a-secret',
            'shortcode' => '123456',
            'passkey' => 'hospital-a-passkey',
        ]);
        $this->fakeMpesaOAuth();

        app(MpesaService::class)->getAccessToken();

        Http::assertSent(fn ($request): bool => $request->hasHeader(
            'Authorization',
            'Basic '.base64_encode('hospital-a-key:hospital-a-secret'),
        ));
    }

    public function test_mpesa_service_falls_back_to_env_when_hospital_has_no_credentials(): void
    {
        config([
            'integrations.allow_global_fallback' => false,
            'integrations.allow_mpesa_global_fallback' => true,
            'mpesa.consumer_key' => 'global-test-key',
            'mpesa.consumer_secret' => 'global-test-secret',
        ]);
        $this->setCurrentHospital(Hospital::factory()->create());
        $this->fakeMpesaOAuth();

        app(MpesaService::class)->getAccessToken();

        Http::assertSent(fn ($request): bool => $request->hasHeader(
            'Authorization',
            'Basic '.base64_encode('global-test-key:global-test-secret'),
        ));
    }

    public function test_mpesa_fallback_uses_the_platform_callback_url_with_tenant_context(): void
    {
        $hospital = Hospital::factory()->create(['subscription_plan' => 'enterprise']);
        $this->setCurrentHospital($hospital);
        config([
            'integrations.allow_global_fallback' => false,
            'integrations.allow_mpesa_global_fallback' => true,
            'mpesa.consumer_key' => 'global-test-key',
            'mpesa.consumer_secret' => 'global-test-secret',
            'mpesa.shortcode' => '174379',
            'mpesa.passkey' => 'global-test-passkey',
            'mpesa.callback_url' => 'https://example.test/api/mpesa/callback',
            'mpesa.environment' => 'sandbox',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'test-oauth-token',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => 'checkout-platform-fallback',
                'MerchantRequestID' => 'merchant-platform-fallback',
            ]),
        ]);

        $result = app(MpesaService::class)->stkPush(
            '0748249882',
            1,
            'APT-1',
            'Appointment booking',
        );

        $this->assertSame('checkout-platform-fallback', $result['checkout_request_id']);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url()
            === 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest'
            && $request['CallBackURL'] === 'https://example.test/api/mpesa/callback?hospital='.$hospital->slug);
    }

    public function test_mpesa_service_uses_the_configured_ca_bundle_for_http_requests(): void
    {
        $caBundle = tempnam(sys_get_temp_dir(), 'mpesa-ca-');
        if ($caBundle === false) {
            $this->fail('Could not create a temporary CA bundle.');
        }

        file_put_contents($caBundle, 'test CA bundle');
        $this->setCurrentHospital(Hospital::factory()->create());
        config([
            'integrations.allow_global_fallback' => true,
            'mpesa.ca_bundle' => $caBundle,
            'mpesa.consumer_key' => 'global-test-key',
            'mpesa.consumer_secret' => 'global-test-secret',
        ]);
        $verifyOption = null;
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request, array $options) use (&$verifyOption): PromiseInterface {
            $verifyOption = $options['verify'] ?? null;

            return Http::response(['access_token' => 'test-oauth-token']);
        });

        try {
            app(MpesaService::class)->getAccessToken();

            $this->assertSame($caBundle, $verifyOption);
        } finally {
            unlink($caBundle);
        }
    }

    public function test_whatsapp_service_uses_hospital_credentials_when_configured(): void
    {
        $hospital = Hospital::factory()->create();
        $this->setCurrentHospital($hospital);
        app(HospitalSettings::class)->for($hospital)->setCredential('whatsapp', [
            'phone_number_id' => 'hospital-phone-id',
            'access_token' => 'hospital-access-token',
            'api_version' => 'v99.0',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v99.0/hospital-phone-id/messages' => Http::response([
                'messages' => [['id' => 'hospital-message']],
            ]),
        ]);

        app(WhatsAppService::class)->sendTemplate('254712345678', 'appointment_update', []);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://graph.facebook.com/v99.0/hospital-phone-id/messages'
            && $request->hasHeader('Authorization', 'Bearer hospital-access-token'));
    }

    public function test_whatsapp_service_falls_back_to_env_when_hospital_has_no_credentials(): void
    {
        config([
            'integrations.allow_global_fallback' => true,
            'whatsapp.phone_number_id' => 'global-phone-id',
            'whatsapp.access_token' => 'global-access-token',
            'whatsapp.api_version' => 'v20.0',
        ]);
        $this->setCurrentHospital(Hospital::factory()->create());
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v20.0/global-phone-id/messages' => Http::response([
                'messages' => [['id' => 'global-message']],
            ]),
        ]);

        app(WhatsAppService::class)->sendTemplate('254712345678', 'appointment_update', []);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://graph.facebook.com/v20.0/global-phone-id/messages'
            && $request->hasHeader('Authorization', 'Bearer global-access-token'));
    }

    public function test_sms_uses_hospital_provider_and_credentials(): void
    {
        $hospital = Hospital::factory()->create();
        $this->setCurrentHospital($hospital);
        app(HospitalSettings::class)->for($hospital)->setCredential('sms', [
            'provider' => 'africastalking',
            'username' => 'hospital-a-sms',
            'api_key' => 'hospital-a-sms-key',
            'sender_id' => 'HOSPITALA',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.africastalking.com/version1/messaging' => Http::response('ok', 200),
        ]);

        $this->assertTrue(app(NotificationService::class)->sendSms('+254712345678', 'Test message'));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.africastalking.com/version1/messaging'
            && $request['username'] === 'hospital-a-sms'
            && $request->hasHeader('apiKey', 'hospital-a-sms-key'));
    }

    public function test_sms_falls_back_to_env_when_hospital_has_no_credentials(): void
    {
        config([
            'integrations.allow_global_fallback' => true,
            'services.sms_provider' => 'africastalking',
            'services.africastalking.username' => 'global-sms',
            'services.africastalking.api_key' => 'global-sms-key',
        ]);
        $this->setCurrentHospital(Hospital::factory()->create());
        Http::preventStrayRequests();
        Http::fake([
            'https://api.africastalking.com/version1/messaging' => Http::response('ok', 200),
        ]);

        $this->assertTrue(app(NotificationService::class)->sendSms('+254712345678', 'Test message'));

        Http::assertSent(fn ($request): bool => $request['username'] === 'global-sms'
            && $request->hasHeader('apiKey', 'global-sms-key'));
    }

    public function test_mail_uses_hospital_sender_when_configured(): void
    {
        $hospital = Hospital::factory()->create();
        $this->setCurrentHospital($hospital);
        app(HospitalSettings::class)->for($hospital)->setCredential('email', [
            'mailer' => 'log',
            'from_address' => 'appointments@hospital-a.test',
            'from_name' => 'Hospital A',
        ]);
        Mail::fake();

        app(HospitalMailService::class)->send(
            'patient@example.test',
            new MarketingDemoRequest([
                'name' => 'Patient',
                'email' => 'patient@example.test',
                'organization' => 'Hospital A',
            ]),
        );

        Mail::assertSent(MarketingDemoRequest::class, fn (MarketingDemoRequest $mailable): bool => $mailable->from[0]['address'] === 'appointments@hospital-a.test'
            && $mailable->from[0]['name'] === 'Hospital A');
    }

    public function test_mail_falls_back_to_env_when_hospital_has_no_credentials(): void
    {
        config([
            'mail.from.address' => 'global-mail@example.test',
            'mail.from.name' => 'Global Mail',
        ]);
        $this->setCurrentHospital(Hospital::factory()->create());
        Mail::fake();

        app(HospitalMailService::class)->send(
            'patient@example.test',
            new MarketingDemoRequest([
                'name' => 'Patient',
                'email' => 'patient@example.test',
                'organization' => 'Global Hospital',
            ]),
        );

        Mail::assertSent(MarketingDemoRequest::class, fn (MarketingDemoRequest $mailable): bool => $mailable->from[0]['address'] === 'global-mail@example.test'
            && $mailable->from[0]['name'] === 'Global Mail');
    }

    public function test_two_hospitals_use_their_own_credentials(): void
    {
        $hospitalA = Hospital::factory()->create();
        $hospitalB = Hospital::factory()->create();
        app(HospitalSettings::class)->for($hospitalA)->setCredential('mpesa', [
            'consumer_key' => 'hospital-a-key',
            'consumer_secret' => 'hospital-a-secret',
            'shortcode' => '123456',
            'passkey' => 'hospital-a-passkey',
        ]);
        app(HospitalSettings::class)->for($hospitalB)->setCredential('mpesa', [
            'consumer_key' => 'hospital-b-key',
            'consumer_secret' => 'hospital-b-secret',
            'shortcode' => '654321',
            'passkey' => 'hospital-b-passkey',
        ]);
        $this->fakeMpesaOAuth();

        $this->setCurrentHospital($hospitalA);
        app(MpesaService::class)->getAccessToken();
        $this->setCurrentHospital($hospitalB);
        app(MpesaService::class)->getAccessToken();

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->hasHeader(
            'Authorization',
            'Basic '.base64_encode('hospital-a-key:hospital-a-secret'),
        ));
        Http::assertSent(fn ($request): bool => $request->hasHeader(
            'Authorization',
            'Basic '.base64_encode('hospital-b-key:hospital-b-secret'),
        ));
    }

    public function test_tenant_without_mpesa_credentials_fails_closed(): void
    {
        $hospital = Hospital::factory()->create();
        $this->setCurrentHospital($hospital);
        config([
            'integrations.allow_global_fallback' => false,
            'mpesa.consumer_key' => 'global-test-key',
            'mpesa.consumer_secret' => 'global-test-secret',
            'mpesa.shortcode' => '174379',
            'mpesa.passkey' => 'global-test-passkey',
        ]);
        Http::preventStrayRequests();

        try {
            app(MpesaService::class)->getAccessToken();
            $this->fail('Missing tenant M-Pesa credentials should fail closed.');
        } catch (MissingProviderCredentialsException $exception) {
            $this->assertSame('M-Pesa', $exception->provider);
            $this->assertSame($hospital->slug, $exception->hospitalSlug);
            $this->assertStringContainsString('An admin must enter credentials', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_tenant_without_whatsapp_credentials_fails_closed(): void
    {
        $hospital = Hospital::factory()->create();
        $this->setCurrentHospital($hospital);
        config([
            'integrations.allow_global_fallback' => false,
            'whatsapp.phone_number_id' => 'global-phone-id',
            'whatsapp.access_token' => 'global-access-token',
        ]);
        Http::preventStrayRequests();

        try {
            app(WhatsAppService::class)->sendTemplate('254712345678', 'appointment_update', []);
            $this->fail('Missing tenant WhatsApp credentials should fail closed.');
        } catch (MissingProviderCredentialsException $exception) {
            $this->assertSame('WhatsApp', $exception->provider);
            $this->assertSame($hospital->slug, $exception->hospitalSlug);
        }

        Http::assertNothingSent();
    }

    public function test_tenant_without_whatsapp_webhook_credentials_returns_service_unavailable(): void
    {
        $hospital = Hospital::factory()->create();
        $this->setCurrentHospital($hospital);
        config([
            'integrations.allow_global_fallback' => false,
            'whatsapp.verify_token' => 'global-verify-token',
        ]);

        $response = app(WhatsAppWebhookController::class)->verify(
            Request::create('/webhooks/whatsapp?hub.verify_token=global-verify-token&hub.mode=subscribe'),
        );

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString($hospital->slug, $response->getContent());
        $this->assertStringContainsString('WhatsApp is not configured', $response->getContent());
    }

    public function test_whatsapp_webhook_returns_503_when_tenant_credentials_cannot_be_decrypted(): void
    {
        $hospital = Hospital::factory()->create();
        $this->setCurrentHospital($hospital);
        app(HospitalSettings::class)->for($hospital)->setCredential('whatsapp', [
            'verify_token' => 'tenant-verify-token',
        ]);
        DB::table('hospital_integration_credentials')
            ->where('hospital_id', $hospital->id)
            ->where('provider', 'whatsapp')
            ->update(['credentials' => 'invalid-encrypted-payload']);
        config([
            'integrations.allow_global_fallback' => true,
            'whatsapp.verify_token' => 'global-verify-token',
        ]);

        $response = app(WhatsAppWebhookController::class)->verify(
            Request::create('/webhooks/whatsapp?hub.verify_token=global-verify-token&hub.mode=subscribe'),
        );

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString('WhatsApp is not configured', $response->getContent());
        $this->assertStringNotContainsString('global-verify-token', $response->getContent());
    }

    public function test_tenant_without_sms_credentials_fails_closed(): void
    {
        $hospital = Hospital::factory()->create();
        $this->setCurrentHospital($hospital);
        config([
            'integrations.allow_global_fallback' => false,
            'services.sms_provider' => 'africastalking',
            'services.africastalking.username' => 'global-sms',
            'services.africastalking.api_key' => 'global-sms-key',
        ]);
        Http::preventStrayRequests();

        try {
            app(NotificationService::class)->sendSms('+254712345678', 'Test message');
            $this->fail('Missing tenant SMS credentials should fail closed.');
        } catch (MissingProviderCredentialsException $exception) {
            $this->assertSame('SMS', $exception->provider);
            $this->assertSame($hospital->slug, $exception->hospitalSlug);
        }

        Http::assertNothingSent();
    }

    public function test_global_fallback_only_when_explicitly_enabled(): void
    {
        $hospital = Hospital::factory()->create();
        $this->setCurrentHospital($hospital);
        config([
            'integrations.allow_global_fallback' => false,
            'mpesa.consumer_key' => 'global-test-key',
            'mpesa.consumer_secret' => 'global-test-secret',
            'mpesa.shortcode' => '174379',
            'mpesa.passkey' => 'global-test-passkey',
        ]);
        Http::preventStrayRequests();

        $missingCredentialsException = null;
        try {
            app(MpesaService::class)->getAccessToken();
        } catch (MissingProviderCredentialsException $exception) {
            $missingCredentialsException = $exception;
        }

        $this->assertInstanceOf(MissingProviderCredentialsException::class, $missingCredentialsException);
        $this->assertSame($hospital->slug, $missingCredentialsException->hospitalSlug);
        Http::assertNothingSent();
        config(['integrations.allow_global_fallback' => true]);
        $this->fakeMpesaOAuth();

        app(MpesaService::class)->getAccessToken();

        Http::assertSent(fn ($request): bool => $request->hasHeader(
            'Authorization',
            'Basic '.base64_encode('global-test-key:global-test-secret'),
        ));
    }

    public function test_health_check_reports_per_hospital_credential_status(): void
    {
        $configuredHospital = Hospital::factory()->create(['is_active' => true]);
        $missingHospital = Hospital::factory()->create(['is_active' => true]);
        app(HospitalSettings::class)->for($configuredHospital)->setCredential('mpesa', [
            'consumer_key' => 'tenant-key',
            'consumer_secret' => 'tenant-secret',
            'shortcode' => '123456',
            'passkey' => 'tenant-passkey',
        ]);
        config([
            'app.env' => 'production',
            'integrations.allow_global_fallback' => false,
            'mpesa.consumer_key' => 'global-test-key',
            'mpesa.consumer_secret' => 'global-test-secret',
            'mpesa.passkey' => 'global-test-passkey',
            'mpesa.shortcode' => '174379',
            'mpesa.callback_url' => 'https://example.test/api/mpesa/callback',
        ]);
        $this->app->detectEnvironment(static fn (): string => 'production');
        $this->recordHealthCheckHeartbeats();

        $exitCode = Artisan::call('mpesa:health-check');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('M-Pesa credentials '.$configuredHospital->slug, $output);
        $this->assertStringContainsString('M-Pesa credentials '.$missingHospital->slug, $output);
        $this->assertStringContainsString('missing tenant credentials', $output);
        $this->assertStringContainsString('MISSING', $output);
        $this->assertStringNotContainsString('tenant-secret', $output);
    }

    public function test_service_uses_env_when_no_hospital_bound(): void
    {
        config([
            'mpesa.consumer_key' => 'global-unbound-key',
            'mpesa.consumer_secret' => 'global-unbound-secret',
        ]);
        app()->forgetInstance('currentHospital');
        $this->fakeMpesaOAuth();

        app(MpesaService::class)->getAccessToken();

        Http::assertSent(fn ($request): bool => $request->hasHeader(
            'Authorization',
            'Basic '.base64_encode('global-unbound-key:global-unbound-secret'),
        ));
    }

    private function fakeMpesaOAuth(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'test-oauth-token',
            ]),
        ]);
    }

    private function setCurrentHospital(Hospital $hospital): void
    {
        app()->instance('currentHospital', $hospital);
    }

    private function recordHealthCheckHeartbeats(): void
    {
        Cache::put(ReconcileMpesaPayments::HEARTBEAT_CACHE_KEY, now()->timestamp, now()->addMinutes(15));
        Cache::put(MpesaHealthCheck::SCHEDULER_HEARTBEAT_CACHE_KEY, now()->timestamp, 600);

        foreach (MpesaHealthCheck::SCHEDULED_TASK_HEARTBEATS as $command => $ttlMinutes) {
            Cache::put(
                MpesaHealthCheck::schedulerHeartbeatCacheKey($command),
                now()->timestamp,
                $ttlMinutes * 60,
            );
        }
    }
}
