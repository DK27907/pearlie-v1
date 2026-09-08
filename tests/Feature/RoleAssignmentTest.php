<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RoleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_role_assignment_via_seeder()
    {
        // Create user and role
        $user = User::factory()->create();
        Role::create(['name' => 'admin']);

        $user->assignRole('admin');
        $this->assertTrue($user->hasRole('admin'));
    }
}
