<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_doctor_can_update_professional_profile_information(): void
    {
        $doctor = User::factory()->create([
            'is_doctor' => true,
            'role' => 'doctor',
            'specialization' => 'General Medicine',
        ]);

        $this->actingAs($doctor)->patch('/profile', [
            'name' => $doctor->name,
            'email' => $doctor->email,
            'specialization' => 'Orthodontics',
            'bio' => 'Provides family-focused orthodontic care.',
            'consultation_fee' => '3500.00',
            'licence_number' => 'KMPDC-B98765',
        ])->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertDatabaseHas('users', [
            'id' => $doctor->id,
            'specialization' => 'Orthodontics',
            'bio' => 'Provides family-focused orthodontic care.',
            'consultation_fee' => '3500.00',
            'licence_number' => 'KMPDC-B98765',
        ]);
    }

    public function test_non_doctor_cannot_update_doctor_professional_profile_fields(): void
    {
        $patient = User::factory()->create([
            'specialization' => 'Patient value',
            'bio' => 'Patient bio',
            'consultation_fee' => '0.00',
            'licence_number' => 'Patient licence',
        ]);

        $this->actingAs($patient)->patch('/profile', [
            'name' => $patient->name,
            'email' => $patient->email,
            'specialization' => 'Injected specialty',
            'bio' => 'Injected bio',
            'consultation_fee' => '999999.99',
            'licence_number' => 'Injected licence',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'id' => $patient->id,
            'specialization' => 'Patient value',
            'bio' => 'Patient bio',
            'consultation_fee' => '0.00',
            'licence_number' => 'Patient licence',
        ]);
    }

    public function test_doctor_cannot_save_a_negative_consultation_fee(): void
    {
        $doctor = User::factory()->create([
            'is_doctor' => true,
            'role' => 'doctor',
            'specialization' => 'General Medicine',
            'consultation_fee' => '1500.00',
        ]);

        $this->actingAs($doctor)->from('/profile')->patch('/profile', [
            'name' => $doctor->name,
            'email' => $doctor->email,
            'specialization' => 'General Medicine',
            'consultation_fee' => '-1',
        ])->assertSessionHasErrors('consultation_fee');

        $this->assertSame('1500.00', $doctor->refresh()->consultation_fee);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }
}
