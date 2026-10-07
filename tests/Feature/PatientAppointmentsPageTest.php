<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\MpesaPayment;
use App\Models\PaymentEvent;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientAppointmentsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_patient_can_view_own_appointments(): void
    {
        $hospital = $this->pearlHospital();
        app()->instance('currentHospital', $hospital);
        $patient = $this->createPatient($hospital);
        $doctor = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_doctor' => true,
            'name' => 'Dr. Visible Patient Doctor',
        ]);
        $service = Service::factory()->create([
            'hospital_id' => $hospital->id,
            'name' => 'Visible Patient Service',
        ]);
        $appointment = $this->createAppointment($hospital, $patient, [
            'doctor_id' => $doctor->id,
            'service_id' => $service->id,
        ]);
        $otherPatient = $this->createPatient($hospital);
        $this->createAppointment($hospital, $otherPatient, [
            'reason' => 'Other Patient Unique Reason',
        ]);

        $this->actingAs($patient)
            ->get(route('appointments.index'))
            ->assertOk()
            ->assertSee('Dr. Visible Patient Doctor')
            ->assertSee('Visible Patient Service')
            ->assertSee('Oct 6, 2026')
            ->assertDontSee('Other Patient Unique Reason')
            ->assertSee(route('appointments.show', $appointment));
    }

    public function test_patient_can_view_own_appointment_detail_with_payment_history(): void
    {
        $hospital = $this->pearlHospital();
        app()->instance('currentHospital', $hospital);
        $patient = $this->createPatient($hospital);
        $doctor = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_doctor' => true,
            'specialization' => 'Dentist',
            'bio' => 'Dental care profile.',
        ]);
        $service = Service::factory()->create([
            'hospital_id' => $hospital->id,
            'name' => 'Dental Cleaning',
            'price' => 2500,
        ]);
        $appointment = $this->createAppointment($hospital, $patient, [
            'doctor_id' => $doctor->id,
            'service_id' => $service->id,
            'payment_amount' => 2500,
            'payment_status' => 'paid',
            'mpesa_receipt' => 'TEST-RECEIPT-1',
        ]);
        $payment = MpesaPayment::factory()->create([
            'hospital_id' => $hospital->id,
            'appointment_request_id' => $appointment->id,
            'status' => MpesaPayment::STATUS_COMPLETED,
            'amount' => 2500,
            'mpesa_receipt' => 'TEST-RECEIPT-1',
        ]);
        PaymentEvent::query()->create([
            'hospital_id' => $hospital->id,
            'payment_id' => $payment->id,
            'event' => 'payment_completed',
            'payload' => ['receipt' => 'TEST-RECEIPT-1'],
        ]);

        $this->actingAs($patient)
            ->get(route('appointments.show', $appointment))
            ->assertOk()
            ->assertSee('Dental care profile.')
            ->assertSee('Dental Cleaning')
            ->assertSee('KSh 2,500.00')
            ->assertSee('TEST-RECEIPT-1')
            ->assertSee('Payment status history')
            ->assertSee('payment completed');
    }

    public function test_patient_cannot_view_another_patients_appointment_detail(): void
    {
        $hospital = $this->pearlHospital();
        app()->instance('currentHospital', $hospital);
        $patient = $this->createPatient($hospital);
        $otherPatient = $this->createPatient($hospital);
        $appointment = $this->createAppointment($hospital, $otherPatient);

        $this->actingAs($patient)
            ->get(route('appointments.show', $appointment))
            ->assertNotFound();
    }

    public function test_guest_is_redirected_to_login_from_appointments(): void
    {
        $this->get(route('appointments.index'))
            ->assertRedirect(route('login'));
    }

    private function pearlHospital(): Hospital
    {
        return Hospital::query()->where('slug', 'pearl')->firstOrFail();
    }

    private function createPatient(Hospital $hospital): User
    {
        return User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'patient',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createAppointment(Hospital $hospital, User $patient, array $attributes = []): AppointmentRequest
    {
        return AppointmentRequest::factory()->for($patient, 'patient')->create(array_merge([
            'hospital_id' => $hospital->id,
            'name' => 'Patient Booking',
            'preferred_date' => '2026-10-06',
            'status' => AppointmentRequest::STATUS_PENDING,
            'booking_fee' => 500,
        ], $attributes));
    }
}
