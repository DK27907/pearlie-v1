<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status', __(Password::RESET_LINK_SENT))
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_mail_delivery_failure_returns_the_non_enumerating_success_response(): void
    {
        $user = User::factory()->create();
        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('Mail transport unavailable.'));
        $mailFactory = Mockery::mock(MailFactory::class);
        $mailFactory->shouldReceive('mailer')
            ->once()
            ->with(null)
            ->andReturn($mailer);
        $this->app->instance(MailFactory::class, $mailFactory);
        Log::spy();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status', __(Password::RESET_LINK_SENT))
            ->assertSessionHasNoErrors();

        Log::shouldHaveReceived('error')->once();
    }

    public function test_unknown_email_returns_the_same_success_response(): void
    {
        $this->post('/forgot-password', ['email' => 'unknown@example.test'])
            ->assertRedirect()
            ->assertSessionHas('status', __(Password::RESET_LINK_SENT))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'unknown@example.test']);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));
            $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);

            $this->assertFalse(Hash::check('old-password', $user->fresh()->password));
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'old-password',
            ])->assertSessionHasErrors('email');
            $this->assertGuest();

            $this->post('/login', [
                'email' => $user->email,
                'password' => 'new-password',
            ])->assertRedirect(route('dashboard', absolute: false));
            $this->assertAuthenticatedAs($user);

            return true;
        });
    }

    public function test_password_reset_rejects_a_tampered_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token.'tampered',
                'email' => $user->email,
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])->assertSessionHasErrors('email');

            $this->assertTrue(Hash::check('password', $user->fresh()->password));

            return true;
        });
    }

    public function test_password_reset_rejects_an_expired_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->travel(61)->minutes();

            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])->assertSessionHasErrors('email');

            $this->assertTrue(Hash::check('password', $user->fresh()->password));

            return true;
        });
    }
}
