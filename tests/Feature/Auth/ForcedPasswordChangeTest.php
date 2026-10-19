<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_is_forced_to_change_password_before_platform_access(): void
    {
        $superAdmin = User::factory()->create([
            'email' => 'forced-superadmin@example.test',
            'password' => Hash::make('CurrentPass!2026'),
            'is_super_admin' => true,
            'role' => 'super_admin',
            'must_change_password' => true,
        ]);

        $this->post(route('login'), [
            'email' => $superAdmin->email,
            'password' => 'CurrentPass!2026',
        ])->assertRedirect(route('password.force.edit'));

        $this->get(route('superadmin.dashboard'))
            ->assertRedirect(route('password.force.edit'));
        $this->get(route('password.force.edit'))->assertOk();

        $this->put(route('password.force.update'), [
            'password' => 'ChangedPass!2026x',
            'password_confirmation' => 'ChangedPass!2026x',
        ])->assertRedirect(route('superadmin.dashboard'));

        $superAdmin->refresh();
        $this->assertFalse($superAdmin->must_change_password);
        $this->assertTrue(Hash::check('ChangedPass!2026x', $superAdmin->password));
        $this->get(route('superadmin.dashboard'))->assertOk();
    }

    public function test_doctor_is_forced_to_change_password_before_doctor_access(): void
    {
        $doctor = User::factory()->create([
            'email' => 'forced-doctor@example.test',
            'password' => Hash::make('CurrentPass!2026'),
            'is_doctor' => true,
            'role' => 'doctor',
            'must_change_password' => true,
        ]);

        $this->post(route('tenant.login', ['hospital' => 'pearl']), [
            'email' => $doctor->email,
            'password' => 'CurrentPass!2026',
        ])->assertRedirect(route('password.force.edit'));

        $this->get(route('doctor.dashboard'))
            ->assertRedirect(route('password.force.edit'));
        $this->get(route('password.force.edit'))->assertOk();

        $this->put(route('password.force.update'), [
            'password' => 'ChangedPass!2026x',
            'password_confirmation' => 'ChangedPass!2026x',
        ])->assertRedirect(route('doctor.dashboard'));

        $doctor->refresh();
        $this->assertFalse($doctor->must_change_password);
        $this->assertTrue(Hash::check('ChangedPass!2026x', $doctor->password));
        $this->get(route('doctor.dashboard'))->assertOk();
    }
}
