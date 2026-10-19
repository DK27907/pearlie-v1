<?php

namespace Tests\Feature\Admin;

use App\Mail\DoctorInviteMail;
use App\Models\DoctorInvite;
use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class DoctorManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        app()->instance(
            'currentHospital',
            Hospital::query()->where('slug', 'pearl')->firstOrFail(),
        );
    }

    public function test_doctor_created_via_admin_has_doctor_role_and_flag(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->post(route('admin.doctors.store'), [
            'name' => 'Dr. Ada Kamau',
            'email' => 'ada@example.test',
            'specialization' => 'General Medicine',
            'phone' => '+254 712 345 678',
            'bio' => 'Focused on preventive and family medicine.',
            'consultation_fee' => '2500.00',
            'licence_number' => 'KMPDC-A12345',
        ]);

        $response->assertRedirect(route('admin.doctors.index'))
            ->assertSessionHas('status', 'Doctor account created and invitation email sent.');
        $this->assertDatabaseHas('users', [
            'name' => 'Dr. Ada Kamau',
            'email' => 'ada@example.test',
            'specialization' => 'General Medicine',
            'phone' => '+254 712 345 678',
            'bio' => 'Focused on preventive and family medicine.',
            'consultation_fee' => '2500.00',
            'licence_number' => 'KMPDC-A12345',
            'is_doctor' => true,
        ]);
        $this->assertDatabaseCount('doctor_invites', 1);
        $doctor = User::query()->where('email', 'ada@example.test')->firstOrFail();
        $this->assertSame('doctor', $doctor->role);
        $this->assertTrue($doctor->is_doctor);
        $this->assertTrue($doctor->hasRole('doctor'));
        $sentMail = null;
        Mail::assertSent(DoctorInviteMail::class, function (DoctorInviteMail $mail) use (&$sentMail): bool {
            $sentMail = $mail;

            return $mail->doctor->email === 'ada@example.test';
        });
        $this->assertSame("You're invited to the MediDesk AI Doctor Dashboard", $sentMail->envelope()->subject);
    }

    public function test_doctor_with_temporary_password_must_change_on_first_login(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->post(route('admin.doctors.store'), [
            'name' => 'Dr. Temporary Password',
            'email' => 'temporary-doctor@example.test',
            'specialization' => 'General Medicine',
            'phone' => '+254 712 345 678',
            'use_temporary_password' => '1',
        ]);

        $response->assertRedirect(route('admin.doctors.index'))
            ->assertSessionHas('temporary_password');
        $temporaryPassword = session('temporary_password');
        $doctor = User::query()->where('email', 'temporary-doctor@example.test')->firstOrFail();
        $this->assertIsString($temporaryPassword);
        $this->assertSame(12, strlen($temporaryPassword));
        $this->assertTrue(Hash::check($temporaryPassword, $doctor->password));
        $this->assertTrue($doctor->must_change_password);
        $this->assertDatabaseCount('doctor_invites', 0);
        Mail::assertNothingOutgoing();

        $this->post(route('logout'));
        $this->post(route('tenant.login', ['hospital' => 'pearl']), [
            'email' => $doctor->email,
            'password' => $temporaryPassword,
        ])->assertRedirect(route('password.force.edit'));
        $this->get(route('doctor.dashboard'))->assertRedirect(route('password.force.edit'));
    }

    public function test_doctor_can_change_forced_password_and_access_dashboard(): void
    {
        $doctor = User::factory()->create([
            'is_doctor' => true,
            'role' => 'doctor',
            'must_change_password' => true,
        ]);

        $this->actingAs($doctor)
            ->get(route('doctor.dashboard'))
            ->assertRedirect(route('password.force.edit'));

        $this->put(route('password.force.update'), [
            'password' => 'a-new-secure-password',
            'password_confirmation' => 'a-new-secure-password',
        ])->assertRedirect(route('doctor.dashboard'));

        $doctor->refresh();
        $this->assertFalse($doctor->must_change_password);
        $this->assertTrue(Hash::check('a-new-secure-password', $doctor->password));
        $this->get(route('doctor.dashboard'))->assertOk();
    }

    public function test_doctor_without_must_change_flag_skips_password_change(): void
    {
        $doctor = User::factory()->create([
            'is_doctor' => true,
            'role' => 'doctor',
            'must_change_password' => false,
        ]);

        $this->post(route('tenant.login', ['hospital' => 'pearl']), [
            'email' => $doctor->email,
            'password' => 'password',
        ])->assertRedirect(route('doctor.dashboard'));

        $this->get(route('doctor.dashboard'))->assertOk();
    }

    public function test_admin_cannot_create_doctor_with_a_negative_consultation_fee(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.doctors.store'), [
            'name' => 'Dr. Ada Kamau',
            'email' => 'ada@example.test',
            'specialization' => 'General Medicine',
            'phone' => '+254 712 345 678',
            'consultation_fee' => '-1',
        ])->assertSessionHasErrors('consultation_fee');

        $this->assertDatabaseMissing('users', ['email' => 'ada@example.test']);
        Mail::assertNothingOutgoing();
    }

    public function test_non_admin_cannot_create_doctor(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('admin.doctors.store'), [
            'name' => 'Dr. Ada Kamau',
            'email' => 'ada@example.test',
            'specialization' => 'General Medicine',
            'phone' => '0712345678',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'ada@example.test']);
        Mail::assertNothingOutgoing();
    }

    public function test_admin_can_search_and_deactivate_a_doctor(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $doctor = User::factory()->create([
            'is_doctor' => true,
            'name' => 'Dr. Ada Kamau',
            'specialization' => 'General Medicine',
        ]);
        DoctorInvite::factory()->for($doctor, 'doctor')->create();
        $otherDoctor = User::factory()->create([
            'is_doctor' => true,
            'name' => 'Dr. Sam Otieno',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.doctors.index', ['search' => 'Ada']))
            ->assertOk()
            ->assertSee('Dr. Ada Kamau')
            ->assertDontSee('Dr. Sam Otieno');

        $this->delete(route('admin.doctors.destroy', $doctor))
            ->assertRedirect(route('admin.doctors.index'))
            ->assertSessionHas('status', 'Doctor account deactivated.');

        $this->assertDatabaseHas('users', [
            'id' => $doctor->id,
            'is_doctor' => false,
        ]);
        $this->assertNotNull(
            DoctorInvite::withoutGlobalScope('hospital')
                ->where('hospital_id', $doctor->hospital_id)
                ->where('doctor_id', $doctor->id)
                ->value('used_at'),
        );
        $this->assertModelExists($otherDoctor);
    }

    public function test_doctor_invite_email_escapes_doctor_name(): void
    {
        $doctor = User::factory()->make([
            'name' => '<script>alert(1)</script>',
        ]);
        $mail = new DoctorInviteMail($doctor, Str::random(64), now()->addDay());
        $html = $mail->render();

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('Set Up My Account', $html);
    }
}
