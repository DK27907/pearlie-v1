<?php

namespace Tests\Feature\Auth;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SessionTimeoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'session.driver' => 'database',
            'session.expire_on_close' => false,
            'session.lifetime' => 60,
        ]);
    }

    public function test_session_expires_after_61_minutes_of_inactivity(): void
    {
        $hospital = Hospital::factory()->create([
            'subscription_status' => 'active',
        ]);
        $admin = User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'hospital_admin',
            'is_admin' => true,
        ]);

        $this->loginAsHospitalAdmin($hospital, $admin);
        $this->travel(61)->minutes();
        $this->refreshAuthenticationState();

        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_remember_me_restores_authentication_after_session_expires(): void
    {
        $hospital = Hospital::factory()->create([
            'subscription_status' => 'active',
        ]);
        $admin = User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'hospital_admin',
            'is_admin' => true,
        ]);

        $loginResponse = $this->loginAsHospitalAdmin($hospital, $admin, remember: true);
        $recallerCookieName = 'remember_web_'.sha1(SessionGuard::class);
        $recallerCookie = $loginResponse->getCookie($recallerCookieName);
        $this->assertNotNull($recallerCookie);
        $this->withCookie($recallerCookieName, $recallerCookie->getValue());

        $this->travel(61)->minutes();
        $this->refreshAuthenticationState();

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Admin dashboard');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_session_prune_deletes_expired_database_sessions_only(): void
    {
        $expiredSessionId = 'expired-session';
        $activeSessionId = 'active-session';
        DB::table('sessions')->insert([
            [
                'id' => $expiredSessionId,
                'user_id' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'payload' => '',
                'last_activity' => now()->subMinutes(61)->getTimestamp(),
            ],
            [
                'id' => $activeSessionId,
                'user_id' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'payload' => '',
                'last_activity' => now()->subMinutes(59)->getTimestamp(),
            ],
        ]);

        $this->artisan('session:prune')
            ->assertSuccessful();

        $this->assertDatabaseMissing('sessions', ['id' => $expiredSessionId]);
        $this->assertDatabaseHas('sessions', ['id' => $activeSessionId]);
    }

    private function loginAsHospitalAdmin(Hospital $hospital, User $admin, bool $remember = false): TestResponse
    {
        $this->get(route('tenant.login', ['hospital' => $hospital->slug]))
            ->assertOk();

        return $this->post(route('tenant.login.store', ['hospital' => $hospital->slug]), [
            'email' => $admin->email,
            'password' => 'password',
            'remember' => $remember,
        ])->assertRedirect(route('admin.dashboard'));
    }

    private function refreshAuthenticationState(): void
    {
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        Auth::forgetGuards();
    }
}
