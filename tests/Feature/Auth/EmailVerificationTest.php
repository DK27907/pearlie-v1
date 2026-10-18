<?php

namespace Tests\Feature\Auth;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_verification_screen_can_be_rendered(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/verify-email');

        $response->assertStatus(200);
    }

    #[DataProvider('verifiedUserDashboardRoutes')]
    public function test_verified_users_visiting_verification_screen_are_redirected_to_their_dashboard(
        array $attributes,
        string $dashboardRoute,
    ): void {
        $hospital = Hospital::factory()->create();
        $user = User::factory()->create([
            'hospital_id' => $hospital->id,
            ...$attributes,
        ]);

        $this->actingAs($user)
            ->get('/verify-email')
            ->assertRedirect(route($dashboardRoute, absolute: false));
    }

    /**
     * @return array<string, array{array{role: string, is_admin: bool, is_super_admin: bool, is_doctor: bool}, string}>
     */
    public static function verifiedUserDashboardRoutes(): array
    {
        return [
            'patient' => [[
                'role' => 'patient',
                'is_admin' => false,
                'is_super_admin' => false,
                'is_doctor' => false,
            ], 'dashboard'],
            'doctor' => [[
                'role' => 'doctor',
                'is_admin' => false,
                'is_super_admin' => false,
                'is_doctor' => true,
            ], 'doctor.dashboard'],
            'hospital administrator' => [[
                'role' => 'hospital_admin',
                'is_admin' => true,
                'is_super_admin' => false,
                'is_doctor' => false,
            ], 'admin.dashboard'],
            'platform administrator' => [[
                'role' => 'super_admin',
                'is_admin' => false,
                'is_super_admin' => true,
                'is_doctor' => false,
            ], 'superadmin.dashboard'],
        ];
    }

    public function test_email_can_be_verified(): void
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
    }

    public function test_email_is_not_verified_with_invalid_hash(): void
    {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email')]
        );

        $this->actingAs($user)->get($verificationUrl);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_unverified_user_can_request_a_verification_email(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $response = $this->from('/verify-email')
            ->actingAs($user)
            ->post(route('verification.send'));

        $response->assertRedirect('/verify-email')
            ->assertSessionHas('status', 'verification-link-sent');
        Notification::assertSentTo($user, VerifyEmail::class);
    }
}
