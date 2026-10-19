<?php

namespace Tests\Feature\Auth;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_patient_can_access_the_patient_dashboard(): void
    {
        $patient = User::factory()->create([
            'role' => 'patient',
            'is_admin' => false,
            'is_super_admin' => false,
            'is_doctor' => false,
        ]);

        $this->actingAs($patient)
            ->get(route('dashboard'))
            ->assertOk();
    }

    #[DataProvider('nonPatientRoles')]
    public function test_non_patient_roles_are_forbidden_from_the_patient_dashboard(array $attributes): void
    {
        $user = User::factory()->create($attributes);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertForbidden();
    }

    /**
     * @return array<string, array{array{role: string, is_admin: bool, is_super_admin: bool, is_doctor: bool}}>
     */
    public static function nonPatientRoles(): array
    {
        return [
            'doctor' => [[
                'role' => 'doctor',
                'is_admin' => false,
                'is_super_admin' => false,
                'is_doctor' => true,
            ]],
            'hospital administrator' => [[
                'role' => 'hospital_admin',
                'is_admin' => true,
                'is_super_admin' => false,
                'is_doctor' => false,
            ]],
            'super administrator' => [[
                'role' => 'super_admin',
                'is_admin' => false,
                'is_super_admin' => true,
                'is_doctor' => false,
            ]],
        ];
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $hospital = Hospital::factory()->create();
        $user = User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'patient',
        ]);
        $this->get("/h/{$hospital->slug}/login");
        $sessionIdBeforeLogin = session()->getId();

        $response = $this->post("/h/{$hospital->slug}/login", [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertNotSame($sessionIdBeforeLogin, session()->getId());
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    #[DataProvider('authenticatedRoleRoutes')]
    public function test_logout_redirects_to_the_correct_login_surface(string $role, string $routeName): void
    {
        $hospital = Hospital::query()->where('slug', 'pearl')->firstOrFail();
        $attributes = match ($role) {
            'doctor' => ['role' => 'doctor', 'is_doctor' => true],
            'hospital_admin' => ['role' => 'hospital_admin', 'is_admin' => true],
            'super_admin' => ['role' => 'super_admin', 'is_super_admin' => true],
            default => ['role' => 'patient'],
        };
        $user = User::factory()->create([
            'hospital_id' => $hospital->id,
            ...$attributes,
        ]);

        $logoutDestination = in_array($role, ['doctor', 'hospital_admin'], true)
            ? route('tenant.login', ['hospital' => $hospital->slug])
            : route('login');

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect($logoutDestination);

        $this->get(route($routeName))->assertRedirect(route('login'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function authenticatedRoleRoutes(): array
    {
        return [
            'patient' => ['patient', 'dashboard'],
            'doctor' => ['doctor', 'doctor.dashboard'],
            'hospital administrator' => ['hospital_admin', 'admin.dashboard'],
            'platform administrator' => ['super_admin', 'superadmin.dashboard'],
        ];
    }

    public function test_tenant_logout_redirects_to_the_same_hospital_login(): void
    {
        $hospital = Hospital::factory()->create([
            'name' => 'Tenant Logout Clinic',
            'slug' => 'tenant-logout-clinic',
        ]);
        $admin = User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'hospital_admin',
            'is_admin' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('tenant.logout', ['hospital' => $hospital->slug]))
            ->assertRedirect(route('tenant.login', ['hospital' => $hospital->slug]));
    }

    #[DataProvider('authenticatedRoleRoutes')]
    public function test_authenticated_users_visiting_login_are_redirected_to_their_dashboard(string $role, string $routeName): void
    {
        $hospital = Hospital::query()->where('slug', 'pearl')->firstOrFail();
        $attributes = match ($role) {
            'doctor' => ['role' => 'doctor', 'is_doctor' => true],
            'hospital_admin' => ['role' => 'hospital_admin', 'is_admin' => true],
            'super_admin' => ['role' => 'super_admin', 'is_super_admin' => true],
            default => ['role' => 'patient'],
        };
        $user = User::factory()->create([
            'hospital_id' => $hospital->id,
            ...$attributes,
        ]);

        $this->actingAs($user)
            ->get(route('login'))
            ->assertRedirect(route($routeName));
    }

    #[DataProvider('dashboardRoleRoutes')]
    public function test_authenticated_dashboard_refreshes_succeed_repeatedly(string $role, string $routeName): void
    {
        $hospital = Hospital::query()->where('slug', 'pearl')->firstOrFail();
        $attributes = match ($role) {
            'doctor' => ['role' => 'doctor', 'is_doctor' => true],
            'hospital administrator' => ['role' => 'hospital_admin', 'is_admin' => true],
            default => ['role' => 'super_admin', 'is_super_admin' => true],
        };
        $user = User::factory()->create([
            'hospital_id' => $hospital->id,
            ...$attributes,
        ]);

        $this->actingAs($user);
        foreach (range(1, 5) as $refresh) {
            $this->get(route($routeName))->assertOk();
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function dashboardRoleRoutes(): array
    {
        return [
            'doctor' => ['doctor', 'doctor.dashboard'],
            'hospital administrator' => ['hospital administrator', 'admin.dashboard'],
            'platform administrator' => ['platform administrator', 'superadmin.dashboard'],
        ];
    }

    public function test_patient_login_ignores_an_intended_admin_route(): void
    {
        $hospital = Hospital::factory()->create();
        $patient = User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'patient',
            'is_admin' => false,
            'is_super_admin' => false,
            'is_doctor' => false,
        ]);
        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));

        $this->post("/h/{$hospital->slug}/login", [
            'email' => $patient->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($patient);
    }

    public function test_admin_login_preserves_an_intended_admin_route(): void
    {
        $hospital = Hospital::query()->create([
            'name' => 'Clinic',
            'slug' => 'admin-intended-clinic',
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
        ]);
        $admin = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
        $this->get(route('admin.appointments.index'))
            ->assertRedirect(route('login'));

        $this->post("/h/{$hospital->slug}/login", [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('admin.appointments.index'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_doctor_login_redirects_to_the_doctor_dashboard(): void
    {
        $hospital = Hospital::query()->create([
            'name' => 'Clinic',
            'slug' => 'doctor-login-clinic',
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
        ]);
        $doctor = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_doctor' => true,
            'role' => 'doctor',
        ]);

        $this->post("/h/{$hospital->slug}/login", [
            'email' => $doctor->email,
            'password' => 'password',
        ])->assertRedirect(route('doctor.dashboard'));

        $this->assertAuthenticatedAs($doctor);
    }

    public function test_hospital_admin_login_redirects_to_the_admin_dashboard(): void
    {
        $hospital = Hospital::query()->create([
            'name' => 'Clinic',
            'slug' => 'admin-login-clinic',
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
        ]);
        $admin = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);

        $this->post("/h/{$hospital->slug}/login", [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_tenant_admin_session_survives_a_fresh_request_with_stale_hospital_context(): void
    {
        $pearl = Hospital::query()->where('slug', 'pearl')->firstOrFail();
        $hospital = Hospital::query()->create([
            'name' => 'Verification Clinic',
            'slug' => 'verification-session-clinic',
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
        ]);
        $admin = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);

        $this->post("/h/{$hospital->slug}/login", [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));

        Auth::forgetGuards();
        app()->instance('currentHospital', $pearl);

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Admin dashboard');
    }

    public function test_super_admin_login_redirects_to_the_platform_dashboard(): void
    {
        $admin = User::factory()->create([
            'is_super_admin' => true,
            'role' => 'super_admin',
        ]);

        $this->post('/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('superadmin.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        $hospital = Hospital::factory()->create();
        $user = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_active' => false,
        ]);

        $this->post("/h/{$hospital->slug}/login", [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors(['email' => trans('auth.failed')]);

        $this->assertGuest();
    }

    public function test_user_cannot_log_in_to_a_suspended_hospital(): void
    {
        $hospital = Hospital::query()->create([
            'name' => 'Suspended Clinic',
            'slug' => 'suspended-login-clinic',
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'suspended',
            'is_active' => false,
        ]);
        $admin = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);

        $this->post("/h/{$hospital->slug}/login", [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertSessionHasErrors(['email' => trans('auth.failed')]);

        $this->assertGuest();
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $hospital = Hospital::factory()->create();
        $user = User::factory()->create(['hospital_id' => $hospital->id]);

        $this->post("/h/{$hospital->slug}/login", [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors(['email' => trans('auth.failed')]);

        $this->assertGuest();
    }

    public function test_login_is_locked_after_five_failed_attempts(): void
    {
        $credentials = [
            'email' => 'missing@example.test',
            'password' => 'wrong-password',
        ];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.65'])
                ->post('/login', $credentials)
                ->assertSessionHasErrors(['email' => trans('auth.failed')]);
        }

        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.65'])
            ->post('/login', $credentials)
            ->assertSessionHasErrors(['email']);

        $this->assertStringStartsWith(
            'Too many login attempts.',
            $response->getSession()->get('errors')->getBag('default')->first('email'),
        );

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.65'])
            ->post('/login', $credentials)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect(route('login'));
    }

    public function test_get_logout_does_not_end_an_authenticated_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/logout')->assertMethodNotAllowed();

        $this->assertAuthenticatedAs($user);
    }
}
