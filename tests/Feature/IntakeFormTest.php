<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_confirmed_appointment_intake_form_loads_with_its_one_time_token(): void
    {
        [, $appointment] = $this->createConfirmedAppointment();
        $token = $appointment->intake_token;

        $this->get(route('intake.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('Pre-visit intake form')
            ->assertSee('Allergies')
            ->assertSee('Current medications')
            ->assertSee('Symptoms / reason for visit')
            ->assertSee('Emergency contact name')
            ->assertSee('Emergency contact phone');
    }

    public function test_submitting_the_intake_form_saves_encrypted_data_and_consumes_the_token(): void
    {
        [, $appointment] = $this->createConfirmedAppointment();
        $token = $appointment->intake_token;
        $payload = [
            'allergies' => 'Penicillin',
            'medications' => 'Metformin 500mg',
            'symptoms' => 'Recurring headaches for 3 weeks',
            'emergency_contact_name' => 'Jane Doe',
            'emergency_contact_phone' => '+254 700 000 001',
        ];

        $this->post(route('intake.submit', ['token' => $token]), $payload)
            ->assertRedirect(route('intake.thank-you', ['appointment' => $appointment->id]));
        $this->get(route('intake.thank-you', ['appointment' => $appointment->id]))
            ->assertOk()
            ->assertSee('Thanks! Your doctor will see this before your visit.');

        $savedAppointment = $appointment->fresh();
        $this->assertSame($payload, $savedAppointment->intake_data);
        $this->assertNotNull($savedAppointment->intake_completed_at);
        $this->assertNull($savedAppointment->intake_token);
        $this->get(route('intake.show', ['token' => $token]))->assertNotFound();
    }

    public function test_doctor_and_admin_appointment_views_show_completed_intake_data(): void
    {
        [$hospital, $appointment, $doctor] = $this->createConfirmedAppointment();
        $appointment->forceFill([
            'intake_data' => [
                'allergies' => 'Penicillin',
                'medications' => 'Metformin 500mg',
                'symptoms' => 'Recurring headaches for 3 weeks',
                'emergency_contact_name' => 'Jane Doe',
                'emergency_contact_phone' => '+254 700 000 001',
            ],
            'intake_completed_at' => now(),
        ])->save();
        $admin = User::factory()->for($hospital, 'hospital')->create([
            'role' => 'hospital_admin',
            'is_admin' => true,
        ]);

        $this->actingAs($doctor)
            ->get(route('doctor.appointments.show', $appointment->id))
            ->assertOk()
            ->assertSee('Patient Intake')
            ->assertSee('Penicillin')
            ->assertSee('Metformin 500mg')
            ->assertSee('Recurring headaches for 3 weeks')
            ->assertSee('Jane Doe, +254 700 000 001');

        $this->actingAs($admin)
            ->get(route('admin.appointments.show', $appointment->id))
            ->assertOk()
            ->assertSee('Patient Intake')
            ->assertSee('Penicillin')
            ->assertSee('Metformin 500mg')
            ->assertSee('Recurring headaches for 3 weeks');
    }

    public function test_doctor_view_explains_when_patient_has_not_completed_intake(): void
    {
        [, $appointment, $doctor] = $this->createConfirmedAppointment();

        $this->actingAs($doctor)
            ->get(route('doctor.appointments.show', $appointment->id))
            ->assertOk()
            ->assertSee('Patient has not completed the intake form.');
    }

    private function createConfirmedAppointment(): array
    {
        $hospital = Hospital::factory()->create([
            'subscription_plan' => 'professional',
            'subscription_status' => 'active',
            'is_active' => true,
        ]);
        app()->instance('currentHospital', $hospital);
        $doctor = User::factory()->for($hospital, 'hospital')->create([
            'role' => 'doctor',
            'is_doctor' => true,
            'is_active' => true,
        ]);
        $appointment = AppointmentRequest::query()->create([
            'hospital_id' => $hospital->id,
            'session_id' => 'intake-'.str()->uuid(),
            'name' => 'Intake Patient',
            'phone' => '254700000000',
            'preferred_date' => today()->addDays(3),
            'slot_start_time' => '09:00',
            'slot_end_time' => '09:30',
            'doctor_id' => $doctor->id,
            'reason' => 'Consultation',
            'raw_message' => 'Book an appointment',
            'status' => AppointmentRequest::STATUS_CONFIRMED,
            'payment_status' => 'unpaid',
        ]);

        return [$hospital, $appointment, $doctor];
    }
}
