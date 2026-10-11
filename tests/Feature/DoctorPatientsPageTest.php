<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoctorPatientsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_doctor_can_view_own_patient_list(): void
    {
        $hospital = $this->pearlHospital();
        app()->instance('currentHospital', $hospital);
        $doctor = $this->createDoctor($hospital);
        $this->createAppointment($hospital, $doctor, [
            'name' => 'Patient Alpha',
            'phone' => '254700000101',
            'preferred_date' => '2026-09-01',
            'payment_status' => 'paid',
            'payment_amount' => 1250,
        ]);
        $this->createAppointment($hospital, $doctor, [
            'name' => 'Patient Alpha',
            'phone' => '254700000101',
            'preferred_date' => '2026-10-08',
            'payment_status' => 'paid',
            'payment_amount' => 500,
        ]);

        $this->actingAs($doctor)
            ->get(route('doctor.patients.index'))
            ->assertOk()
            ->assertSee('Patient Alpha')
            ->assertSee('254700000101')
            ->assertSee('2')
            ->assertSee('KSh 1,750.00');
    }

    public function test_doctor_sees_only_patients_who_booked_with_them(): void
    {
        $hospital = $this->pearlHospital();
        app()->instance('currentHospital', $hospital);
        $doctor = $this->createDoctor($hospital);
        $otherDoctor = $this->createDoctor($hospital);
        $this->createAppointment($hospital, $doctor, ['name' => 'Doctor One Patient']);
        $this->createAppointment($hospital, $otherDoctor, ['name' => 'Doctor Two Patient']);

        $this->actingAs($doctor)
            ->get(route('doctor.patients.index'))
            ->assertOk()
            ->assertSee('Doctor One Patient')
            ->assertDontSee('Doctor Two Patient');
    }

    public function test_doctor_groups_bookings_by_patient_id_when_available(): void
    {
        $hospital = $this->pearlHospital();
        app()->instance('currentHospital', $hospital);
        $doctor = $this->createDoctor($hospital);
        $patient = User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'patient',
            'name' => 'Account Patient',
            'phone' => '254700000111',
        ]);
        $this->createAppointment($hospital, $doctor, [
            'patient_id' => $patient->id,
            'name' => 'Old Booking Name',
            'phone' => '254700000112',
            'preferred_date' => '2026-09-01',
        ]);
        $this->createAppointment($hospital, $doctor, [
            'patient_id' => $patient->id,
            'name' => 'New Booking Name',
            'phone' => '254700000113',
            'preferred_date' => '2026-10-08',
        ]);

        $this->actingAs($doctor)
            ->get(route('doctor.patients.index'))
            ->assertOk()
            ->assertSee('Account Patient')
            ->assertSee('254700000111')
            ->assertSee('2');
    }

    public function test_doctor_sees_empty_state_without_patient_bookings(): void
    {
        $hospital = $this->pearlHospital();
        app()->instance('currentHospital', $hospital);
        $doctor = $this->createDoctor($hospital);

        $this->actingAs($doctor)
            ->get(route('doctor.patients.index'))
            ->assertOk()
            ->assertSee('No patients have booked with you yet');
    }

    public function test_guest_is_redirected_to_login_from_doctor_patients(): void
    {
        $this->get(route('doctor.patients.index'))
            ->assertRedirect(route('login'));
    }

    private function pearlHospital(): Hospital
    {
        return Hospital::query()->where('slug', 'pearl')->firstOrFail();
    }

    private function createDoctor(Hospital $hospital): User
    {
        return User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_doctor' => true,
            'role' => 'doctor',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createAppointment(Hospital $hospital, User $doctor, array $attributes = []): AppointmentRequest
    {
        return AppointmentRequest::factory()->create(array_merge([
            'hospital_id' => $hospital->id,
            'doctor_id' => $doctor->id,
            'session_id' => 'patient-session-'.fake()->uuid(),
            'name' => 'Listed Patient',
            'phone' => '254700000100',
            'preferred_date' => '2026-10-06',
            'payment_status' => 'pending',
        ], $attributes));
    }
}
