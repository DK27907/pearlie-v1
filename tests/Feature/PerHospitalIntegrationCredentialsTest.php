<?php

namespace Tests\Feature;

use App\Mail\MarketingDemoRequest;
use App\Models\Hospital;
use App\Services\HospitalMailService;
use App\Services\HospitalSettings;
use App\Services\MpesaService;
use App\Services\NotificationService;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ]);
        app(HospitalSettings::class)->for($hospitalB)->setCredential('mpesa', [
            'consumer_key' => 'hospital-b-key',
            'consumer_secret' => 'hospital-b-secret',
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
}
