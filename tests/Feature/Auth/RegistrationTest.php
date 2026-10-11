<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_registration_screen_is_available_when_registration_requires_an_invite(): void
    {
        config(['app.allow_registration' => false]);
        $this->app->detectEnvironment(fn () => 'production');

        $this->get('/register')
            ->assertOk()
            ->assertSee('Invite Token');
    }

    public function test_new_users_can_register(): void
    {
        $this->get('/register');
        $sessionIdBeforeRegistration = session()->getId();

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertNotSame($sessionIdBeforeRegistration, session()->getId());
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_registration_is_rate_limited_after_six_requests(): void
    {
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.64'])
                ->post('/register', [])
                ->assertRedirect();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.64'])
            ->post('/register', [])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');
    }
}
