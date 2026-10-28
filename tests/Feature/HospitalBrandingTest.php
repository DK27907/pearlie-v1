<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
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
            ->assertSee('MediDesk Assistant')
            ->assertDontSee('Custom Hospital X');
    }

    public function test_login_page_uses_platform_branding_with_no_tenant_context(): void
    {
        $this->get(route('login'))
            ->assertSee('MediDesk Assistant')
            ->assertDontSee('Pearl Hospital')
            ->assertDontSee('Vin Plaza')
            ->assertDontSee('0707799114');
    }

    public function test_register_page_uses_platform_branding_with_no_tenant_context(): void
    {
        $this->get(route('register'))
            ->assertSee('MediDesk Assistant')
            ->assertDontSee('Pearl Hospital')
            ->assertDontSee('Vin Plaza')
            ->assertDontSee('0707799114');
    }

    public function test_forgot_password_page_uses_platform_branding_with_no_tenant_context(): void
    {
        $this->get(route('password.request'))
            ->assertSee('MediDesk Assistant')
            ->assertDontSee('Pearl Hospital')
            ->assertDontSee('Vin Plaza')
            ->assertDontSee('0707799114');
    }

    public function test_superadmin_dashboard_uses_platform_branding_without_tenant_contacts(): void
    {
        $pearl = Hospital::query()->where('slug', 'pearl')->firstOrFail();
        $superadmin = User::factory()->create([
            'role' => 'super_admin',
            'is_super_admin' => true,
        ]);

        $this->actingAs($superadmin)
            ->get(route('superadmin.dashboard'))
            ->assertOk()
            ->assertSee('MediDesk Assistant')
            ->assertSee('MediDesk Assistant platform administration')
            ->assertSee(asset('images/medidesk-assistant-logo.jpg'), false)
            ->assertDontSee((string) $pearl->address)
            ->assertDontSee((string) $pearl->phone)
            ->assertDontSee((string) $pearl->email);
    }

    public function test_login_page_uses_platform_branding_without_pearl_contact_details(): void
    {
        $pearl = Hospital::query()->where('slug', 'pearl')->firstOrFail();

        $this->get(route('login'))
            ->assertOk()
            ->assertSee(config('app.platform_name'))
            ->assertDontSee('Pearl Hospital')
            ->assertDontSee((string) $pearl->address)
            ->assertDontSee((string) $pearl->phone)
            ->assertDontSee((string) $pearl->email);
    }

    public function test_platform_branding_uses_the_local_healthcare_portrait_logo(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(asset('images/medidesk-assistant-logo.jpg'), false);
    }

    public function test_register_page_uses_platform_branding(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee(config('app.platform_name'))
            ->assertDontSee('Pearl Hospital');
    }

    public function test_tenant_chat_page_retains_its_hospital_branding(): void
    {
        Hospital::query()
            ->where('slug', 'pearl')
            ->firstOrFail()
            ->forceFill(['chatbot_name' => 'Pearlie'])
            ->save();

        $this->get(route('tenant.chat.page', 'pearl'))
            ->assertOk()
            ->assertSee('Pearl Hospital')
            ->assertSee('Pearlie');
    }

    public function test_tenant_login_route_is_available_to_guests_and_redirects_authenticated_users(): void
    {
        $hospital = Hospital::factory()->create([
            'name' => 'Tenant Login Clinic',
            'slug' => 'tenant-login-clinic',
        ]);
        $patient = User::factory()->create([
            'hospital_id' => $hospital->id,
        ]);

        $this->get(route('tenant.login', ['hospital' => $hospital->slug]))
            ->assertOk();

        $this->actingAs($patient)
            ->get(route('tenant.login', ['hospital' => $hospital->slug]))
            ->assertRedirect();
    }

    public function test_tenant_login_rejects_credentials_from_another_hospital(): void
    {
        $pearl = Hospital::factory()->create([
            'name' => 'Pearl Hospital',
            'slug' => 'pearl-login-isolation',
        ]);
        $demo = Hospital::factory()->create([
            'name' => 'Demo Hospital',
            'slug' => 'demo-login-isolation',
        ]);
        $pearlAdmin = $this->createAdmin($pearl);

        $this->post(route('tenant.login.store', ['hospital' => $demo->slug]), [
            'email' => $pearlAdmin->email,
            'password' => 'password',
        ])
            ->assertRedirect(route('tenant.login', ['hospital' => $demo->slug]))
            ->assertSessionHasErrors([
                'email' => 'This account does not belong to this hospital.',
            ]);

        $this->assertGuest();
    }

    public function test_superadmin_can_login_from_a_tenant_url_and_is_sent_to_platform_dashboard(): void
    {
        $hospital = Hospital::factory()->create([
            'slug' => 'superadmin-tenant-login',
        ]);
        $superadmin = User::factory()->create([
            'role' => 'super_admin',
            'is_super_admin' => true,
        ]);

        $this->post(route('tenant.login.store', ['hospital' => $hospital->slug]), [
            'email' => $superadmin->email,
            'password' => 'password',
        ])->assertRedirect(route('superadmin.dashboard'));

        $this->assertAuthenticatedAs($superadmin);
    }

    public function test_tenant_logins_show_their_own_hospital_branding(): void
    {
        $pearl = Hospital::factory()->create([
            'name' => 'Pearl Hospital',
            'slug' => 'pearl-branding-isolation',
        ]);
        $demo = Hospital::factory()->create([
            'name' => 'Demo Hospital',
            'slug' => 'demo-branding-isolation',
        ]);

        $this->get(route('tenant.login', ['hospital' => $pearl->slug]))
            ->assertOk()
            ->assertSee('Pearl Hospital')
            ->assertDontSee('Demo Hospital')
            ->assertDontSee('<h1 class="mt-3 text-xl font-bold text-slate-900">MediDesk Assistant</h1>', false);

        $this->get(route('tenant.login', ['hospital' => $demo->slug]))
            ->assertOk()
            ->assertSee('Demo Hospital')
            ->assertDontSee('Pearl Hospital')
            ->assertDontSee('<h1 class="mt-3 text-xl font-bold text-slate-900">MediDesk Assistant</h1>', false);
    }

    public function test_tenant_login_shows_configured_logo_contact_details_and_initial_placeholder(): void
    {
        Storage::fake('public');
        $hospital = Hospital::factory()->create([
            'name' => 'Logo Clinic',
            'slug' => 'logo-clinic',
            'phone' => '+254700123456',
            'address' => '1 Clinic Road',
            'site_logo_path' => 'hospitals/logo-clinic/logo.png',
        ]);
        Storage::disk('public')->put($hospital->site_logo_path, 'logo-bytes');

        $this->get(route('tenant.login', ['hospital' => $hospital->slug]))
            ->assertOk()
            ->assertSee(Storage::disk('public')->url($hospital->site_logo_path), false)
            ->assertSee('+254700123456')
            ->assertSee('1 Clinic Road');

        $hospital->update(['site_logo_path' => null]);

        $this->get(route('tenant.login', ['hospital' => $hospital->slug]))
            ->assertSee('L');
    }

    public function test_tenant_password_recovery_screen_keeps_the_hospital_branding(): void
    {
        $hospital = Hospital::factory()->create([
            'name' => 'Recovery Clinic',
            'slug' => 'recovery-clinic',
        ]);

        $this->get(route('tenant.password.request', ['hospital' => $hospital->slug]))
            ->assertOk()
            ->assertSee('Recovery Clinic')
            ->assertSee(route('tenant.password.email', ['hospital' => $hospital->slug]), false);
    }

    public function test_each_tenant_login_uses_only_its_own_logo(): void
    {
        Storage::fake('public');
        $pearl = Hospital::factory()->create([
            'name' => 'Pearl Hospital',
            'slug' => 'pearl-logo-isolation',
            'site_logo_path' => 'hospitals/pearl-logo-isolation/logo.png',
        ]);
        $demo = Hospital::factory()->create([
            'name' => 'Demo Hospital',
            'slug' => 'demo-logo-isolation',
            'site_logo_path' => 'hospitals/demo-logo-isolation/logo.png',
        ]);
        Storage::disk('public')->put($pearl->site_logo_path, 'pearl-logo');
        Storage::disk('public')->put($demo->site_logo_path, 'demo-logo');

        $this->get(route('tenant.login', ['hospital' => $pearl->slug]))
            ->assertSee(Storage::disk('public')->url($pearl->site_logo_path), false)
            ->assertDontSee(Storage::disk('public')->url($demo->site_logo_path), false);

        $this->get(route('tenant.login', ['hospital' => $demo->slug]))
            ->assertSee(Storage::disk('public')->url($demo->site_logo_path), false)
            ->assertDontSee(Storage::disk('public')->url($pearl->site_logo_path), false);
    }

    public function test_platform_login_is_premium_and_has_no_tenant_data(): void
    {
        Hospital::factory()->create([
            'name' => 'Pearl Hospital',
            'slug' => 'platform-login-leak-check',
            'phone' => '0707799114',
            'address' => 'Private Tenant Address',
        ]);

        $this->get('/login')
            ->assertOk()
            ->assertSee('MediDesk Assistant')
            ->assertSee('AI-powered healthcare operations')
            ->assertSee('Need help?')
            ->assertSee('v1.0')
            ->assertSee(date('Y'))
            ->assertSee('Privacy')
            ->assertSee('Terms')
            ->assertSee('mailto:'.config('mail.from.address'), false)
            ->assertSee('All rights reserved.')
            ->assertDontSee('Pearl Hospital')
            ->assertDontSee('Demo Hospital')
            ->assertDontSee('Vin Plaza')
            ->assertDontSee('0707799114')
            ->assertDontSee('Private Tenant Address');
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
        $hospital = Hospital::factory()->create([
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
        ]);
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
        $this->assertSame("hospitals/{$hospital->slug}/logo.png", $logoPath);
        Storage::disk('public')->assertExists($logoPath);
        $homeResponse = $this->get(route('tenant.home', $hospital->slug));
        $this->assertSame(200, $homeResponse->getStatusCode(), 'Tenant home redirected to '.$homeResponse->headers->get('Location'));
        $homeResponse->assertSee(Storage::disk('public')->url($logoPath), false);
        $chatResponse = $this->get(route('tenant.chat.page', $hospital->slug));
        $this->assertSame(200, $chatResponse->getStatusCode(), 'Tenant chat redirected to '.$chatResponse->headers->get('Location'));
        $chatResponse
            ->assertSee(Storage::disk('public')->url($logoPath), false);
        Auth::logout();
        $this->get(route('tenant.login', ['hospital' => $hospital->slug]))
            ->assertOk()
            ->assertSee(Storage::disk('public')->url($logoPath), false);
    }

    public function test_branding_settings_preview_the_existing_hospital_logo(): void
    {
        Storage::fake('public');
        $hospital = Hospital::factory()->create([
            'name' => 'Preview Clinic',
            'slug' => 'preview-clinic',
            'site_logo_path' => 'hospitals/preview-clinic/logo.png',
        ]);
        Storage::disk('public')->put($hospital->site_logo_path, 'logo-bytes');
        app()->instance('currentHospital', $hospital);
        $admin = $this->createAdmin($hospital);

        $this->actingAs($admin)
            ->get(route('admin.integration-settings.edit', ['tab' => 'branding']))
            ->assertOk()
            ->assertSee(Storage::disk('public')->url($hospital->site_logo_path), false)
            ->assertSee('Current logo preview')
            ->assertSee('image/webp');
    }

    public function test_logo_upload_sanitizes_scriptable_svg_files(): void
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
            ->assertRedirect(route('admin.integration-settings.edit', ['tab' => 'branding']));

        $logoPath = $hospital->fresh()->site_logo_path;
        $this->assertSame("hospitals/{$hospital->slug}/logo.svg", $logoPath);
        $sanitizedSvg = Storage::disk('public')->get($logoPath);
        $this->assertStringNotContainsString('<script', $sanitizedSvg);
        $this->assertStringContainsString('<svg', $sanitizedSvg);
    }

    public function test_replacing_a_hospital_logo_deletes_the_previous_file(): void
    {
        Storage::fake('public');
        $hospital = Hospital::factory()->create();
        $previousLogoPath = "hospitals/{$hospital->slug}/logo.png";
        Storage::disk('public')->put($previousLogoPath, 'previous-logo');
        $hospital->update(['site_logo_path' => $previousLogoPath]);
        $admin = $this->createAdmin($hospital);

        $this->actingAs($admin)
            ->put(route('admin.integration-settings.branding.update'), [
                'site_logo' => UploadedFile::fake()->create('replacement.jpg', 100, 'image/jpeg'),
            ])
            ->assertRedirect(route('admin.integration-settings.edit', ['tab' => 'branding']));

        $newLogoPath = $hospital->fresh()->site_logo_path;
        $this->assertSame("hospitals/{$hospital->slug}/logo.jpg", $newLogoPath);
        Storage::disk('public')->assertMissing($previousLogoPath);
        Storage::disk('public')->assertExists($newLogoPath);
    }

    public function test_logo_upload_rejects_unsupported_and_oversized_files(): void
    {
        Storage::fake('public');
        $hospital = Hospital::factory()->create();
        app()->instance('currentHospital', $hospital);
        $admin = $this->createAdmin($hospital);

        $this->actingAs($admin)
            ->put(route('admin.integration-settings.branding.update'), [
                'site_logo' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
            ])
            ->assertSessionHasErrors('site_logo');

        $this->actingAs($admin)
            ->put(route('admin.integration-settings.branding.update'), [
                'site_logo' => UploadedFile::fake()->create('large.png', 2049, 'image/png'),
            ])
            ->assertSessionHasErrors('site_logo');

        $this->assertNull($hospital->fresh()->site_logo_path);
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
