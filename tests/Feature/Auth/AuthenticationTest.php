<?php

namespace Tests\Feature\Auth;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();
        $this->get('/login');
        $sessionIdBeforeLogin = session()->getId();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertNotSame($sessionIdBeforeLogin, session()->getId());
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_patient_login_ignores_an_intended_admin_route(): void
    {
        $patient = User::factory()->create([
            'role' => 'patient',
            'is_admin' => false,
            'is_super_admin' => false,
            'is_doctor' => false,
        ]);
        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));

        $this->post('/login', [
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

        $this->post('/login', [
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

        $this->post('/login', [
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

        $this->post('/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
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
        $user = User::factory()->create(['is_active' => false]);

        $this->post('/login', [
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

        $this->post('/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertSessionHasErrors(['email' => trans('auth.failed')]);

        $this->assertGuest();
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
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
        $response->assertRedirect('/');
    }

    public function test_get_logout_does_not_end_an_authenticated_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/logout')->assertMethodNotAllowed();

        $this->assertAuthenticatedAs($user);
    }
}
