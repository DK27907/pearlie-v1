<?php

namespace Tests\Feature;

use App\Models\DoctorAvailability;
use App\Models\Hospital;
use App\Models\User;
use App\Services\PearlieService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoctorProfessionalProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_patients_see_doctor_professional_details_on_hospital_page_and_during_booking(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-28 08:00'));
        $hospital = Hospital::query()->create([
            'name' => 'Profile Clinic',
            'slug' => 'profile-clinic',
            'subscription_plan' => 'professional',
            'subscription_status' => 'active',
            'is_active' => true,
            'deposit_amount' => 500,
            'slot_duration_minutes' => 30,
            'no_show_grace_minutes' => 30,
            'default_language' => 'en',
            'supported_languages' => ['en', 'sw'],
        ]);
        app()->instance('currentHospital', $hospital);

        $doctor = User::factory()->create([
            'hospital_id' => $hospital->id,
            'name' => 'Dr. John Kamau',
            'is_doctor' => true,
            'role' => 'doctor',
            'specialization' => 'General Medicine',
            'bio' => 'Focused on preventive and family medicine.',
            'consultation_fee' => '2500.00',
            'licence_number' => 'KMPDC-A12345',
        ]);
        DoctorAvailability::query()->create([
            'doctor_id' => $doctor->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'slot_duration_minutes' => 30,
            'max_patients_per_slot' => 1,
            'is_active' => true,
        ]);

        $otherHospital = Hospital::query()->create([
            'name' => 'Other Clinic',
            'slug' => 'other-profile-clinic',
            'subscription_plan' => 'professional',
            'subscription_status' => 'active',
            'is_active' => true,
        ]);
        app()->instance('currentHospital', $otherHospital);
        $otherDoctor = User::factory()->create([
            'hospital_id' => $otherHospital->id,
            'name' => 'Dr. Other Tenant',
            'is_doctor' => true,
            'role' => 'doctor',
            'specialization' => 'Other specialty',
            'bio' => 'Confidential other hospital bio.',
            'consultation_fee' => '8000.00',
            'licence_number' => 'OTHER-TENANT-LICENCE',
        ]);
        DoctorAvailability::query()->create([
            'doctor_id' => $otherDoctor->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'slot_duration_minutes' => 30,
            'max_patients_per_slot' => 1,
            'is_active' => true,
        ]);
        app()->instance('currentHospital', $hospital);

        $this->get(route('tenant.home', $hospital->slug))
            ->assertSee('Focused on preventive and family medicine.')
            ->assertSee('Consultation fee:')
            ->assertSee('KSh 2,500.00')
            ->assertSee('KMPDC-A12345')
            ->assertDontSee('Confidential other hospital bio.')
            ->assertDontSee('OTHER-TENANT-LICENCE');

        $assistant = app(PearlieService::class);
        $sessionId = 'doctor-profile-booking-session';
        $assistant->processMessage('I want to book with Dr. Kamau', $sessionId);
        $assistant->processMessage('Jane Doe', $sessionId);
        $assistant->processMessage('0712345678', $sessionId);
        $assistant->processMessage('General consultation', $sessionId);
        $availabilityReply = $assistant->processMessage('2026-09-28', $sessionId);

        $this->assertStringContainsString('Focused on preventive and family medicine.', $availabilityReply['response']);
        $this->assertStringNotContainsString('Consultation fee', $availabilityReply['response']);
        $this->assertStringNotContainsString('KSh', $availabilityReply['response']);
        $this->assertStringContainsString('Licence: KMPDC-A12345', $availabilityReply['response']);
        $this->assertStringNotContainsString('Confidential other hospital bio.', $availabilityReply['response']);
        $this->assertStringNotContainsString('OTHER-TENANT-LICENCE', $availabilityReply['response']);
    }
}
