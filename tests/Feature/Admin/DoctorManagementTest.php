<?php

namespace Tests\Feature\Admin;

use App\Mail\DoctorInviteMail;
use App\Models\DoctorInvite;
use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_admin_can_create_doctor_and_invitation_email_is_sent(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->post(route('admin.doctors.store'), [
            'name' => 'Dr. Ada Kamau',
            'email' => 'ada@example.test',
            'specialization' => 'General Medicine',
            'phone' => '+254 712 345 678',
        ]);

        $response->assertRedirect(route('admin.doctors.index'))
            ->assertSessionHas('status', 'Doctor account created and invitation email sent.');
        $this->assertDatabaseHas('users', [
            'name' => 'Dr. Ada Kamau',
            'email' => 'ada@example.test',
            'specialization' => 'General Medicine',
            'phone' => '+254 712 345 678',
            'is_doctor' => true,
        ]);
        $this->assertDatabaseCount('doctor_invites', 1);
        $sentMail = null;
        Mail::assertSent(DoctorInviteMail::class, function (DoctorInviteMail $mail) use (&$sentMail): bool {
            $sentMail = $mail;

            return $mail->doctor->email === 'ada@example.test';
        });
        $this->assertSame("You're invited to the MediDesk AI Doctor Dashboard", $sentMail->envelope()->subject);
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
        $this->assertDatabaseHas('doctor_invites', [
            'doctor_id' => $doctor->id,
            'used_at' => now(),
        ]);
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
