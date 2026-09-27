<?php

namespace Tests\Feature\Auth;

use App\Mail\DoctorInviteMail;
use App\Models\DoctorInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DoctorSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_can_set_password_using_single_use_invite_token(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.doctors.store'), [
            'name' => 'Dr. Ada Kamau',
            'email' => 'ada@example.test',
            'specialization' => 'General Medicine',
            'phone' => '0712345678',
        ])->assertRedirect(route('admin.doctors.index'));

        $mail = null;
        Mail::assertSent(DoctorInviteMail::class, function (DoctorInviteMail $sent) use (&$mail): bool {
            $mail = $sent;

            return $sent->doctor->email === 'ada@example.test';
        });

        auth()->logout();

        $this->get(route('doctor.setup', $mail->token))
            ->assertOk()
            ->assertSee('Set up your doctor account');

        $this->post(route('doctor.setup.store', $mail->token), [
            'password' => 'StrongDoctorPass123',
            'password_confirmation' => 'StrongDoctorPass123',
        ])->assertRedirect(route('doctor.dashboard'))
            ->assertSessionHasNoErrors();

        $doctor = User::query()->where('email', 'ada@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($doctor);
        $this->assertTrue(Hash::check('StrongDoctorPass123', $doctor->password));
        $this->assertNotNull(DoctorInvite::query()->firstOrFail()->used_at);

        auth()->logout();
        $this->get(route('doctor.setup', $mail->token))->assertNotFound();
    }

    public function test_expired_doctor_invite_token_is_rejected(): void
    {
        $doctor = User::factory()->create(['is_doctor' => true]);
        $rawToken = 'expired-doctor-invite-token';
        DoctorInvite::factory()->for($doctor, 'doctor')->create([
            'token' => hash('sha256', $rawToken),
            'expires_at' => now()->subSecond(),
        ]);

        $this->get(route('doctor.setup', $rawToken))->assertNotFound();

        $this->post(route('doctor.setup.store', $rawToken), [
            'password' => 'StrongDoctorPass123',
            'password_confirmation' => 'StrongDoctorPass123',
        ])->assertNotFound();
        $this->assertGuest();
    }

    public function test_password_edit_route_displays_the_change_password_form(): void
    {
        $doctor = User::factory()->create(['is_doctor' => true]);

        $this->actingAs($doctor)
            ->get(route('password.edit'))
            ->assertOk()
            ->assertSee('Change Password')
            ->assertSee('Current Password');
    }
}
