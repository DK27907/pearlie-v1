<?php

namespace Tests\Feature\Admin;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_onboarding_marks_mpesa_complete_with_valid_sandbox_credentials(): void
    {
        $hospital = Hospital::factory()->create(['subscription_plan' => 'enterprise']);
        $hospital->integrationCredentials()->create([
            'provider' => 'mpesa',
            'credentials' => [
                'consumer_key' => 'sandbox-key',
                'consumer_secret' => 'sandbox-secret',
                'passkey' => 'sandbox-passkey',
                'shortcode' => '174379',
                'callback_url' => 'https://example.test/api/mpesa/callback',
                'environment' => 'sandbox',
            ],
            'is_active' => true,
        ]);

        $this->actingAs($this->createHospitalAdmin($hospital))
            ->get(route('hospital.onboarding'))
            ->assertOk()
            ->assertSeeInOrder(['data-checklist="payments"', 'M-Pesa', 'Complete'], false);
    }

    public function test_onboarding_needs_attention_when_mpesa_is_required_without_credentials(): void
    {
        $hospital = Hospital::factory()->create(['subscription_plan' => 'enterprise']);

        $this->actingAs($this->createHospitalAdmin($hospital))
            ->get(route('hospital.onboarding'))
            ->assertOk()
            ->assertSeeInOrder(['data-checklist="payments"', 'M-Pesa', 'Needs attention'], false);
    }

    public function test_onboarding_marks_mpesa_complete_when_subscription_does_not_include_it(): void
    {
        $hospital = Hospital::factory()->create(['subscription_plan' => 'professional']);

        $this->actingAs($this->createHospitalAdmin($hospital))
            ->get(route('hospital.onboarding'))
            ->assertOk()
            ->assertSeeInOrder(['data-checklist="payments"', 'M-Pesa', 'Complete'], false);
    }

    private function createHospitalAdmin(Hospital $hospital): User
    {
        return User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
    }
}
