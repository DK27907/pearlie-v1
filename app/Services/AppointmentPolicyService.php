<?php

namespace App\Services;

use App\Exceptions\AppointmentLifecycleException;
use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Support\PolicyResult;
use Carbon\CarbonImmutable;

class AppointmentPolicyService
{
    public function evaluate(AppointmentRequest $appointment, bool $staffOverride = false): PolicyResult
    {
        // The appointment's persisted hospital_id is the authority for its policy settings.
        $hospital = Hospital::withoutGlobalScopes()
            ->whereKey($appointment->hospital_id)
            ->first();

        if (! $hospital) {
            throw new AppointmentLifecycleException('This appointment is missing its hospital.');
        }
        if (! $appointment->preferred_date) {
            $now = CarbonImmutable::now();

            return new PolicyResult(false, false, false, $now, $now, 0, (bool) $hospital->deposits_refundable);
        }

        $scheduledAt = CarbonImmutable::parse(
            $appointment->preferred_date->format('Y-m-d').' '.($appointment->slot_start_time ?: '00:00:00'),
        );
        $cancellationCutoffAt = $scheduledAt->subHours((int) $hospital->cancellation_cutoff_hours);
        $reschedulingCutoffAt = $scheduledAt->subHours((int) $hospital->rescheduling_cutoff_hours);
        $isOpen = in_array($appointment->status, [
            AppointmentRequest::STATUS_PENDING,
            AppointmentRequest::STATUS_CONFIRMED,
        ], true) && $scheduledAt->isFuture();

        return new PolicyResult(
            canCancel: $isOpen && ($staffOverride || $hospital->patient_cancellation_enabled),
            canReschedule: $isOpen && ($staffOverride || (
                $hospital->patient_rescheduling_enabled && now()->lt($reschedulingCutoffAt)
            )),
            isLateCancellation: ! $staffOverride && now()->gte($cancellationCutoffAt),
            cancellationCutoffAt: $cancellationCutoffAt,
            reschedulingCutoffAt: $reschedulingCutoffAt,
            lateCancellationFee: (float) $hospital->late_cancellation_fee,
            depositsRefundable: (bool) $hospital->deposits_refundable,
        );
    }
}
