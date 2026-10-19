<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\User;
use App\Services\HospitalMailService;
use App\Services\HospitalManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class SuperAdminLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_super_admin_session_is_restored_without_the_tenant_scope_on_platform_routes(): void
    {
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'is_super_admin' => true,
        ]);

        Auth::forgetGuards();

        $this->withSession([
            Auth::guard('web')->getName() => $superAdmin->getAuthIdentifier(),
        ])->get(route('superadmin.dashboard'))
            ->assertOk();
    }

    public function test_super_admin_can_view_hospitals_edit_details_and_toggle_subscription_status(): void
    {
        $hospital = Hospital::query()->create([
            'name' => 'Lifecycle Hospital',
            'slug' => 'lifecycle-hospital',
            'subscription_plan' => 'professional',
            'subscription_status' => 'active',
            'is_active' => true,
        ]);
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'is_super_admin' => true,
        ]);

        $this->actingAs($superAdmin)
            ->get(route('superadmin.dashboard'))
            ->assertOk()
            ->assertSee($hospital->name);

        $this->actingAs($superAdmin)
            ->get(route('superadmin.hospitals.create'))
            ->assertOk();

        $this->actingAs($superAdmin)
            ->get(route('superadmin.hospitals.edit', $hospital))
            ->assertOk()
            ->assertSee($hospital->name);

        $this->actingAs($superAdmin)
            ->post(route('superadmin.hospitals.suspend', $hospital))
            ->assertRedirect();

        $this->assertSame('suspended', $hospital->fresh()->subscription_status);

        $this->actingAs($superAdmin)
            ->post(route('superadmin.hospitals.activate', $hospital))
            ->assertRedirect();

        $this->assertSame('active', $hospital->fresh()->subscription_status);

        $this->actingAs($superAdmin)
            ->post(route('superadmin.hospitals.impersonate', $hospital))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('impersonating_hospital_id', $hospital->id);
    }

    public function test_hospital_created_in_platform_admin_ui_renders_its_own_public_surfaces(): void
    {
        Mail::fake();
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'is_super_admin' => true,
        ]);

        $this->actingAs($superAdmin)
            ->post(route('superadmin.hospitals.store'), [
                'name' => 'Test Clinic',
                'slug' => 'test-clinic',
                'address' => '42 Clinic Road',
                'phone' => '0700111222',
                'emergency_phone' => '0700333444',
                'email' => 'hello@test-clinic.example',
                'whatsapp_number' => '254700111222',
                'primary_color' => '#123456',
                'secondary_color' => '#654321',
                'deposit_amount' => 0,
                'slot_duration_minutes' => 30,
                'no_show_grace_minutes' => 30,
                'hours_emergency' => '24 hours',
                'hours_outpatient' => '8:00 AM to 4:00 PM',
                'default_language' => 'en',
                'supported_languages' => ['en', 'sw'],
                'subscription_plan' => 'professional',
                'trial_days' => 14,
                'admin_name' => 'Test Clinic Admin',
                'admin_email' => 'admin@test-clinic.example',
                'admin_password' => 'SecureStart!2026',
                'admin_password_confirmation' => 'SecureStart!2026',
            ]);

        $hospital = Hospital::query()->where('slug', 'test-clinic')->firstOrFail();
        $this->assertSame('Test Clinic', $hospital->name);
        $this->assertSame(
            $hospital->id,
            User::withoutGlobalScopes()->where('email', 'admin@test-clinic.example')->value('hospital_id'),
        );

        $this->post(route('logout'));

        $this->get(route('tenant.home', $hospital->slug))
            ->assertOk()
            ->assertSee('Test Clinic')
            ->assertSee('42 Clinic Road')
            ->assertSee('0700111222')
            ->assertSee('hello@test-clinic.example')
            ->assertSee('#123456', false)
            ->assertSee('#654321', false)
            ->assertSee('24 hours');

        $this->get(route('tenant.chat.page', $hospital->slug))
            ->assertOk()
            ->assertSee('Test Clinic')
            ->assertSee('0700333444')
            ->assertSee('data-chat-url="'.route('tenant.chat.post', $hospital->slug).'"', false);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('MediDesk AI')
            ->assertDontSee('Test Clinic')
            ->assertDontSee('42 Clinic Road')
            ->assertDontSee('0700111222');
    }

    public function test_super_admin_creation_preserves_the_tenant_and_reports_invitation_delivery_failure(): void
    {
        $pearl = Hospital::query()->where('slug', 'pearl')->firstOrFail();
        $superAdmin = User::factory()->create([
            'hospital_id' => $pearl->id,
            'role' => 'super_admin',
            'is_super_admin' => true,
        ]);
        $this->mock(HospitalMailService::class)
            ->shouldReceive('send')
            ->once()
            ->andThrow(new TransportException('Mail transport unavailable.'));

        $result = app(HospitalManagementService::class)->create([
            'name' => 'Delivery Failure Hospital',
            'slug' => 'delivery-failure-hospital',
            'deposit_amount' => 500,
            'slot_duration_minutes' => 30,
            'no_show_grace_minutes' => 30,
            'hours_emergency' => '24/7',
            'hours_outpatient' => '9:00 AM - 5:00 PM',
            'default_language' => 'en',
            'supported_languages' => ['en'],
            'subscription_plan' => 'starter',
            'trial_days' => 14,
            'admin_email' => 'admin@delivery-failure.example',
        ], $superAdmin->id);

        $hospital = $result['hospital'];

        $this->assertFalse($result['invitation_sent']);
        $this->assertSame(
            $hospital->id,
            User::withoutGlobalScopes()->where('email', 'admin@delivery-failure.example')->value('hospital_id'),
        );
        $this->assertDatabaseMissing('invites', [
            'email' => 'admin@delivery-failure.example',
        ]);
    }
}
