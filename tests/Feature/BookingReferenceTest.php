<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_appointment_references_are_generated_and_unique(): void
    {
        $hospital = Hospital::factory()->create();
        app()->instance('currentHospital', $hospital);

        $first = AppointmentRequest::factory()->create(['hospital_id' => $hospital->id]);
        $second = AppointmentRequest::factory()->create(['hospital_id' => $hospital->id]);

        $this->assertMatchesRegularExpression('/^BK-[A-F0-9]{32}$/', $first->booking_reference);
        $this->assertNotSame($first->booking_reference, $second->booking_reference);
        $this->assertDatabaseHas('appointment_requests', [
            'id' => $first->id,
            'booking_reference' => $first->booking_reference,
        ]);
    }

    public function test_public_booking_lookup_returns_no_patient_identifiers(): void
    {
        $hospital = Hospital::factory()->create([
            'name' => 'Public Lookup Hospital',
            'address' => '10 Care Street',
        ]);
        app()->instance('currentHospital', $hospital);
        $service = Service::factory()->create([
            'hospital_id' => $hospital->id,
            'name' => 'Dental Cleaning',
        ]);
        $doctor = User::factory()->create([
            'hospital_id' => $hospital->id,
            'name' => 'Dr. Example',
            'is_doctor' => true,
        ]);
        $appointment = AppointmentRequest::factory()->create([
            'hospital_id' => $hospital->id,
            'service_id' => $service->id,
            'doctor_id' => $doctor->id,
            'name' => 'Sensitive Patient Name',
            'phone' => '254712345678',
            'email' => 'patient@example.test',
            'preferred_date' => '2026-11-01',
            'slot_start_time' => '09:30:00',
            'status' => AppointmentRequest::STATUS_CONFIRMED,
        ]);

        $response = $this->getJson('/api/bookings/lookup/'.$appointment->booking_reference);

        $response->assertOk()
            ->assertJson([
                'booking_reference' => $appointment->booking_reference,
                'status' => AppointmentRequest::STATUS_CONFIRMED,
                'service' => 'Dental Cleaning',
                'doctor' => 'Dr. Example',
                'date' => '2026-11-01',
                'time' => '09:30',
                'hospital' => [
                    'name' => 'Public Lookup Hospital',
                    'address' => '10 Care Street',
                ],
            ]);

        $this->assertStringNotContainsString('Sensitive Patient Name', $response->getContent());
        $this->assertStringNotContainsString('254712345678', $response->getContent());
        $this->assertStringNotContainsString('patient@example.test', $response->getContent());
    }

    public function test_public_booking_lookup_does_not_disclose_nonexistent_references(): void
    {
        $this->getJson('/api/bookings/lookup/BK-'.str_repeat('A', 32))
            ->assertNotFound()
            ->assertJson(['message' => 'Booking not found.']);
    }
}
