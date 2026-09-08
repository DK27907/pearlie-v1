<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class EnsureUserIsAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_access_admin_routes()
    {
        // Create a normal user
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin/escalations');

        $response->assertStatus(403);
    }

    public function test_admin_can_access_admin_routes()
    {
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();

        $response = $this->actingAs($user)->get('/admin/escalations');

        $response->assertStatus(200);
    }
}
