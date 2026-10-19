<?php

namespace Tests\Feature\Auth;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformLoginSuperAdminOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_log_in_at_platform_login(): void
    {
        $superAdmin = User::factory()->create([
            'email' => 'super-test@example.test',
            'password' => 'CorrectPass!2026',
            'is_super_admin' => true,
            'role' => 'super_admin',
            'is_active' => true,
            'hospital_id' => null,
        ]);
        $superAdmin->syncRolesFromFlags();

        $response = $this->post('/login', [
            'email' => 'super-test@example.test',
            'password' => 'CorrectPass!2026',
        ]);

        $response->assertRedirect('/superadmin/dashboard');
        $this->assertAuthenticatedAs($superAdmin);
    }

    public function test_hospital_admin_cannot_log_in_at_platform_login(): void
    {
        $admin = $this->createTenantUser([
            'email' => 'hosp-admin@example.test',
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);

        $response = $this->post('/login', [
            'email' => $admin->email,
            'password' => 'CorrectPass!2026',
        ]);

        $response->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->assertGuest();
    }

    public function test_doctor_cannot_log_in_at_platform_login(): void
    {
        $doctor = $this->createTenantUser([
            'email' => 'doctor@example.test',
            'is_doctor' => true,
            'role' => 'doctor',
        ]);

        $response = $this->post('/login', [
            'email' => $doctor->email,
            'password' => 'CorrectPass!2026',
        ]);

        $response->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->assertGuest();
    }

    public function test_tenant_login_still_works_for_hospital_admin(): void
    {
        $hospital = Hospital::factory()->create();
        $admin = $this->createTenantUser([
            'email' => 'hosp-admin2@example.test',
            'hospital_id' => $hospital->id,
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);

        $response = $this->post("/h/{$hospital->slug}/login", [
            'email' => $admin->email,
            'password' => 'CorrectPass!2026',
        ]);

        $response->assertRedirect('/admin/dashboard');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_tenant_login_still_works_for_doctor(): void
    {
        $hospital = Hospital::factory()->create();
        $doctor = $this->createTenantUser([
            'email' => 'doctor2@example.test',
            'hospital_id' => $hospital->id,
            'is_doctor' => true,
            'role' => 'doctor',
            'must_change_password' => false,
        ]);

        $response = $this->post("/h/{$hospital->slug}/login", [
            'email' => $doctor->email,
            'password' => 'CorrectPass!2026',
        ]);

        $response->assertRedirect('/doctor/dashboard');
        $this->assertAuthenticatedAs($doctor);
    }

    public function test_wrong_password_uses_the_generic_authentication_error(): void
    {
        $superAdmin = User::factory()->create([
            'email' => 'super-wrong@example.test',
            'password' => 'CorrectPass!2026',
            'is_super_admin' => true,
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $superAdmin->syncRolesFromFlags();

        $response = $this->post('/login', [
            'email' => $superAdmin->email,
            'password' => 'WrongPass!2026',
        ]);

        $response->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->assertGuest();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createTenantUser(array $attributes): User
    {
        $hospital = $attributes['hospital_id'] ?? Hospital::factory()->create()->id;
        $user = User::factory()->create([
            'password' => 'CorrectPass!2026',
            'hospital_id' => $hospital,
            'is_admin' => false,
            'is_doctor' => false,
            'is_super_admin' => false,
            ...$attributes,
        ]);
        $user->syncRolesFromFlags();

        return $user;
    }
}
