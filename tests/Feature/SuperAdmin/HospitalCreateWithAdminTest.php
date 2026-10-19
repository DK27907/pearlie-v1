<?php

namespace Tests\Feature\SuperAdmin;

use App\Mail\HospitalAdminWelcomeMail;
use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HospitalCreateWithAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_hospital_and_admin_in_one_request(): void
    {
        Mail::fake();
        $superAdmin = $this->superAdmin();

        $response = $this->actingAs($superAdmin)->post(route('superadmin.hospitals.store'), $this->hospitalPayload());

        $hospital = Hospital::withoutGlobalScopes()->where('slug', 'nairobi-medical-centre')->firstOrFail();
        $admin = User::withoutGlobalScopes()->where('email', 'admin@nairobi-medical.test')->firstOrFail();

        $response->assertRedirect(route('superadmin.hospitals.show', $hospital))
            ->assertSessionHas(
                'status',
                'Hospital created. Admin can log in at /h/nairobi-medical-centre/login with admin@nairobi-medical.test. Welcome email queued to admin@nairobi-medical.test.',
            );
        Mail::assertQueued(HospitalAdminWelcomeMail::class, function (HospitalAdminWelcomeMail $mail) use ($hospital, $admin): bool {
            if (! $mail->hasTo($admin->email) || $mail->hospital->id !== $hospital->id || $mail->admin->id !== $admin->id) {
                return false;
            }

            $serializedMail = serialize($mail);
            $rendered = $mail->render();

            return str_contains($rendered, 'SecureStart!2026')
                && str_contains($rendered, 'Change this password immediately on first login')
                && str_contains($rendered, route('tenant.login', ['hospital' => $hospital->slug]))
                && ! str_contains($serializedMail, 'SecureStart!2026');
        });
        $this->assertSame($hospital->id, $admin->hospital_id);
        $this->assertSame('hospital_admin', $admin->role);
        $this->assertTrue($admin->is_admin);
        $this->assertFalse($admin->is_doctor);
        $this->assertFalse($admin->is_super_admin);
        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->must_change_password);
        $this->assertTrue($admin->hasRole('hospital_admin'));
        $this->assertTrue(Hash::check('SecureStart!2026', $admin->password));
    }

    public function test_created_admin_must_change_password_before_accessing_dashboard(): void
    {
        Mail::fake();
        $superAdmin = $this->superAdmin();
        $this->actingAs($superAdmin)->post(route('superadmin.hospitals.store'), $this->hospitalPayload());
        auth()->logout();

        $this->post(route('tenant.login.store', ['hospital' => 'nairobi-medical-centre']), [
            'email' => 'admin@nairobi-medical.test',
            'password' => 'SecureStart!2026',
        ])->assertRedirect(route('password.force.edit'));
        $this->assertAuthenticated();
        $this->get(route('admin.dashboard'))->assertRedirect(route('password.force.edit'));
        $this->get(route('password.force.edit'))->assertOk();

        $this->put(route('password.force.update'), [
            'password' => 'ChangedPass!2026x',
            'password_confirmation' => 'ChangedPass!2026x',
        ])->assertRedirect(route('admin.dashboard'));

        $admin = User::withoutGlobalScopes()->where('email', 'admin@nairobi-medical.test')->firstOrFail();
        $this->assertFalse($admin->must_change_password);
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_welcome_email_escapes_hospital_and_admin_names(): void
    {
        Mail::fake();
        $superAdmin = $this->superAdmin();
        $this->actingAs($superAdmin)->post(route('superadmin.hospitals.store'), $this->hospitalPayload([
            'name' => '<script>alert("hospital")</script>',
            'admin_name' => '<script>alert("admin")</script>',
        ]));

        Mail::assertQueued(HospitalAdminWelcomeMail::class, function (HospitalAdminWelcomeMail $mail): bool {
            $rendered = $mail->render();

            return str_contains($rendered, '&lt;script&gt;')
                && ! str_contains($rendered, '<script>alert(');
        });
    }

    public function test_duplicate_admin_email_is_rejected_without_creating_hospital(): void
    {
        User::factory()->create(['email' => 'existing@nairobi-medical.test']);
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)
            ->post(route('superadmin.hospitals.store'), $this->hospitalPayload([
                'admin_email' => 'existing@nairobi-medical.test',
            ]))
            ->assertSessionHasErrors('admin_email');

        $this->assertDatabaseMissing('hospitals', ['slug' => 'nairobi-medical-centre']);
    }

    public function test_short_admin_password_is_rejected_without_creating_hospital(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)
            ->post(route('superadmin.hospitals.store'), $this->hospitalPayload([
                'admin_password' => 'short',
                'admin_password_confirmation' => 'short',
            ]))
            ->assertSessionHasErrors('admin_password');

        $this->assertDatabaseMissing('hospitals', ['slug' => 'nairobi-medical-centre']);
    }

    public function test_mismatched_admin_password_confirmation_is_rejected_without_creating_hospital(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)
            ->post(route('superadmin.hospitals.store'), $this->hospitalPayload([
                'admin_password_confirmation' => 'DifferentPass!2026',
            ]))
            ->assertSessionHasErrors('admin_password');

        $this->assertDatabaseMissing('hospitals', ['slug' => 'nairobi-medical-centre']);
    }

    public function test_admin_creation_failure_rolls_back_hospital_creation(): void
    {
        Mail::fake();
        $superAdmin = $this->superAdmin();
        User::creating(function (User $user): void {
            if ($user->email === 'admin@nairobi-medical.test') {
                throw new RuntimeException('Admin creation failed.');
            }
        });
        $response = $this->actingAs($superAdmin)
            ->post(route('superadmin.hospitals.store'), $this->hospitalPayload());

        $response->assertServerError();
        $this->assertDatabaseMissing('hospitals', ['slug' => 'nairobi-medical-centre']);
        Mail::assertNothingQueued();
    }

    public function test_existing_hospital_invitation_routes_remain_registered(): void
    {
        $this->assertTrue(Route::has('hospital.invitation.setup'));
        $this->assertTrue(Route::has('hospital.invitation.store'));
    }

    private function superAdmin(): User
    {
        $superAdmin = User::factory()->create([
            'is_super_admin' => true,
            'role' => 'super_admin',
        ]);
        $superAdmin->syncRolesFromFlags();

        return $superAdmin;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function hospitalPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nairobi Medical Centre',
            'slug' => 'nairobi-medical-centre',
            'phone' => '+254711000000',
            'address' => 'Upper Hill, Nairobi',
            'deposit_amount' => 500,
            'slot_duration_minutes' => 30,
            'no_show_grace_minutes' => 30,
            'hours_emergency' => '24/7',
            'hours_outpatient' => '8:00 AM - 6:00 PM',
            'default_language' => 'en',
            'supported_languages' => ['en', 'sw'],
            'subscription_plan' => 'professional',
            'trial_days' => 14,
            'admin_name' => 'Nairobi Admin',
            'admin_email' => 'admin@nairobi-medical.test',
            'admin_password' => 'SecureStart!2026',
            'admin_password_confirmation' => 'SecureStart!2026',
        ], $overrides);
    }
}
