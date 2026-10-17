<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HospitalBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_admin_can_update_header_and_footer(): void
    {
        $hospital = Hospital::factory()->create();
        app()->instance('currentHospital', $hospital);
        $admin = $this->createAdmin($hospital);

        $this->actingAs($admin)
            ->get(route('admin.integration-settings.edit'))
            ->assertOk()
            ->assertSee('Branding');
        $this->actingAs($admin)
            ->put(route('admin.integration-settings.branding.update'), [
                'site_header_text' => 'Pearl Care Centre',
                'site_footer_text' => 'Care with compassion, every day.',
            ])
            ->assertRedirect(route('admin.integration-settings.edit', ['tab' => 'branding']));

        $this->assertDatabaseHas('hospitals', [
            'id' => $hospital->id,
            'site_header_text' => 'Pearl Care Centre',
            'site_footer_text' => 'Care with compassion, every day.',
        ]);
    }

    public function test_header_text_appears_on_tenant_home(): void
    {
        $hospital = Hospital::factory()->create([
            'site_header_text' => 'A Clinic',
        ]);

        $this->get(route('tenant.home', $hospital->slug))
            ->assertOk()
            ->assertSee('A Clinic');
    }

    public function test_footer_text_appears_on_tenant_home(): void
    {
        $hospital = Hospital::factory()->create([
            'site_footer_text' => 'Your care, our priority.',
        ]);

        $this->get(route('tenant.home', $hospital->slug))
            ->assertOk()
            ->assertSee('Your care, our priority.');
    }

    public function test_superadmin_pages_show_medidesk_ai_not_hospital_branding(): void
    {
        $hospital = Hospital::factory()->create([
            'site_header_text' => 'Custom Hospital X',
        ]);
        $superadmin = User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'super_admin',
            'is_admin' => false,
            'is_super_admin' => true,
            'is_doctor' => false,
        ]);

        $this->actingAs($superadmin)
            ->get(route('superadmin.hospitals.index'))
            ->assertOk()
            ->assertSee('MediDesk AI')
            ->assertDontSee('Custom Hospital X');
    }

    public function test_login_page_uses_platform_branding_with_no_tenant_context(): void
    {
        $this->get(route('login'))
            ->assertSee('MediDesk AI')
            ->assertDontSee('Pearl Hospital')
            ->assertDontSee('Vin Plaza')
            ->assertDontSee('0707799114');
    }

    public function test_register_page_uses_platform_branding_with_no_tenant_context(): void
    {
        $this->get(route('register'))
            ->assertSee('MediDesk AI')
            ->assertDontSee('Pearl Hospital')
            ->assertDontSee('Vin Plaza')
            ->assertDontSee('0707799114');
    }

    public function test_forgot_password_page_uses_platform_branding_without_tenant_context(): void
    {
        $this->get(route('password.request'))
            ->assertSee('MediDesk AI')
            ->assertDontSee('Pearl Hospital')
            ->assertDontSee('Vin Plaza')
            ->assertDontSee('0707799114');
    }

    public function test_two_hospitals_show_their_own_headers(): void
    {
        $hospitalA = Hospital::factory()->create([
            'site_header_text' => 'A Clinic',
        ]);
        $hospitalB = Hospital::factory()->create([
            'site_header_text' => 'B Clinic',
        ]);

        $this->get(route('tenant.home', $hospitalA->slug))
            ->assertOk()
            ->assertSee('A Clinic')
            ->assertDontSee('B Clinic');
        $this->get(route('tenant.home', $hospitalB->slug))
            ->assertOk()
            ->assertSee('B Clinic')
            ->assertDontSee('A Clinic');
    }

    public function test_logo_upload_saves_and_displays_the_logo(): void
    {
        Storage::fake('public');
        $hospital = Hospital::factory()->create();
        app()->instance('currentHospital', $hospital);
        $admin = $this->createAdmin($hospital);

        $this->actingAs($admin)
            ->put(route('admin.integration-settings.branding.update'), [
                'site_header_text' => 'Pearl Care Centre',
                'site_footer_text' => 'Care with compassion.',
                'site_logo' => UploadedFile::fake()->create('hospital-logo.png', 100, 'image/png'),
            ])
            ->assertRedirect(route('admin.integration-settings.edit', ['tab' => 'branding']));

        $logoPath = $hospital->fresh()->site_logo_path;
        $this->assertIsString($logoPath);
        Storage::disk('public')->assertExists($logoPath);
        $this->get(route('tenant.home', $hospital->slug))
            ->assertOk()
            ->assertSee(Storage::disk('public')->url($logoPath), false);
    }

    public function test_logo_upload_rejects_scriptable_svg_files(): void
    {
        Storage::fake('public');
        $hospital = Hospital::factory()->create();
        app()->instance('currentHospital', $hospital);
        $admin = $this->createAdmin($hospital);

        $this->actingAs($admin)
            ->put(route('admin.integration-settings.branding.update'), [
                'site_logo' => UploadedFile::fake()->createWithContent(
                    'hospital-logo.svg',
                    '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
                ),
            ])
            ->assertSessionHasErrors('site_logo');

        $this->assertSame(null, $hospital->fresh()->site_logo_path);
        $this->assertSame([], Storage::disk('public')->allFiles('branding'));
    }

    public function test_custom_branding_appears_on_chat_admin_and_doctor_pages(): void
    {
        $hospital = Hospital::factory()->create([
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
            'site_header_text' => 'Pearl Care Centre',
            'site_footer_text' => 'Care with compassion.',
        ]);
        app()->instance('currentHospital', $hospital);
        $admin = $this->createAdmin($hospital);
        $doctor = User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'doctor',
            'is_doctor' => true,
        ]);

        $this->get(route('tenant.chat.page', $hospital->slug))
            ->assertOk()
            ->assertSee('Pearl Care Centre')
            ->assertSee('Care with compassion.');
        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Pearl Care Centre')
            ->assertSee('Care with compassion.');
        $this->actingAs($doctor)
            ->get(route('doctor.dashboard'))
            ->assertOk()
            ->assertSee('Pearl Care Centre')
            ->assertSee('Care with compassion.');
    }

    public function test_doctor_cannot_update_hospital_branding(): void
    {
        $hospital = Hospital::factory()->create();
        app()->instance('currentHospital', $hospital);
        $doctor = User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'doctor',
            'is_doctor' => true,
        ]);

        $this->actingAs($doctor)
            ->put(route('admin.integration-settings.branding.update'), [
                'site_header_text' => 'Unauthorized branding',
                'site_footer_text' => 'Unauthorized footer',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('hospitals', [
            'id' => $hospital->id,
            'site_header_text' => null,
            'site_footer_text' => null,
        ]);
    }

    private function createAdmin(Hospital $hospital): User
    {
        return User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'hospital_admin',
            'is_admin' => true,
            'is_super_admin' => false,
            'is_doctor' => false,
        ]);
    }
}
