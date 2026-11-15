<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\HospitalIntegrationCredential;
use App\Models\User;
use App\Services\AppointmentFollowUpService;
use App\Services\HospitalSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppointmentFollowUpTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_completed_appointment_gets_a_logged_follow_up_and_feedback_is_saved_from_chat(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
        $hospital = Hospital::factory()->create([
            'slug' => 'feedback-hospital',
            'is_active' => true,
            'subscription_status' => 'active',
            'settings' => ['google_review_url' => 'https://reviews.example.test/hospital'],
        ]);
        app()->instance('currentHospital', $hospital);
        (new HospitalSettings)->for($hospital)->setCredential(
            HospitalIntegrationCredential::PROVIDER_WHATSAPP,
            [
                'phone_number_id' => 'test-phone-number-id',
                'access_token' => 'test-access-token',
                'api_version' => 'v20.0',
            ],
        );
        $doctor = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_doctor' => true,
            'name' => 'Amina Njeri',
        ]);
        $patientIdentity = 'stable-web-chat-patient';
        $appointment = AppointmentRequest::factory()->create([
            'hospital_id' => $hospital->id,
            'doctor_id' => $doctor->id,
            'name' => 'Test Patient',
            'phone' => '254700000000',
            'status' => AppointmentRequest::STATUS_COMPLETED,
            'created_at' => now()->subDays(2),
        ]);
        $appointment->forceFill([
            'feedback_identity_hash' => hash('sha256', implode(':', [
                $hospital->id,
                'web',
                $patientIdentity,
            ])),
        ])->save();
        DB::table('appointment_requests')
            ->where('id', $appointment->id)
            ->update(['updated_at' => now()->subHours(25)]);
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.follow-up']],
            ], 200),
        ]);

        $this->artisan('appointments:send-follow-ups')
            ->assertExitCode(0);

        $appointment->refresh();
        $this->assertNotNull($appointment->feedback_requested_at);
        $this->assertDatabaseHas('notification_logs', [
            'hospital_id' => $hospital->id,
            'appointment_request_id' => $appointment->id,
            'channel' => 'whatsapp',
            'provider_message_id' => 'wamid.follow-up',
            'status' => 'sent',
        ]);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'graph.facebook.com')
            && str_contains($request['text']['body'], 'Test Patient')
            && str_contains($request['text']['body'], 'Amina Njeri'));

        $patientCookieName = 'tenant_chat_patient_id_'.$hospital->id;
        $scoreResponse = $this->withSession([
            'tenant_chat_session_id_'.$hospital->id => 'feedback-score-session',
            $patientCookieName => $patientIdentity,
        ])->postJson(route('tenant.chat.post', ['slug' => $hospital->slug]), [
            'message' => '5',
        ]);
        $scoreResponse->assertOk()
            ->assertJsonPath(
                'response',
                'Thank you for your feedback! If you have any comments, just type them here. If you had a great experience, please leave us a review on Google: https://reviews.example.test/hospital',
            );

        $this->assertSame(5, $appointment->fresh()->feedback_score);

        $patientCookie = $scoreResponse->getCookie($patientCookieName, false);
        $this->assertNotNull($patientCookie);
        $this->withCookie($patientCookieName, $patientCookie->getValue())
            ->withSession([
                'tenant_chat_session_id_'.$hospital->id => 'new-feedback-comment-session',
            ])
            ->postJson(route('tenant.chat.post', ['slug' => $hospital->slug]), [
                'message' => 'Great service',
            ])->assertOk()
            ->assertJsonPath('response', 'We appreciate your feedback. Have a great day!');

        $this->assertSame('Great service', $appointment->fresh()->feedback_comment);
    }

    public function test_failed_follow_up_delivery_remains_retryable(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
        $hospital = Hospital::factory()->create([
            'is_active' => true,
            'subscription_status' => 'active',
        ]);
        app()->instance('currentHospital', $hospital);
        $appointment = AppointmentRequest::factory()->create([
            'hospital_id' => $hospital->id,
            'status' => AppointmentRequest::STATUS_COMPLETED,
        ]);
        DB::table('appointment_requests')
            ->where('id', $appointment->id)
            ->update(['updated_at' => now()->subHours(25)]);
        Http::preventStrayRequests();

        $this->artisan('appointments:send-follow-ups')
            ->assertFailed();

        $this->assertNull($appointment->fresh()->feedback_requested_at);
        $this->assertDatabaseHas('notification_logs', [
            'hospital_id' => $hospital->id,
            'appointment_request_id' => $appointment->id,
            'channel' => 'sms',
            'status' => 'not_configured',
            'failure_code' => 'credentials_missing',
        ]);
    }

    public function test_sms_is_used_as_a_fallback_when_whatsapp_is_not_configured(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
        $hospital = Hospital::factory()->create([
            'is_active' => true,
            'subscription_status' => 'active',
        ]);
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
            'status' => AppointmentRequest::STATUS_COMPLETED,
        ]);
        DB::table('appointment_requests')
            ->where('id', $appointment->id)
            ->update(['updated_at' => now()->subHours(25)]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.twilio.com/2010-04-01/Accounts/*/Messages.json' => Http::response([
                'sid' => 'SM-follow-up',
            ], 201),
        ]);

        $this->artisan('appointments:send-follow-ups')
            ->assertSuccessful();

        $this->assertNotNull($appointment->fresh()->feedback_requested_at);
        $this->assertDatabaseHas('notification_logs', [
            'hospital_id' => $hospital->id,
            'appointment_request_id' => $appointment->id,
            'channel' => 'sms',
            'provider' => 'twilio',
            'provider_message_id' => 'SM-follow-up',
            'status' => 'sent',
        ]);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.twilio.com')
            && $request->method() === 'POST');
    }

    public function test_hospital_admin_can_save_a_google_review_url_for_feedback_replies(): void
    {
        $hospital = Hospital::factory()->create([
            'settings' => ['existing_setting' => 'preserve'],
        ]);
        $admin = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_admin' => true,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.integration-settings.general.update'), [
                'deposit_amount' => 500,
                'slot_duration_minutes' => 30,
                'google_review_url' => 'https://reviews.example.test/hospital',
            ])
            ->assertRedirect(route('admin.integration-settings.edit', ['tab' => 'general']));

        $settings = $hospital->fresh()->settings;
        $this->assertSame('preserve', $settings['existing_setting']);
        $this->assertSame('https://reviews.example.test/hospital', $settings['google_review_url']);
    }

    public function test_feedback_reply_cannot_update_another_hospital_appointment(): void
    {
        $hospital = Hospital::factory()->create();
        $otherHospital = Hospital::factory()->create();
        $patientIdentity = 'same-profile-identity';
        $identityHash = hash('sha256', implode(':', [
            $hospital->id,
            'web',
            $patientIdentity,
        ]));
        app()->instance('currentHospital', $otherHospital);
        $otherAppointment = AppointmentRequest::factory()->create([
            'hospital_id' => $otherHospital->id,
            'status' => AppointmentRequest::STATUS_COMPLETED,
            'feedback_requested_at' => now()->subHour(),
        ]);
        $otherAppointment->forceFill(['feedback_identity_hash' => $identityHash])->save();
        app()->instance('currentHospital', $hospital);

        $response = app(AppointmentFollowUpService::class)->captureReply(
            $hospital,
            'web',
            $patientIdentity,
            '5',
        );

        $this->assertNull($response);
        $this->assertNull($otherAppointment->fresh()->feedback_score);
    }
}
