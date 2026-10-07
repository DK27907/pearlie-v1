<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Hospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->instance(
            'currentHospital',
            Hospital::query()->where('slug', 'pearl')->firstOrFail(),
        );
    }

    public function test_header_appears_on_home_page(): void
    {
        $this->get('/pearlie')
            ->assertOk()
            ->assertSee('Pearl Hospital')
            ->assertSee('Chat with Pearlie');
    }

    public function test_footer_appears_on_home_page(): void
    {
        $this->get('/pearlie')
            ->assertOk()
            ->assertSee('Vin Plaza, Nyahururu-Nyeri Road')
            ->assertSee(config('pearlie.hospital.emergency_phone'))
            ->assertSee('All rights reserved.');
    }

    public function test_header_shows_admin_link_for_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get('/pearlie')
            ->assertOk()
            ->assertSee('Admin Dashboard');
    }

    public function test_header_shows_doctor_link_for_doctor(): void
    {
        $doctor = User::factory()->create(['is_doctor' => true]);

        $this->actingAs($doctor)
            ->get('/pearlie')
            ->assertOk()
            ->assertSee('My Dashboard');
    }

    public function test_header_shows_login_for_guest(): void
    {
        $this->get('/pearlie')
            ->assertOk()
            ->assertSee('Login')
            ->assertSee('Register');
    }

    public function test_guest_layout_renders_auth_form_once(): void
    {
        $response = $this->get(route('login'))->assertOk();

        $this->assertSame(1, substr_count($response->getContent(), 'name="email"'));
    }

    public function test_auth_pages_share_the_hospital_branding_and_chat_link(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee(config('pearlie.hospital.name'))
            ->assertSee(config('pearlie.hospital.emergency_phone'))
            ->assertSee('Return to chat');
    }
}
