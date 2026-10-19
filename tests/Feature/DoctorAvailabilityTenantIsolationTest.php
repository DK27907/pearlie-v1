<?php

namespace Tests\Feature;

use App\Models\DoctorAvailability;
use App\Models\Hospital;
use App\Models\User;
use App\Services\DoctorAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoctorAvailabilityTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_discovery_does_not_return_doctors_from_another_hospital(): void
    {
        $pearl = Hospital::factory()->create(['slug' => 'pearl-scope-test']);
        $demo = Hospital::factory()->create(['slug' => 'demo-scope-test']);
        $pearlDoctor = User::factory()->create([
            'hospital_id' => $pearl->id,
            'name' => 'Pearl Doctor Scoped',
            'is_doctor' => true,
            'role' => 'doctor',
        ]);
        $demoDoctor = User::factory()->create([
            'hospital_id' => $demo->id,
            'name' => 'Demo Doctor Leaked',
            'is_doctor' => true,
            'role' => 'doctor',
        ]);
        $availability = app(DoctorAvailabilityService::class);
        $date = '2030-01-01';
        $dayOfWeek = now()->parse($date)->dayOfWeek;

        app()->instance('currentHospital', $pearl);
        DoctorAvailability::query()->create([
            'doctor_id' => $pearlDoctor->id,
            'day_of_week' => $dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration_minutes' => 30,
            'max_patients_per_slot' => 1,
            'is_active' => true,
        ]);

        app()->instance('currentHospital', $demo);
        DoctorAvailability::query()->create([
            'doctor_id' => $demoDoctor->id,
            'day_of_week' => $dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration_minutes' => 30,
            'max_patients_per_slot' => 1,
            'is_active' => true,
        ]);

        app()->instance('currentHospital', $pearl);
        $pearlDoctors = collect($availability->getAllDoctorsWithSlots($pearl, $date))
            ->pluck('doctor.id')
            ->all();
        $preferredPearlDoctor = $availability->findDoctorForSlot(
            $pearl,
            $date,
            '09:00',
            $demoDoctor->id,
        );

        app()->instance('currentHospital', $demo);
        $demoDoctors = collect($availability->getAllDoctorsWithSlots($demo, $date))
            ->pluck('doctor.id')
            ->all();

        $this->assertContains($pearlDoctor->id, $pearlDoctors);
        $this->assertNotContains($demoDoctor->id, $pearlDoctors);
        $this->assertSame($pearlDoctor->id, $preferredPearlDoctor?->id);
        $this->assertContains($demoDoctor->id, $demoDoctors);
        $this->assertNotContains($pearlDoctor->id, $demoDoctors);
    }
}
