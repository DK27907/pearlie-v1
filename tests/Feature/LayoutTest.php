<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hospital = Hospital::query()->where('slug', 'pearl')->firstOrFail();
        app()->instance('currentHospital', $this->hospital);
    }

    public function test_header_appears_on_home_page(): void
    {
        $this->get(route('tenant.home', 'pearl'))
            ->assertOk()
            ->assertSee($this->hospital->name)
            ->assertSee('Talk to MediDesk');
    }

    public function test_marketing_home_link_preserves_its_visible_accessible_name(): void
    {
        app()->forgetInstance('currentHospital');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee("Your Hospital's 24/7", false)
            ->assertDontSee('aria-label="MediDesk Assistant home"', false);
    }

    public function test_chat_page_has_a_main_landmark_and_level_one_heading(): void
    {
        $this->get(route('tenant.chat.page', 'pearl'))
            ->assertOk()
            ->assertSee('<main class="chat-page">', false)
            ->assertSee('<h1 class="title">', false);
    }

    public function test_auth_pages_have_level_one_headings_and_accessible_contrast_colors(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('<h1 class="text-2xl font-bold text-slate-900">', false);

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('<h1 class="mb-6 text-2xl font-bold text-slate-900">Create an account</h1>', false);

        $this->get(route('password.reset', 'audit-token'))
            ->assertOk()
            ->assertSee('<h1 class="mb-6 text-2xl font-bold text-slate-900">Reset your password</h1>', false);

        User::factory()->create([
            'hospital_id' => $this->hospital->id,
            'is_doctor' => true,
            'role' => 'doctor',
            'is_active' => true,
        ]);

        $this->get(route('tenant.home', 'pearl'))
            ->assertOk()
            ->assertSee('bg-sky-700', false);

        $this->get(route('tenant.chat.page', 'pearl'))
            ->assertOk()
            ->assertSee('color: #595959', false)
            ->assertSee('text-decoration: underline', false);

        app()->forgetInstance('currentHospital');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('text-slate-600">Includes hosting', false)
            ->assertSee('text-slate-400 sm:flex-row', false);
    }

    public function test_tenant_footer_displays_hospital_contact_and_platform_branding(): void
    {
        $this->get(route('tenant.home', 'pearl'))
            ->assertOk()
            ->assertSee($this->hospital->address)
            ->assertSee($this->hospital->phone)
            ->assertSee('Powered by MediDesk Assistant');
    }

    public function test_tenant_footer_does_not_fall_back_to_another_hospitals_contacts(): void
    {
        config([
            'pearlie.hospital.appointment_phone' => '0700111222',
            'pearlie.hospital.emergency_phone' => '0700333444',
            'pearlie.hospital.email' => 'pearl-config@example.test',
            'pearlie.hospital.website' => 'https://pearl-config.example.test',
        ]);

        $tenant = Hospital::factory()->create([
            'name' => 'Verification Medical Center',
            'slug' => 'verify-medical',
            'phone' => null,
            'emergency_phone' => null,
            'email' => null,
            'website' => null,
        ]);
        app()->instance('currentHospital', $tenant);

        $footer = view('components.site-footer')->render();

        $this->assertStringContainsString($tenant->name, $footer);
        $this->assertStringNotContainsString('0700111222', $footer);
        $this->assertStringNotContainsString('0700333444', $footer);
        $this->assertStringNotContainsString('pearl-config@example.test', $footer);
        $this->assertStringNotContainsString('pearl-config.example.test', $footer);
    }

    public function test_header_shows_admin_link_for_admin(): void
    {
        $admin = User::factory()->create([
            'hospital_id' => $this->hospital->id,
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Admin Dashboard');
    }

    public function test_header_shows_doctor_link_for_doctor(): void
    {
        $doctor = User::factory()->create([
            'hospital_id' => $this->hospital->id,
            'is_doctor' => true,
            'role' => 'doctor',
        ]);

        $this->actingAs($doctor)
            ->get(route('doctor.dashboard'))
            ->assertOk()
            ->assertSee('My Dashboard');
    }

    public function test_profile_modal_uses_the_csp_compatible_alpine_component(): void
    {
        $doctor = User::factory()->create([
            'hospital_id' => $this->hospital->id,
            'is_doctor' => true,
            'role' => 'doctor',
        ]);

        $this->actingAs($doctor)
            ->get(route('doctor.profile'))
            ->assertOk()
            ->assertSee('data-modal-name="confirm-user-deletion"', false)
            ->assertSee('data-open-modal="confirm-user-deletion"', false);
    }

    public function test_header_shows_login_for_guest(): void
    {
        $this->get(route('tenant.home', 'pearl'))
            ->assertOk()
            ->assertSee('Talk to MediDesk')
            ->assertSee('Book Appointment');
    }

    public function test_guest_layout_renders_auth_form_once(): void
    {
        $response = $this->get(route('login'))->assertOk();

        $this->assertSame(1, substr_count($response->getContent(), 'name="email"'));
    }

    public function test_auth_pages_use_platform_branding_and_keep_the_return_link(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee(config('app.platform_name'))
            ->assertDontSee(config('pearlie.hospital.name'))
            ->assertDontSee(config('pearlie.hospital.emergency_phone'))
            ->assertSee('Return to chat');
    }
}
