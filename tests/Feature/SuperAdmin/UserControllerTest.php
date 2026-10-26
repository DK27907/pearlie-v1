<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_super_admin_can_view_users_across_hospitals(): void
    {
        $pearl = Hospital::factory()->create(['name' => 'Pearl Hospital']);
        $nairobi = Hospital::factory()->create(['name' => 'Nairobi Hospital']);
        $superAdmin = $this->createSuperAdmin($pearl);
        User::factory()->create([
            'hospital_id' => $pearl->id,
            'name' => 'Pearl patient',
            'role' => 'patient',
        ]);
        User::factory()->create([
            'hospital_id' => $nairobi->id,
            'name' => 'Nairobi patient',
            'role' => 'patient',
        ]);

        $this->actingAs($superAdmin)
            ->get(route('superadmin.users.index'))
            ->assertSee('Pearl patient')
            ->assertSee('Nairobi patient')
            ->assertSee('Pearl Hospital')
            ->assertSee('Nairobi Hospital');
    }

    public function test_super_admin_can_change_password_for_user_in_another_hospital(): void
    {
        $pearl = Hospital::factory()->create();
        $nairobi = Hospital::factory()->create();
        $superAdmin = $this->createSuperAdmin($pearl);
        $user = User::factory()->create([
            'hospital_id' => $nairobi->id,
            'role' => 'patient',
            'must_change_password' => false,
        ]);
        $rememberToken = $user->remember_token;

        $this->actingAs($superAdmin)
            ->put(route('superadmin.users.update', $user), $this->validUpdate([
                'password' => 'ChangedPassword!2026',
                'password_confirmation' => 'ChangedPassword!2026',
            ]))
            ->assertRedirect(route('superadmin.users.index'));

        $user->refresh();
        $this->assertTrue(Hash::check('ChangedPassword!2026', $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertNotSame($rememberToken, $user->remember_token);
        $this->assertSame((int) $nairobi->id, (int) $user->hospital_id);
    }

    public function test_super_admin_can_change_another_super_admin_password_without_changing_their_role(): void
    {
        $pearl = Hospital::factory()->create();
        $nairobi = Hospital::factory()->create();
        $superAdmin = $this->createSuperAdmin($pearl);
        $anotherSuperAdmin = $this->createSuperAdmin($nairobi);

        $this->actingAs($superAdmin)
            ->get(route('superadmin.users.edit', $anotherSuperAdmin))
            ->assertSee('Super admin (managed by the platform)')
            ->assertDontSee('name="role"');

        $update = $this->validUpdate([
            'email' => $anotherSuperAdmin->email,
            'password' => 'ChangedPassword!2026',
            'password_confirmation' => 'ChangedPassword!2026',
        ]);
        unset($update['role']);

        $this->put(route('superadmin.users.update', $anotherSuperAdmin), $update)
            ->assertRedirect(route('superadmin.users.index'));

        $anotherSuperAdmin->refresh();
        $this->assertTrue(Hash::check('ChangedPassword!2026', $anotherSuperAdmin->password));
        $this->assertTrue($anotherSuperAdmin->is_super_admin);
        $this->assertSame('super_admin', $anotherSuperAdmin->role);
    }

    public function test_super_admin_can_activate_and_deactivate_users_across_hospitals(): void
    {
        $pearl = Hospital::factory()->create();
        $nairobi = Hospital::factory()->create();
        $superAdmin = $this->createSuperAdmin($pearl);
        $user = User::factory()->create([
            'hospital_id' => $nairobi->id,
            'role' => 'patient',
            'is_active' => true,
        ]);

        $this->actingAs($superAdmin)
            ->patch(route('superadmin.users.activation', $user), ['is_active' => '0'])
            ->assertRedirect(route('superadmin.users.index'));
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => false]);

        $this->patch(route('superadmin.users.activation', $user), ['is_active' => '1'])
            ->assertRedirect(route('superadmin.users.index'));
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => true]);
    }

    public function test_super_admin_cannot_deactivate_themselves(): void
    {
        $hospital = Hospital::factory()->create();
        $superAdmin = $this->createSuperAdmin($hospital);

        $this->actingAs($superAdmin)
            ->patch(route('superadmin.users.activation', $superAdmin), ['is_active' => '0'])
            ->assertUnprocessable();

        $this->assertDatabaseHas('users', ['id' => $superAdmin->id, 'is_active' => true]);
    }

    public function test_super_admin_cannot_deactivate_a_hospitals_last_active_admin(): void
    {
        $pearl = Hospital::factory()->create();
        $nairobi = Hospital::factory()->create();
        $superAdmin = $this->createSuperAdmin($pearl);
        $hospitalAdmin = User::factory()->create([
            'hospital_id' => $nairobi->id,
            'role' => 'hospital_admin',
            'is_admin' => true,
            'is_active' => true,
        ]);

        $this->actingAs($superAdmin)
            ->patch(route('superadmin.users.activation', $hospitalAdmin), ['is_active' => '0'])
            ->assertUnprocessable();

        $this->assertDatabaseHas('users', ['id' => $hospitalAdmin->id, 'is_active' => true]);
    }

    public function test_super_admin_cannot_promote_a_user_to_super_admin_through_edit_form(): void
    {
        $hospital = Hospital::factory()->create();
        $superAdmin = $this->createSuperAdmin($hospital);
        $user = User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'patient',
            'is_super_admin' => false,
        ]);

        $this->actingAs($superAdmin)
            ->put(route('superadmin.users.update', $user), $this->validUpdate([
                'role' => 'super_admin',
            ]))
            ->assertSessionHasErrors('role');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => 'patient',
            'is_super_admin' => false,
        ]);
    }

    public function test_hospital_admin_cannot_access_platform_user_management(): void
    {
        $hospital = Hospital::factory()->create();
        $hospitalAdmin = User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'hospital_admin',
            'is_admin' => true,
        ]);

        $this->actingAs($hospitalAdmin)
            ->get(route('superadmin.users.index'))
            ->assertForbidden();
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

    private function createSuperAdmin(Hospital $hospital): User
    {
        return User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'super_admin',
            'is_super_admin' => true,
            'is_admin' => false,
            'is_doctor' => false,
        ]);
    }
}
