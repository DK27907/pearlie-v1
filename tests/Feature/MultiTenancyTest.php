<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\User;
use App\Mail\HospitalAdminInviteMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MultiTenancyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_hospital_admin_only_sees_appointments_from_their_hospital(): void
    {
        $pearl = $this->createHospital('pearl-a');
        $karibu = $this->createHospital('karibu-a');

        app()->instance('currentHospital', $pearl);
        $admin = User::factory()->create([
            'hospital_id' => $pearl->id,
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
        AppointmentRequest::query()->create([
            'name' => 'Pearl Patient',
            'phone' => '0700000001',
            'session_id' => 'pearl-patient-session',
            'preferred_date' => today(),
            'reason' => 'Check-up',
            'raw_message' => 'Book a check-up',
            'status' => 'pending',
        ]);

        app()->instance('currentHospital', $karibu);
        AppointmentRequest::query()->create([
            'name' => 'Karibu Patient',
            'phone' => '0700000002',
            'session_id' => 'karibu-patient-session',
            'preferred_date' => today(),
            'reason' => 'Consultation',
            'raw_message' => 'Book a consultation',
            'status' => 'pending',
        ]);
        app()->forgetInstance('currentHospital');

        $this->actingAs($admin)
            ->get(route('admin.appointments.index'))
            ->assertOk()
            ->assertSee('Pearl Patient')
            ->assertDontSee('Karibu Patient');
    }

    public function test_hospital_admin_cannot_view_another_hospitals_appointment(): void
    {
        $pearl = $this->createHospital('pearl-b');
        $karibu = $this->createHospital('karibu-b');

        app()->instance('currentHospital', $pearl);
        $admin = User::factory()->create([
            'hospital_id' => $pearl->id,
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
        app()->instance('currentHospital', $karibu);
        $appointment = AppointmentRequest::query()->create([
            'name' => 'Private Karibu Patient',
            'phone' => '0700000003',
            'session_id' => 'private-karibu-session',
            'preferred_date' => today(),
            'reason' => 'Private consultation',
            'raw_message' => 'Private booking message',
            'status' => 'pending',
        ]);
        app()->forgetInstance('currentHospital');

        $this->actingAs($admin)
            ->get(route('admin.appointments.show', $appointment->id))
            ->assertNotFound();
    }

    public function test_platform_admin_must_impersonate_a_hospital_before_using_hospital_admin_routes(): void
    {
        $hospital = $this->createHospital('platform-test');
        $pearl = Hospital::query()->where('slug', 'pearl')->firstOrFail();
        $platformAdmin = User::factory()->create([
            'hospital_id' => $pearl->id,
            'role' => 'super_admin',
            'is_super_admin' => true,
        ]);

        $this->actingAs($platformAdmin)
            ->get(route('admin.dashboard'))
            ->assertForbidden();

        $this->post(route('superadmin.hospitals.impersonate', $hospital))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('impersonating_hospital_id', $hospital->id);

        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_starter_plan_cannot_use_doctor_management_or_ai_booking(): void
    {
        Http::preventStrayRequests();
        $hospital = $this->createHospital('starter-test', 'starter');
        app()->instance('currentHospital', $hospital);
        $doctor = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_doctor' => true,
            'role' => 'doctor',
        ]);
        app()->forgetInstance('currentHospital');

        $this->actingAs($doctor)
            ->get(route('doctor.dashboard'))
            ->assertForbidden();

        $this->post(route('pearlie.chat'), ['message' => 'I want to book an appointment'])
            ->assertOk()
            ->assertJsonPath('source', 'subscription_feature_unavailable');

        Http::preventStrayRequests(false);
    }

    public function test_tenant_path_selects_the_public_assistants_hospital(): void
    {
        $hospital = $this->createHospital('path-tenant');

        $this->get(route('tenant.home', 'path-tenant'))
            ->assertOk()
            ->assertSee($hospital->name);
    }

    public function test_tenant_chat_alias_selects_hospital_and_uses_its_chat_endpoint(): void
    {
        $this->createHospital('chat-alias');

        $this->get(route('tenant.chat.page', 'chat-alias'))
            ->assertOk()
            ->assertSee(route('tenant.chat.short', 'chat-alias'), false);
    }

    public function test_tenant_specific_whatsapp_webhook_resolves_the_requested_hospital(): void
    {
        $hospital = $this->createHospital('webhook-alias');
        $hospital->update(['whatsapp_verify_token' => 'tenant-verify-token']);

        $this->get(route('tenant.whatsapp.webhook.verify', [
            'hospitalSlug' => $hospital->slug,
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'tenant-verify-token',
            'hub.challenge' => 'tenant-challenge',
        ]))
            ->assertOk()
            ->assertSeeText('tenant-challenge');
    }

    public function test_tenant_specific_mpesa_callback_is_reachable_without_authentication(): void
    {
        $this->createHospital('callback-alias');

        $this->postJson(route('tenant.mpesa.callback', 'callback-alias'), [])
            ->assertOk()
            ->assertJsonPath('ResultCode', 0)
            ->assertJsonPath('ResultDesc', 'Accepted');
    }

    public function test_tenant_models_are_assigned_and_can_be_queried_without_the_hospital_scope(): void
    {
        $pearl = $this->createHospital('assignment-pearl');
        $demo = $this->createHospital('assignment-demo');

        app()->instance('currentHospital', $pearl);
        $pearlAppointment = AppointmentRequest::query()->create([
            'name' => 'Pearl Patient',
            'phone' => '0700000001',
            'session_id' => 'assignment-pearl-session',
            'preferred_date' => today(),
            'reason' => 'Check-up',
            'raw_message' => 'Book a check-up',
            'status' => 'pending',
        ]);

        app()->instance('currentHospital', $demo);
        $demoAppointment = AppointmentRequest::query()->create([
            'name' => 'Demo Patient',
            'phone' => '0700000002',
            'session_id' => 'assignment-demo-session',
            'preferred_date' => today(),
            'reason' => 'Consultation',
            'raw_message' => 'Book a consultation',
            'status' => 'pending',
        ]);
        app()->forgetInstance('currentHospital');

        $this->assertSame($pearl->id, $pearlAppointment->hospital_id);
        $this->assertSame($demo->id, $demoAppointment->hospital_id);
        $this->assertEqualsCanonicalizing(
            [$pearlAppointment->id, $demoAppointment->id],
            AppointmentRequest::withoutGlobalScope('hospital')->pluck('id')->all(),
        );
    }

    public function test_doctor_dashboard_does_not_show_appointments_from_another_hospital(): void
    {
        $pearl = $this->createHospital('doctor-pearl');
        $demo = $this->createHospital('doctor-demo');

        app()->instance('currentHospital', $pearl);
        $doctor = User::factory()->create([
            'hospital_id' => $pearl->id,
            'is_doctor' => true,
            'role' => 'doctor',
        ]);
        AppointmentRequest::query()->create([
            'doctor_id' => $doctor->id,
            'name' => 'Pearl Patient',
            'phone' => '0700000001',
            'session_id' => 'doctor-pearl-session',
            'preferred_date' => today(),
            'reason' => 'Pearl appointment',
            'raw_message' => 'Pearl booking',
            'status' => 'pending',
        ]);

        app()->instance('currentHospital', $demo);
        AppointmentRequest::query()->create([
            'name' => 'Private Demo Patient',
            'phone' => '0700000002',
            'session_id' => 'doctor-demo-session',
            'preferred_date' => today(),
            'reason' => 'Private appointment',
            'raw_message' => 'Private demo booking',
            'status' => 'pending',
        ]);
        app()->forgetInstance('currentHospital');

        $this->actingAs($doctor)
            ->get(route('doctor.appointments'))
            ->assertOk()
            ->assertSee('Pearl Patient')
            ->assertDontSee('Private Demo Patient');
    }

    public function test_super_admin_dashboard_lists_all_hospitals(): void
    {
        $this->createHospital('listed-hospital');
        $platformAdmin = User::factory()->create([
            'role' => 'super_admin',
            'is_super_admin' => true,
        ]);

        $this->actingAs($platformAdmin)
            ->get(route('superadmin.dashboard'))
            ->assertOk()
            ->assertSee('Listed Hospital');
    }

    public function test_hospital_admin_can_view_the_onboarding_checklist(): void
    {
        $hospital = $this->createHospital('onboarding-hospital');
        app()->instance('currentHospital', $hospital);
        $admin = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
        app()->forgetInstance('currentHospital');

        $this->actingAs($admin)
            ->get(route('hospital.onboarding'))
            ->assertOk()
            ->assertSee('Onboarding checklist')
            ->assertSee('Needs attention');
    }

    public function test_suspended_hospital_is_blocked_from_tenant_routes(): void
    {
        $hospital = $this->createHospital('suspended-hospital');
        $hospital->update(['subscription_status' => 'suspended']);
        app()->instance('currentHospital', $hospital);
        $admin = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
        app()->forgetInstance('currentHospital');

        $this->actingAs($admin)
            ->get(route('tenant.home', 'suspended-hospital'))
            ->assertForbidden();
    }

    public function test_super_admin_can_create_hospital_and_its_invited_admin_can_complete_setup(): void
    {
        Mail::fake();
        $pearl = Hospital::query()->where('slug', 'pearl')->firstOrFail();
        $platformAdmin = User::factory()->create([
            'hospital_id' => $pearl->id,
            'role' => 'super_admin',
            'is_super_admin' => true,
        ]);

        $response = $this->actingAs($platformAdmin)->post(route('superadmin.hospitals.store'), [
            'name' => 'Invitation Test Hospital',
            'slug' => 'invitation-test',
            'deposit_amount' => 500,
            'slot_duration_minutes' => 30,
            'no_show_grace_minutes' => 30,
            'hours_emergency' => '24/7',
            'hours_outpatient' => '9:00 AM - 5:00 PM',
            'default_language' => 'en',
            'supported_languages' => ['en'],
            'subscription_plan' => 'professional',
            'trial_days' => 14,
            'admin_email' => 'first-admin@invitation-test.co.ke',
        ]);

        $hospital = Hospital::query()->where('slug', 'invitation-test')->firstOrFail();
        $response->assertRedirect(route('superadmin.hospitals.show', $hospital));

        $sentInvite = null;
        Mail::assertSent(HospitalAdminInviteMail::class, function (HospitalAdminInviteMail $mail) use (&$sentInvite): bool {
            $sentInvite = $mail;

            return str_contains($mail->inviteUrl, '/hospital/invitation-test/invitation/');
        });
        $this->assertNotNull($sentInvite);

        $this->get($sentInvite->inviteUrl)->assertOk();
        $inviteUrl = parse_url($sentInvite->inviteUrl);
        $this->post($inviteUrl['path'].'?'.$inviteUrl['query'], [
            'name' => 'First Hospital Admin',
            'password' => 'secure-password-123',
            'password_confirmation' => 'secure-password-123',
        ])->assertRedirect(route('hospital.onboarding'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'hospital_id' => $hospital->id,
            'email' => 'first-admin@invitation-test.co.ke',
            'role' => 'hospital_admin',
        ]);
        $this->assertDatabaseHas('invites', [
            'hospital_id' => $hospital->id,
            'email' => 'first-admin@invitation-test.co.ke',
        ]);
    }

    private function createHospital(string $slug, string $plan = 'enterprise'): Hospital
    {
        return Hospital::query()->create([
            'name' => str($slug)->headline()->toString(),
            'slug' => $slug,
            'subscription_plan' => $plan,
            'subscription_status' => 'active',
            'is_active' => true,
        ]);
    }
}
