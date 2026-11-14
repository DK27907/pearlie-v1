<?php

namespace Tests\Feature\Auth;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_can_log_in_from_the_matching_hospital_route(): void
    {
        $hospital = Hospital::factory()->create([
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
        ]);
        app()->instance('currentHospital', $hospital);

        $doctor = User::factory()->create([
            'hospital_id' => $hospital->id,
            'email' => 'tenant-doctor@example.test',
            'password' => 'CorrectPass!2026',
            'role' => 'doctor',
            'is_doctor' => true,
            'is_admin' => false,
            'is_super_admin' => false,
            'must_change_password' => false,
        ]);
        $doctor->syncRolesFromFlags();

        app()->instance('currentHospital', Hospital::factory()->create());

        $this->get(route('tenant.login', ['hospital' => $hospital->slug]))
            ->assertOk();

        $this->post(route('tenant.login.store', ['hospital' => $hospital->slug]), [
            'email' => $doctor->email,
            'password' => 'CorrectPass!2026',
        ])->assertRedirect(route('doctor.dashboard'));

        $this->assertAuthenticatedAs($doctor);
    }

    public function test_tenant_login_rejects_an_account_from_another_hospital(): void
    {
        $accountHospital = Hospital::factory()->create([
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
        ]);
        $loginHospital = Hospital::factory()->create([
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
        ]);
        app()->instance('currentHospital', $accountHospital);

        $doctor = User::factory()->create([
            'hospital_id' => $accountHospital->id,
            'email' => 'other-tenant-doctor@example.test',
            'password' => 'CorrectPass!2026',
            'role' => 'doctor',
            'is_doctor' => true,
            'is_admin' => false,
            'is_super_admin' => false,
            'must_change_password' => false,
        ]);
        $doctor->syncRolesFromFlags();

        $this->post(route('tenant.login.store', ['hospital' => $loginHospital->slug]), [
            'email' => $doctor->email,
            'password' => 'CorrectPass!2026',
        ])->assertRedirect(route('tenant.login', ['hospital' => $loginHospital->slug]))
            ->assertSessionHasErrors([
                'email' => 'This account does not belong to this hospital.',
            ]);

        $this->assertGuest();
    }
}
