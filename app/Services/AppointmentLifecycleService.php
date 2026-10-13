<?php

namespace App\Services;

use App\Events\AppointmentCancelled;
use App\Events\AppointmentRescheduled;
use App\Exceptions\AppointmentLifecycleException;
use App\Jobs\SendAppointmentLifecycleNotifications;
use App\Models\AppointmentHistory;
use App\Models\AppointmentRequest;
use App\Models\DoctorAvailability;
use App\Models\Hospital;
use App\Models\MpesaPayment;
use App\Models\Service;
use App\Models\SlotHold;
use App\Models\User;
use App\Support\CancellationResult;
use App\Support\RescheduleResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AppointmentLifecycleService
{
    public function __construct(
        private readonly AppointmentPolicyService $policy,
        private readonly DoctorAvailabilityService $availability,
        private readonly PaymentLifecycleService $payments,
    ) {}

    public function cancel(
        int $appointmentId,
        int $hospitalId,
        ?User $actor,
        bool $staffOverride = false,
        ?string $reason = null,
    ): CancellationResult {
        $this->ensureTenantContext($hospitalId);
        $reason = trim((string) $reason);
        $reason = $reason === '' ? null : mb_substr($reason, 0, 1000);

        $result = DB::transaction(function () use (
            $appointmentId,
            $hospitalId,
            $actor,
            $staffOverride,
            $reason,
        ): CancellationResult {
            $payments = MpesaPayment::query()
                ->where('hospital_id', $hospitalId)
                ->where('appointment_request_id', $appointmentId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $appointment = AppointmentRequest::query()
                ->where('hospital_id', $hospitalId)
                ->lockForUpdate()
                ->find($appointmentId);

            if (! $appointment) {
                throw new AppointmentLifecycleException('Appointment not found.');
            }
            $this->assertActorCanManage($appointment, $actor, $staffOverride);

            $decision = $this->policy->evaluate($appointment, $staffOverride);
            if (! $decision->canCancel) {
                throw new AppointmentLifecycleException('This appointment is not eligible for cancellation under the hospital policy.');
            }

            $paidPayment = $payments->first(
                fn (MpesaPayment $payment): bool => $payment->status === MpesaPayment::STATUS_COMPLETED,
            );
            $paidAmount = (float) ($paidPayment?->amount
                ?? ($appointment->payment_status === 'paid'
                    ? ($appointment->payment_amount ?? $appointment->booking_fee)
                    : 0)
                ?? 0);
            $forfeitedAmount = $decision->isLateCancellation
                ? min($paidAmount, $decision->lateCancellationFee)
                : 0.0;
            if ($paidAmount > 0 && ! $decision->depositsRefundable) {
                $forfeitedAmount = $paidAmount;
            }

            $refundPending = $paidAmount > $forfeitedAmount && $decision->depositsRefundable;
            $paymentOutcome = $refundPending
                ? 'refund_pending'
                : ($forfeitedAmount > 0 ? 'retained' : 'unpaid');

            foreach ($payments as $payment) {
                if (! $payment->isPending()) {
                    continue;
                }

                $payment->forceFill([
                    'status' => MpesaPayment::STATUS_CANCELLED,
                    'result_description' => 'Appointment cancelled before payment completed.',
                    'processed_at' => now(),
                ])->save();
                $this->payments->recordEvent($payment, 'appointment_cancelled', [
                    'appointment_id' => $appointment->id,
                    'reason' => $reason,
                ]);
            }

            $appointment->forceFill([
                'status' => AppointmentRequest::STATUS_CANCELLED,
                'status_updated_at' => now(),
                'cancelled_at' => now(),
                'cancelled_by_id' => $actor?->id,
                'cancellation_reason' => $reason,
                'forfeited_amount' => $forfeitedAmount,
                'payment_status' => $paidAmount > 0
                    ? $paymentOutcome
                    : ($appointment->payment_status === 'pending'
                        || $payments->contains(fn (MpesaPayment $payment): bool => $payment->status === MpesaPayment::STATUS_CANCELLED)
                        ? 'unpaid'
                        : $appointment->payment_status),
            ])->save();

            $this->payments->releaseSlot($appointment);
            $this->recordHistory(
                appointment: $appointment,
                actor: $actor,
                staffOverride: $staffOverride,
                action: 'cancelled',
                reason: $reason,
                metadata: [
                    'cancellation_cutoff_at' => $decision->cancellationCutoffAt->toIso8601String(),
                    'late_cancellation' => $decision->isLateCancellation,
                    'late_cancellation_fee' => $decision->lateCancellationFee,
                    'forfeited_amount' => $forfeitedAmount,
                    'payment_outcome' => $paymentOutcome,
                ],
            );

            return new CancellationResult($appointment, $forfeitedAmount, $paymentOutcome);
        });

        AppointmentCancelled::dispatch($appointmentId, $hospitalId, $reason ?? '');
        SendAppointmentLifecycleNotifications::dispatch(
            $appointmentId,
            $hospitalId,
            'cancelled',
            $reason ?? '',
        )->afterCommit();

        return $result;
    }

    public function reschedule(
        int $appointmentId,
        int $hospitalId,
        int $newDoctorId,
        string $newDate,
        string $newStartTime,
        ?User $actor,
        bool $staffOverride = false,
        ?string $reason = null,
    ): RescheduleResult {
        $this->ensureTenantContext($hospitalId);
        $reason = trim((string) $reason);
        $reason = $reason === '' ? null : mb_substr($reason, 0, 1000);

        $result = DB::transaction(function () use (
            $appointmentId,
            $hospitalId,
            $newDoctorId,
            $newDate,
            $newStartTime,
            $actor,
            $staffOverride,
            $reason,
        ): RescheduleResult {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $newDate);
            if (! $date
                || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $newStartTime) !== 1
            ) {
                throw new AppointmentLifecycleException('Choose a valid appointment date and time.');
            }

            $availabilityRecords = DoctorAvailability::query()
                ->where('hospital_id', $hospitalId)
                ->where('doctor_id', $newDoctorId)
                ->where('day_of_week', $date->dayOfWeek)
                ->orderBy('start_time')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $appointment = AppointmentRequest::query()
                ->where('hospital_id', $hospitalId)
                ->lockForUpdate()
                ->find($appointmentId);

            if (! $appointment) {
                throw new AppointmentLifecycleException('Appointment not found.');
            }
            $this->assertActorCanManage($appointment, $actor, $staffOverride);

            $decision = $this->policy->evaluate($appointment, $staffOverride);
            if (! $decision->canReschedule) {
                throw new AppointmentLifecycleException('This appointment is inside the hospital rescheduling cutoff or is not eligible for rescheduling.');
            }

            if (! $date
                || $date->toDateString() < CarbonImmutable::today()->toDateString()
                || $date->greaterThan(CarbonImmutable::today()->addYear())
            ) {
                throw new AppointmentLifecycleException('Choose a future date within the next 12 months.');
            }
            if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $newStartTime) !== 1) {
                throw new AppointmentLifecycleException('Choose a valid appointment time.');
            }

            $doctor = User::query()
                ->where('hospital_id', $hospitalId)
                ->where('is_doctor', true)
                ->where('is_active', true)
                ->find($newDoctorId);
            if (! $doctor) {
                throw new AppointmentLifecycleException('The selected doctor is not available at this hospital.');
            }
            $service = $appointment->service_id === null
                ? null
                : Service::query()
                    ->where('hospital_id', $hospitalId)
                    ->whereKey($appointment->service_id)
                    ->first();
            if ($appointment->service_id !== null && ! $service) {
                throw new AppointmentLifecycleException('The appointment service is no longer available at this hospital.');
            }
            if ($service && filled($service->requires_specialty)) {
                $requiredSpecialty = mb_strtolower(trim((string) $service->requires_specialty));
                $eligibleDoctors = User::query()
                    ->where('hospital_id', $hospitalId)
                    ->where('is_doctor', true)
                    ->where('is_active', true)
                    ->get()
                    ->filter(fn (User $candidate): bool => $this->availability->doctorMatchesSpecialty(
                        $candidate,
                        $requiredSpecialty,
                    ));
                if ($eligibleDoctors->isNotEmpty()
                    && ! $this->availability->doctorMatchesSpecialty($doctor, $requiredSpecialty)
                ) {
                    throw new AppointmentLifecycleException('The selected doctor does not provide this appointment service.');
                }
            }

            $matchingSlot = $availabilityRecords
                ->where('is_active', true)
                ->flatMap(fn ($availability) => $availability->generateSlots())
                ->first(fn (array $slot): bool => $slot['start'] === $newStartTime);
            $isUnchangedSlot = (int) $appointment->doctor_id === (int) $doctor->id
                && $appointment->preferred_date?->format('Y-m-d') === $date->toDateString()
                && CarbonImmutable::parse((string) $appointment->slot_start_time)->format('H:i') === $newStartTime;
            if (! $matchingSlot || (! $isUnchangedSlot
                && ! $this->availability->isSlotAvailable($doctor->id, $newDate, $newStartTime))) {
                throw new AppointmentLifecycleException('That slot is no longer available. Choose another date or time.');
            }

            $oldDate = $appointment->preferred_date?->format('Y-m-d');
            $oldDoctorId = $appointment->doctor_id;
            $oldStartTime = $appointment->slot_start_time;
            SlotHold::query()
                ->where('hospital_id', $hospitalId)
                ->where('appointment_request_id', $appointment->id)
                ->delete();

            $appointment->forceFill([
                'preferred_date' => $date->toDateString(),
                'doctor_id' => $doctor->id,
                'slot_start_time' => $matchingSlot['start'],
                'slot_end_time' => $matchingSlot['end'],
            ])->save();

            if ($appointment->payment_status !== 'paid') {
                SlotHold::query()->create([
                    'hospital_id' => $hospitalId,
                    'doctor_id' => $doctor->id,
                    'appointment_request_id' => $appointment->id,
                    'slot_start_at' => $date->setTimeFromTimeString($matchingSlot['start']),
                    'slot_end_at' => $date->setTimeFromTimeString($matchingSlot['end']),
                    'expires_at' => now()->addMinutes(5),
                ]);
            }

            $this->recordHistory(
                appointment: $appointment,
                actor: $actor,
                staffOverride: $staffOverride,
                action: 'rescheduled',
                reason: $reason,
                oldDate: $oldDate,
                oldDoctorId: $oldDoctorId,
                oldStartTime: $oldStartTime,
                metadata: [
                    'rescheduling_cutoff_at' => $decision->reschedulingCutoffAt->toIso8601String(),
                    'staff_override' => $staffOverride,
                ],
            );

            return new RescheduleResult($appointment);
        });

        AppointmentRescheduled::dispatch($appointmentId, $hospitalId, $reason ?? '');
        SendAppointmentLifecycleNotifications::dispatch(
            $appointmentId,
            $hospitalId,
            'rescheduled',
            $reason ?? '',
        )->afterCommit();

        return $result;
    }

    private function recordHistory(
        AppointmentRequest $appointment,
        ?User $actor,
        bool $staffOverride,
        string $action,
        ?string $reason,
        ?string $oldDate = null,
        ?int $oldDoctorId = null,
        ?string $oldStartTime = null,
        array $metadata = [],
    ): void {
        AppointmentHistory::query()->create([
            'hospital_id' => $appointment->hospital_id,
            'appointment_request_id' => $appointment->id,
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role ?: ($staffOverride ? 'staff' : 'patient'),
            'action' => $action,
            'old_date' => $oldDate ?? $appointment->preferred_date?->format('Y-m-d'),
            'new_date' => $action === 'rescheduled' ? $appointment->preferred_date?->format('Y-m-d') : null,
            'old_doctor_id' => $oldDoctorId ?? $appointment->doctor_id,
            'new_doctor_id' => $action === 'rescheduled' ? $appointment->doctor_id : null,
            'old_start_time' => $oldStartTime ?? $appointment->slot_start_time,
            'new_start_time' => $action === 'rescheduled' ? $appointment->slot_start_time : null,
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }

    private function ensureTenantContext(int $hospitalId): void
    {
        if ((int) (hospital()?->id ?? 0) !== $hospitalId) {
            throw new AppointmentLifecycleException('The current hospital context does not match this appointment.');
        }

        Hospital::withoutGlobalScopes()->whereKey($hospitalId)->firstOrFail();
    }

    private function assertActorCanManage(
        AppointmentRequest $appointment,
        ?User $actor,
        bool $staffOverride,
    ): void {
        if ($actor === null) {
            if ($staffOverride) {
                throw new AppointmentLifecycleException('A hospital staff account is required for an override.');
            }

            return;
        }

        if ((int) $actor->hospital_id !== (int) $appointment->hospital_id) {
            throw new AppointmentLifecycleException('The actor does not belong to this appointment hospital.');
        }

        if (! $staffOverride) {
            if ((int) $appointment->patient_id !== (int) $actor->id) {
                throw new AppointmentLifecycleException('Only the patient who owns this appointment can change it.');
            }

            return;
        }

        if ($actor->isDoctor()) {
            if ((int) $appointment->doctor_id !== (int) $actor->id) {
                throw new AppointmentLifecycleException('Doctors can only change appointments assigned to them.');
            }

            return;
        }

        if (! $actor->isAdmin()) {
            throw new AppointmentLifecycleException('A hospital administrator or assigned doctor is required for an override.');
        }
    }
}
