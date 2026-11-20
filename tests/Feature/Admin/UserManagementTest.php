<?php

namespace Tests\Feature\Admin;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->hospital = Hospital::query()->where('slug', 'pearl')->firstOrFail();
        app()->instance('currentHospital', $this->hospital);
    }

    public function test_admin_can_view_users_in_own_hospital(): void
    {
        $admin = $this->createHospitalAdmin();
        $user = User::factory()->create([
            'hospital_id' => $this->hospital->id,
            'name' => 'Hospital Patient',
            'role' => 'patient',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Hospital Patient')
            ->assertSee('User management');
    }

    public function test_admin_can_deactivate_a_user_without_removing_their_role(): void
    {
        $admin = $this->createHospitalAdmin();
        $user = User::factory()->create([
            'hospital_id' => $this->hospital->id,
            'role' => 'doctor',
            'is_doctor' => true,
        ]);
        $user->syncRolesFromFlags();

        $this->actingAs($admin)
            ->from(route('admin.users.index'))
            ->delete(route('admin.users.destroy', $user))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('status', 'User deactivated successfully.');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_active' => false,
        ]);
        $this->assertTrue($user->fresh()->hasRole('doctor'));
    }

    public function test_admin_cannot_deactivate_themselves(): void
    {
        $admin = $this->createHospitalAdmin();
        $this->createHospitalAdmin();

        $this->actingAs($admin)
            ->from(route('admin.users.index'))
            ->delete(route('admin.users.destroy', $admin))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors([
                'user' => 'You cannot deactivate your own account.',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'is_active' => true,
        ]);
    }

    public function test_admin_cannot_view_users_from_other_hospital(): void
    {
        $admin = $this->createHospitalAdmin();
        $otherHospital = Hospital::factory()->create();
        app()->instance('currentHospital', $otherHospital);
        $otherUser = User::factory()->create([
            'hospital_id' => $otherHospital->id,
            'role' => 'patient',
        ]);
        app()->instance('currentHospital', $this->hospital);

        $this->actingAs($admin)
            ->get(route('admin.users.edit', $otherUser))
            ->assertNotFound();
    }

    public function test_admin_can_update_user_details(): void
    {
        $admin = $this->createHospitalAdmin();
        $user = User::factory()->create([
            'hospital_id' => $this->hospital->id,
            'role' => 'patient',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $user), $this->validUpdate([
                'name' => 'Updated Patient',
                'email' => 'updated-patient@example.test',
                'phone' => '+254700123456',
            ]))
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Patient',
            'email' => 'updated-patient@example.test',
            'phone' => '+254700123456',
        ]);
    }

    public function test_admin_can_change_doctor_password_and_clear_remember_token(): void
    {
        $admin = $this->createHospitalAdmin();
        $user = User::factory()->create([
            'hospital_id' => $this->hospital->id,
            'role' => 'doctor',
            'is_doctor' => true,
            'remember_token' => 'old-remember-token',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $user), $this->validUpdate([
                'password' => 'UpdatedPassword2026!',
                'password_confirmation' => 'UpdatedPassword2026!',
                'must_change_password' => '1',
            ]))
            ->assertRedirect(route('admin.users.index'));

        $user->refresh();
        $this->assertTrue(Hash::check('UpdatedPassword2026!', $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertNull($user->remember_token);
    }

    public function test_super_admin_can_change_doctor_password_across_tenant_scope(): void
    {
        $otherHospital = Hospital::factory()->create();
        app()->instance('currentHospital', $otherHospital);
        $doctor = User::factory()->create([
            'hospital_id' => $otherHospital->id,
            'role' => 'doctor',
            'is_doctor' => true,
            'remember_token' => 'old-remember-token',
        ]);
        app()->instance('currentHospital', $this->hospital);
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'is_super_admin' => true,
        ]);
        $superAdmin->syncRolesFromFlags();

        $this->actingAs($superAdmin)
            ->put(route('superadmin.users.update', $doctor), $this->validUpdate([
                'name' => $doctor->name,
                'email' => $doctor->email,
                'role' => 'doctor',
                'password' => 'SuperAdminUpdated2026!',
                'password_confirmation' => 'SuperAdminUpdated2026!',
            ]))
            ->assertRedirect(route('superadmin.users.index'));

        $updatedDoctor = User::withoutGlobalScopes()->findOrFail($doctor->id);
        $this->assertTrue(Hash::check('SuperAdminUpdated2026!', $updatedDoctor->password));
        $this->assertNull($updatedDoctor->remember_token);
        $this->assertSame($otherHospital->id, $updatedDoctor->hospital_id);
    }

    public function test_admin_can_change_user_role_and_role_flags_are_synchronized(): void
    {
        $admin = $this->createHospitalAdmin();
        $doctor = User::factory()->create([
            'hospital_id' => $this->hospital->id,
            'role' => 'doctor',
            'is_doctor' => true,
        ]);
        $patient = User::factory()->create([
            'hospital_id' => $this->hospital->id,
            'role' => 'patient',
        ]);
        Role::query()->firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);
        $doctor->assignRole('doctor');

        $this->actingAs($admin)
            ->put(route('admin.users.update', $doctor), $this->validUpdate([
                'email' => 'demoted-doctor@example.test',
                'role' => 'patient',
            ]))
            ->assertRedirect(route('admin.users.index'));
        $doctor->refresh();
        $this->assertSame('patient', $doctor->role);
        $this->assertFalse($doctor->is_doctor);
        $this->assertFalse($doctor->is_admin);
        $this->assertFalse($doctor->hasRole('doctor'));
        $this->assertTrue($doctor->hasRole('patient'));

        $this->put(route('admin.users.update', $patient), $this->validUpdate([
            'email' => 'promoted-patient@example.test',
            'role' => 'doctor',
        ]))
            ->assertRedirect(route('admin.users.index'));
        $patient->refresh();
        $this->assertSame('doctor', $patient->role);
        $this->assertTrue($patient->is_doctor);
        $this->assertFalse($patient->is_admin);
        $this->assertTrue($patient->hasRole('doctor'));
        $this->assertFalse($patient->hasRole('patient'));
    }

    public function test_user_role_sync_replaces_spatie_roles_using_legacy_flags(): void
    {
        $user = User::factory()->create([
            'hospital_id' => $this->hospital->id,
            'role' => 'doctor',
            'is_doctor' => true,
            'is_admin' => false,
            'is_super_admin' => false,
        ]);
        Role::query()->firstOrCreate(['name' => 'hospital_admin', 'guard_name' => 'web']);
        $user->assignRole('hospital_admin');

        $user->syncRolesFromFlags();
        $user->refresh();

        $this->assertTrue($user->hasRole('doctor'));
        $this->assertFalse($user->hasRole('hospital_admin'));
    }

    public function test_admin_cannot_delete_or_demote_the_last_hospital_admin(): void
    {
        $admin = $this->createHospitalAdmin();
        $this->actingAs($admin)
            ->put(route('admin.users.update', $admin), $this->validUpdate(['role' => 'patient']))
            ->assertSessionHasErrors([
                'user' => 'The hospital must retain at least one active hospital administrator.',
            ]);

        $this->from(route('admin.users.index'))
            ->delete(route('admin.users.destroy', $admin))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors('user');

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'role' => 'hospital_admin',
            'is_active' => true,
        ]);
    }

    public function test_admin_cannot_edit_superadmin(): void
    {
        $admin = $this->createHospitalAdmin();
        $superadmin = User::factory()->create([
            'hospital_id' => $this->hospital->id,
            'role' => 'super_admin',
            'is_super_admin' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.edit', $superadmin))
            ->assertForbidden();
    }

    public function test_legacy_role_endpoint_cannot_assign_superadmin(): void
    {
        $admin = $this->createHospitalAdmin();
        $user = User::factory()->create([
            'hospital_id' => $this->hospital->id,
            'role' => 'patient',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.roles.assign'), [
                'user_id' => $user->id,
                'role' => 'super_admin',
            ])
            ->assertSessionHasErrors('role');

        $user->refresh();
        $this->assertSame('patient', $user->role);
        $this->assertFalse($user->hasRole('super_admin'));
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validUpdate(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Managed User',
            'email' => 'managed-user@example.test',
            'phone' => '',
            'role' => 'patient',
            'is_active' => '1',
            'must_change_password' => '0',
        ], $overrides);
    }

    private function createHospitalAdmin(): User
    {
        return User::factory()->create([
            'hospital_id' => $this->hospital->id,
            'role' => 'hospital_admin',
            'is_admin' => true,
            'is_super_admin' => false,
        ]);
    }
}
