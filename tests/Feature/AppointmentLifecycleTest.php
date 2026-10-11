<?php

namespace Tests\Feature;

use App\Exceptions\AppointmentLifecycleException;
use App\Jobs\SendAppointmentLifecycleNotifications;
use App\Models\AppointmentHistory;
use App\Models\AppointmentRequest;
use App\Models\Conversation;
use App\Models\DoctorAvailability;
use App\Models\Hospital;
use App\Models\MpesaPayment;
use App\Models\Service;
use App\Models\SlotHold;
use App\Models\User;
use App\Services\AppointmentLifecycleService;
use App\Services\BookingChatService;
use App\Services\DoctorAvailabilityService;
use App\Services\PaymentLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AppointmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 08:00:00'));
    }

    public function test_patient_can_cancel_their_appointment_and_slot_hold_is_released(): void
    {
        [$hospital, $patient, $appointment] = $this->appointmentContext([
            'preferred_date' => '2026-10-10',
            'payment_status' => 'paid',
            'payment_amount' => 500,
        ]);
        $payment = $this->createPayment($hospital, $appointment, MpesaPayment::STATUS_COMPLETED);
        $hold = $this->createHold($hospital, $appointment);
        Queue::fake();

        $this->actingAs($patient)
            ->post(route('appointments.cancel', $appointment->id), ['reason' => 'Plans changed'])
            ->assertRedirect(route('appointments.show', $appointment->id))
            ->assertSessionHas('status', 'Appointment cancelled. Any refundable balance is pending manual payment review.');

        $this->assertSame(AppointmentRequest::STATUS_CANCELLED, $appointment->fresh()->status);
        $this->assertSame('Plans changed', $appointment->fresh()->cancellation_reason);
        $this->assertSame('0.00', $appointment->fresh()->forfeited_amount);
        $this->assertDatabaseMissing('slot_holds', ['id' => $hold->id]);
        $this->assertDatabaseHas('appointment_histories', [
            'appointment_request_id' => $appointment->id,
            'hospital_id' => $hospital->id,
            'action' => 'cancelled',
        ]);
        $this->assertSame(MpesaPayment::STATUS_COMPLETED, $payment->fresh()->status);
        Queue::assertPushed(SendAppointmentLifecycleNotifications::class, 1);
    }

    public function test_cancellation_forms_use_the_csp_safe_confirmation_hook_for_every_role(): void
    {
        [$hospital, $patient, $appointment, $doctor] = $this->appointmentContext([
            'preferred_date' => '2026-10-10',
        ]);
        $admin = User::factory()->for($hospital, 'hospital')->create([
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);

        $this->actingAs($patient)
            ->get(route('appointments.show', $appointment->id))
            ->assertSee('data-confirm="Cancel this appointment?"', false)
            ->assertDontSee('onsubmit="return confirm(', false);

        $this->actingAs($admin)
            ->get(route('admin.appointments.show', $appointment->id))
            ->assertSee('data-confirm="Cancel this appointment?"', false)
            ->assertDontSee('onsubmit="return confirm(', false);

        $this->actingAs($doctor)
            ->get(route('doctor.appointments.show', $appointment->id))
            ->assertSee('data-confirm="Cancel this appointment?"', false)
            ->assertDontSee('onsubmit="return confirm(', false);
    }

    public function test_patient_cannot_cancel_another_patients_appointment(): void
    {
        [$hospital, $patient, $appointment] = $this->appointmentContext([
            'preferred_date' => '2026-10-10',
        ]);
        $anotherPatient = $this->createPatient($hospital);
        $appointment->forceFill(['patient_id' => $anotherPatient->id])->save();

        $this->actingAs($patient)
            ->post(route('appointments.cancel', $appointment->id))
            ->assertNotFound();

        $this->assertSame(AppointmentRequest::STATUS_PENDING, $appointment->fresh()->status);
    }

    public function test_lifecycle_service_rejects_a_patient_who_does_not_own_the_appointment(): void
    {
        [$hospital, $patient, $appointment] = $this->appointmentContext([
            'preferred_date' => '2026-10-10',
        ]);
        $otherPatient = $this->createPatient($hospital);

        $this->expectException(AppointmentLifecycleException::class);
        app(AppointmentLifecycleService::class)->cancel(
            $appointment->id,
            $hospital->id,
            $otherPatient,
        );
    }

    public function test_late_cancellation_retains_only_the_configured_fee_and_records_refund_pending(): void
    {
        [$hospital, $patient, $appointment] = $this->appointmentContext([
            'preferred_date' => '2026-10-02',
            'slot_start_time' => '09:00',
            'payment_status' => 'paid',
            'payment_amount' => 800,
        ]);
        $hospital->forceFill([
            'cancellation_cutoff_hours' => 24,
            'late_cancellation_fee' => 300,
            'deposits_refundable' => true,
        ])->save();
        $this->createPayment($hospital, $appointment, MpesaPayment::STATUS_COMPLETED, 800);
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00'));

        $response = app(AppointmentLifecycleService::class)->cancel(
            $appointment->id,
            $hospital->id,
            $patient,
            reason: 'Late cancellation',
        );

        $this->assertSame(300.0, $response->forfeitedAmount);
        $this->assertSame('refund_pending', $response->paymentOutcome);
        $this->assertSame('300.00', $appointment->fresh()->forfeited_amount);
        $history = AppointmentHistory::query()->where('appointment_request_id', $appointment->id)->firstOrFail();
        $this->assertTrue($history->metadata['late_cancellation']);
        $this->assertSame('refund_pending', $history->metadata['payment_outcome']);
    }

    public function test_second_cancellation_attempt_does_not_create_duplicate_history(): void
    {
        [$hospital, $patient, $appointment] = $this->appointmentContext([
            'preferred_date' => '2026-10-10',
        ]);
        Queue::fake();
        $lifecycle = app(AppointmentLifecycleService::class);

        $lifecycle->cancel($appointment->id, $hospital->id, $patient);
        $this->expectException(AppointmentLifecycleException::class);
        $lifecycle->cancel($appointment->id, $hospital->id, $patient);

        $this->assertSame(1, AppointmentHistory::query()
            ->where('appointment_request_id', $appointment->id)
            ->where('action', 'cancelled')
            ->count());
    }

    public function test_cancellation_wins_a_race_with_a_pending_mpesa_callback(): void
    {
        [$hospital, $patient, $appointment] = $this->appointmentContext([
            'preferred_date' => '2026-10-10',
            'payment_status' => 'pending',
        ]);
        $payment = $this->createPayment($hospital, $appointment, MpesaPayment::STATUS_PENDING);
        Queue::fake();

        app(AppointmentLifecycleService::class)->cancel($appointment->id, $hospital->id, $patient);

        $accepted = app(PaymentLifecycleService::class)->markCompleted($payment, [
            'result_code' => 0,
            'mpesa_receipt' => 'RACE-RECEIPT',
        ]);

        $this->assertFalse($accepted);
        $this->assertSame(MpesaPayment::STATUS_CANCELLED, $payment->fresh()->status);
        $this->assertSame(AppointmentRequest::STATUS_CANCELLED, $appointment->fresh()->status);
        $this->assertNull($appointment->fresh()->paid_at);
    }

    public function test_tenant_context_mismatch_cannot_change_an_appointment(): void
    {
        [$hospital, $patient, $appointment] = $this->appointmentContext([
            'preferred_date' => '2026-10-10',
        ]);
        app()->instance('currentHospital', $this->createHospital(['slug' => 'other-tenant']));

        $this->expectException(AppointmentLifecycleException::class);
        app(AppointmentLifecycleService::class)->cancel($appointment->id, $hospital->id, $patient);
    }

    public function test_patient_can_reschedule_and_the_new_slot_is_booked_transactionally(): void
    {
        [$hospital, $patient, $appointment, $doctor] = $this->appointmentContext([
            'preferred_date' => '2026-10-10',
            'payment_status' => 'paid',
            'payment_amount' => 500,
        ]);
        $availability = DoctorAvailability::factory()->for($doctor, 'doctor')->create([
            'hospital_id' => $hospital->id,
            'day_of_week' => CarbonImmutable::parse('2026-10-12')->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration_minutes' => 30,
            'max_patients_per_slot' => 1,
        ]);
        $oldHold = $this->createHold($hospital, $appointment);
        Queue::fake();

        $this->actingAs($patient)
            ->post(route('appointments.reschedule.store', $appointment->id), [
                'date' => '2026-10-12',
                'slot' => $doctor->id.'|09:00',
                'reason' => 'Need a different day',
            ])
            ->assertRedirect(route('appointments.show', $appointment->id))
            ->assertSessionHas('status', 'Appointment rescheduled successfully.');

        $this->assertSame('2026-10-12', $appointment->fresh()->preferred_date->format('Y-m-d'));
        $this->assertSame('09:00', $appointment->fresh()->slot_start_time);
        $this->assertDatabaseMissing('slot_holds', ['id' => $oldHold->id]);
        $this->assertDatabaseMissing('slot_holds', ['appointment_request_id' => $appointment->id]);
        $this->assertFalse(app(DoctorAvailabilityService::class)
            ->isSlotAvailable($doctor->id, '2026-10-12', '09:00'));
        $history = AppointmentHistory::query()
            ->where('appointment_request_id', $appointment->id)
            ->where('action', 'rescheduled')
            ->firstOrFail();
        $this->assertSame('2026-10-10', $history->old_date->format('Y-m-d'));
        $this->assertSame('2026-10-12', $history->new_date->format('Y-m-d'));
        $this->assertSame('09:00', $history->old_start_time);
        $this->assertSame('09:00', $history->new_start_time);
        $this->assertTrue($availability->exists);
    }

    public function test_patient_can_render_reschedule_page_when_available_slots_exist(): void
    {
        [$hospital, $patient, $appointment, $doctor] = $this->appointmentContext([
            'preferred_date' => '2026-10-10',
            'payment_status' => 'paid',
            'payment_amount' => 500,
        ]);
        DoctorAvailability::factory()->for($doctor, 'doctor')->create([
            'hospital_id' => $hospital->id,
            'day_of_week' => CarbonImmutable::parse('2026-10-12')->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration_minutes' => 30,
            'max_patients_per_slot' => 1,
        ]);

        $this->actingAs($patient)
            ->get(route('appointments.reschedule.create', [
                'id' => $appointment->id,
                'date' => '2026-10-12',
            ]))
            ->assertOk()
            ->assertSee('Choose a new doctor and time')
            ->assertSee('9:00 AM');
    }

    public function test_patient_reschedule_page_only_offers_doctors_matching_the_service_specialty(): void
    {
        [$hospital, $patient, $appointment, $doctor] = $this->appointmentContext([
            'preferred_date' => '2026-10-10',
            'payment_status' => 'paid',
            'payment_amount' => 500,
        ]);
        $doctor->forceFill([
            'name' => 'Eligible General Practitioner',
            'specialization' => 'General Practitioner',
        ])->save();
        $appointment->forceFill([
            'service_id' => Service::factory()->for($hospital)->create([
                'requires_specialty' => 'General Practitioner',
            ])->id,
        ])->save();
        $ineligibleDoctor = User::factory()->for($hospital, 'hospital')->create([
            'name' => 'Ineligible Dentist',
            'is_doctor' => true,
            'role' => 'doctor',
            'specialization' => 'Dentist',
        ]);
        foreach ([$doctor, $ineligibleDoctor] as $availableDoctor) {
            DoctorAvailability::factory()->for($availableDoctor, 'doctor')->create([
                'hospital_id' => $hospital->id,
                'day_of_week' => CarbonImmutable::parse('2026-10-12')->dayOfWeek,
                'start_time' => '09:00',
                'end_time' => '10:00',
                'slot_duration_minutes' => 30,
                'max_patients_per_slot' => 1,
            ]);
        }

        $this->actingAs($patient)
            ->get(route('appointments.reschedule.create', [
                'id' => $appointment->id,
                'date' => '2026-10-12',
            ]))
            ->assertOk()
            ->assertSee('Eligible General Practitioner')
            ->assertDontSee('Ineligible Dentist');
    }

    public function test_unpaid_reschedule_moves_the_ttl_slot_hold_to_the_new_slot(): void
    {
        [$hospital, $patient, $appointment, $doctor] = $this->appointmentContext([
            'preferred_date' => '2026-10-10',
            'payment_status' => 'pending',
        ]);
        DoctorAvailability::factory()->for($doctor, 'doctor')->create([
            'hospital_id' => $hospital->id,
            'day_of_week' => CarbonImmutable::parse('2026-10-12')->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration_minutes' => 30,
        ]);
        $oldHold = $this->createHold($hospital, $appointment);
        Queue::fake();

        app(AppointmentLifecycleService::class)->reschedule(
            $appointment->id,
            $hospital->id,
            $doctor->id,
            '2026-10-12',
            '09:00',
            $patient,
        );

        $this->assertDatabaseMissing('slot_holds', ['id' => $oldHold->id]);
        $newHold = SlotHold::query()
            ->where('hospital_id', $hospital->id)
            ->where('appointment_request_id', $appointment->id)
            ->firstOrFail();
        $this->assertSame('2026-10-12 09:00:00', $newHold->slot_start_at->toDateTimeString());
        $this->assertTrue($newHold->expires_at->equalTo(now()->addMinutes(5)));
    }

    public function test_rescheduling_rejects_a_slot_that_is_already_booked(): void
    {
        [$hospital, $patient, $appointment, $doctor] = $this->appointmentContext([
            'preferred_date' => '2026-10-10',
        ]);
        DoctorAvailability::factory()->for($doctor, 'doctor')->create([
            'hospital_id' => $hospital->id,
            'day_of_week' => CarbonImmutable::parse('2026-10-12')->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration_minutes' => 30,
        ]);
        AppointmentRequest::factory()->for($hospital)->for($doctor, 'doctor')->create([
            'preferred_date' => '2026-10-12',
            'slot_start_time' => '09:00',
            'status' => AppointmentRequest::STATUS_CONFIRMED,
        ]);

        $this->actingAs($patient)
            ->post(route('appointments.reschedule.store', $appointment->id), [
                'date' => '2026-10-12',
                'slot' => $doctor->id.'|09:00',
            ])
            ->assertSessionHasErrors('appointment');

        $this->assertSame('2026-10-10', $appointment->fresh()->preferred_date->format('Y-m-d'));
    }

    public function test_rescheduling_rejects_a_doctor_who_cannot_provide_the_service(): void
    {
        [$hospital, $patient, $appointment, $doctor] = $this->appointmentContext([
            'preferred_date' => '2026-10-10',
        ]);
        $service = $hospital->services()->create([
            'name' => 'Dental consultation',
            'requires_specialty' => 'Dentist',
            'price' => 500,
            'duration_minutes' => 30,
            'is_active' => true,
        ]);
        $appointment->forceFill(['service_id' => $service->id])->save();
        $doctor->forceFill(['specialization' => 'Cardiology'])->save();
        User::factory()->for($hospital, 'hospital')->create([
            'is_doctor' => true,
            'role' => 'doctor',
            'specialization' => 'Dentist',
            'is_active' => true,
        ]);
        DoctorAvailability::factory()->for($doctor, 'doctor')->create([
            'hospital_id' => $hospital->id,
            'day_of_week' => CarbonImmutable::parse('2026-10-12')->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration_minutes' => 30,
        ]);

        $this->expectException(AppointmentLifecycleException::class);
        app(AppointmentLifecycleService::class)->reschedule(
            $appointment->id,
            $hospital->id,
            $doctor->id,
            '2026-10-12',
            '09:00',
            $patient,
        );
    }

    public function test_rescheduling_keeps_the_existing_fallback_when_no_matching_specialist_exists(): void
    {
        [$hospital, $patient, $appointment, $doctor] = $this->appointmentContext([
            'preferred_date' => '2026-10-10',
        ]);
        $service = $hospital->services()->create([
            'name' => 'Dental consultation',
            'requires_specialty' => 'Dentist',
            'price' => 500,
            'duration_minutes' => 30,
            'is_active' => true,
        ]);
        $appointment->forceFill(['service_id' => $service->id])->save();
        DoctorAvailability::factory()->for($doctor, 'doctor')->create([
            'hospital_id' => $hospital->id,
            'day_of_week' => CarbonImmutable::parse('2026-10-12')->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration_minutes' => 30,
        ]);
        Queue::fake();

        app(AppointmentLifecycleService::class)->reschedule(
            $appointment->id,
            $hospital->id,
            $doctor->id,
            '2026-10-12',
            '09:00',
            $patient,
        );

        $this->assertSame('2026-10-12', $appointment->fresh()->preferred_date->format('Y-m-d'));
        $this->assertSame($doctor->id, $appointment->fresh()->doctor_id);
    }

    public function test_rescheduling_is_blocked_inside_the_hospital_cutoff(): void
    {
        [$hospital, $patient, $appointment, $doctor] = $this->appointmentContext([
            'preferred_date' => '2026-10-02',
            'slot_start_time' => '09:00',
        ]);
        $hospital->forceFill(['rescheduling_cutoff_hours' => 24])->save();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00'));

        $this->expectException(AppointmentLifecycleException::class);
        app(AppointmentLifecycleService::class)->reschedule(
            $appointment->id,
            $hospital->id,
            $doctor->id,
            '2026-10-12',
            '09:00',
            $patient,
        );
    }

    public function test_hospital_admin_can_override_patient_policy_and_change_appointment(): void
    {
        [$hospital, , $appointment] = $this->appointmentContext([
            'preferred_date' => '2026-10-10',
        ]);
        $hospital->forceFill([
            'patient_cancellation_enabled' => false,
            'patient_rescheduling_enabled' => false,
        ])->save();
        $admin = User::factory()->for($hospital, 'hospital')->create([
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
        Queue::fake();

        $this->actingAs($admin)
            ->post(route('admin.appointments.cancel', $appointment->id), ['reason' => 'Staff override'])
            ->assertRedirect(route('admin.appointments.show', $appointment->id))
            ->assertSessionHas('status', 'Appointment cancelled by hospital staff.');

        $this->assertSame(AppointmentRequest::STATUS_CANCELLED, $appointment->fresh()->status);
        $this->assertDatabaseHas('appointment_histories', [
            'appointment_request_id' => $appointment->id,
            'actor_id' => $admin->id,
            'actor_role' => 'hospital_admin',
            'action' => 'cancelled',
        ]);
    }

    public function test_hospital_admin_can_reschedule_to_another_doctor_slot(): void
    {
        [$hospital, , $appointment, $oldDoctor] = $this->appointmentContext([
            'preferred_date' => '2026-10-10',
        ]);
        $admin = User::factory()->for($hospital, 'hospital')->create([
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
        $newDoctor = User::factory()->for($hospital, 'hospital')->create([
            'is_doctor' => true,
            'role' => 'doctor',
            'is_active' => true,
        ]);
        DoctorAvailability::factory()->for($newDoctor, 'doctor')->create([
            'hospital_id' => $hospital->id,
            'day_of_week' => CarbonImmutable::parse('2026-10-12')->dayOfWeek,
            'start_time' => '10:00',
            'end_time' => '11:00',
            'slot_duration_minutes' => 30,
        ]);
        Queue::fake();

        $this->actingAs($admin)
            ->post(route('admin.appointments.reschedule', $appointment->id), [
                'doctor_id' => $newDoctor->id,
                'date' => '2026-10-12',
                'time' => '10:00',
                'reason' => 'Doctor schedule changed',
            ])
            ->assertRedirect(route('admin.appointments.show', $appointment->id))
            ->assertSessionHas('status', 'Appointment rescheduled by hospital staff.');

        $this->assertSame($newDoctor->id, $appointment->fresh()->doctor_id);
        $this->assertSame('2026-10-12', $appointment->fresh()->preferred_date->format('Y-m-d'));
        $this->assertDatabaseHas('appointment_histories', [
            'appointment_request_id' => $appointment->id,
            'old_doctor_id' => $oldDoctor->id,
            'new_doctor_id' => $newDoctor->id,
            'actor_id' => $admin->id,
            'action' => 'rescheduled',
        ]);
    }

    public function test_chat_can_confirm_cancellation_without_losing_booking_context_or_crossing_patients(): void
    {
        [$hospital, $patient, $appointment] = $this->appointmentContext([
            'preferred_date' => '2026-10-10',
            'session_id' => 'lifecycle-chat-session',
        ]);
        $this->actingAs($patient);
        Queue::fake();

        $chat = app(BookingChatService::class);
        $started = $chat->handle('lifecycle-chat-session', 'cancel my appointment');
        $this->assertStringContainsString('Cancel appointment #'.$appointment->id, $started->message);
        $confirmed = $chat->handle('lifecycle-chat-session', 'yes');

        $this->assertStringContainsString('cancelled', mb_strtolower($confirmed->message));
        $this->assertSame(AppointmentRequest::STATUS_CANCELLED, $appointment->fresh()->status);
    }

    public function test_chat_preserves_collecting_state_when_user_asks_for_human_handoff(): void
    {
        $hospital = $this->createHospital();
        $patient = $this->createPatient($hospital);
        $this->actingAs($patient);
        Conversation::query()->create([
            'hospital_id' => $hospital->id,
            'session_id' => 'lifecycle-escalation-session',
            'user_message' => 'booking started',
            'ai_response' => 'What date would you prefer?',
            'confidence_score' => 1,
            'channel' => 'web',
            'escalated' => false,
            'chat_state' => [
                'state' => 'COLLECTING',
                'service_id' => 1,
                'doctor_id' => 1,
                'date' => '2026-10-12',
                'time' => null,
                'patient_name' => 'Test Patient',
                'patient_phone' => '254700000001',
                'patient_email' => null,
                'appointment_id' => null,
                'escalation_pending' => false,
                'pending_escalation_message' => null,
            ],
            'chat_state_updated_at' => now(),
        ]);
        $service = $hospital->services()->create([
            'name' => 'Consultation',
            'price' => 0,
            'duration_minutes' => 30,
            'is_active' => true,
        ]);
        $doctor = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_doctor' => true,
            'role' => 'doctor',
        ]);
        $state = Conversation::query()
            ->where('session_id', 'lifecycle-escalation-session')
            ->firstOrFail()
            ->chat_state;
        $state['service_id'] = $service->id;
        $state['doctor_id'] = $doctor->id;
        Conversation::query()
            ->where('session_id', 'lifecycle-escalation-session')
            ->firstOrFail()
            ->forceFill(['chat_state' => $state])
            ->save();

        $response = app(BookingChatService::class)
            ->handle('lifecycle-escalation-session', 'I need a human', 'web');

        $this->assertContains($response->state, ['COLLECTING', 'IDENTIFYING']);
        $this->assertSame($service->id, $response->collectedFields['service_id']);
        $this->assertSame($doctor->id, $response->collectedFields['doctor_id']);
        $this->assertSame('Test Patient', $response->collectedFields['patient_name']);
        $this->assertSame('2026-10-12', $response->collectedFields['date']);
    }

    private function appointmentContext(array $attributes = []): array
    {
        $hospital = $this->createHospital();
        app()->instance('currentHospital', $hospital);
        $patient = $this->createPatient($hospital);
        $doctor = User::factory()->for($hospital, 'hospital')->create([
            'is_doctor' => true,
            'role' => 'doctor',
        ]);

        $appointment = AppointmentRequest::factory()
            ->for($hospital)
            ->for($patient, 'patient')
            ->for($doctor, 'doctor')
            ->create(array_merge([
                'session_id' => 'lifecycle-chat-session',
                'preferred_date' => '2026-10-10',
                'slot_start_time' => '09:00',
                'slot_end_time' => '09:30',
                'status' => AppointmentRequest::STATUS_PENDING,
                'payment_status' => 'pending',
            ], $attributes));

        return [$hospital, $patient, $appointment, $doctor];
    }

    private function createHospital(array $attributes = []): Hospital
    {
        $hospital = Hospital::factory()->create(array_merge([
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
            'is_active' => true,
            'patient_cancellation_enabled' => true,
            'patient_rescheduling_enabled' => true,
            'cancellation_cutoff_hours' => 24,
            'rescheduling_cutoff_hours' => 24,
            'late_cancellation_fee' => 500,
            'deposits_refundable' => true,
        ], $attributes));
        app()->instance('currentHospital', $hospital);

        return $hospital;
    }

    private function createPatient(Hospital $hospital): User
    {
        return User::factory()->for($hospital, 'hospital')->create(['role' => 'patient']);
    }

    private function createPayment(
        Hospital $hospital,
        AppointmentRequest $appointment,
        string $status,
        float $amount = 500,
    ): MpesaPayment {
        return MpesaPayment::factory()
            ->for($hospital)
            ->for($appointment, 'appointment')
            ->create([
                'status' => $status,
                'amount' => $amount,
            ]);
    }

    private function createHold(Hospital $hospital, AppointmentRequest $appointment): SlotHold
    {
        $start = CarbonImmutable::parse($appointment->preferred_date->format('Y-m-d').' '.$appointment->slot_start_time);

        return SlotHold::query()->create([
            'hospital_id' => $hospital->id,
            'doctor_id' => $appointment->doctor_id,
            'appointment_request_id' => $appointment->id,
            'slot_start_at' => $start,
            'slot_end_at' => $start->addMinutes(30),
            'expires_at' => now()->addMinutes(5),
        ]);
    }
}
