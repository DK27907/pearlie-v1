<?php

namespace App\Services;

use App\Jobs\SendPaymentNotification;
use App\Models\AppointmentRequest;
use App\Models\MpesaPayment;
use App\Models\PaymentEvent;
use App\Models\SlotHold;
use Illuminate\Support\Facades\DB;

class PaymentLifecycleService
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function markCompleted(MpesaPayment $payment, array $metadata): bool
    {
        return DB::transaction(function () use ($payment, $metadata): bool {
            $lockedPayment = MpesaPayment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedPayment || ! $lockedPayment->isPending()) {
                return false;
            }

            $lockedPayment->markAsCompleted($metadata);
            $this->completeAppointmentPaymentWithinTransaction(
                $lockedPayment,
                $lockedPayment->appointment,
                $metadata,
            );

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function markFailed(
        MpesaPayment $payment,
        string $reason,
        ?int $resultCode = null,
        array $metadata = [],
    ): bool {
        return DB::transaction(function () use ($payment, $reason, $resultCode, $metadata): bool {
            $lockedPayment = MpesaPayment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedPayment || ! $lockedPayment->isPending()) {
                return false;
            }

            $lockedPayment->markAsFailed($reason, $resultCode);
            if ($metadata !== []) {
                $lockedPayment->forceFill(['callback_payload' => $metadata])->save();
            }

            $this->failAppointmentPaymentWithinTransaction(
                $lockedPayment,
                $lockedPayment->appointment,
                'payment_failed',
                array_merge($metadata, ['reason' => $reason]),
            );

            return true;
        });
    }

    public function markTimeout(MpesaPayment $payment): bool
    {
        return DB::transaction(function () use ($payment): bool {
            $lockedPayment = MpesaPayment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedPayment || ! $lockedPayment->isPending()) {
                return false;
            }

            $lockedPayment->forceFill([
                'status' => MpesaPayment::STATUS_TIMEOUT,
                'result_description' => 'Payment confirmation timed out.',
                'processed_at' => now(),
            ])->save();

            $this->failAppointmentPaymentWithinTransaction(
                $lockedPayment,
                $lockedPayment->appointment,
                'payment_timeout',
                ['timeout_after_seconds' => 300],
            );

            return true;
        });
    }

    public function releaseSlot(AppointmentRequest $appointment): void
    {
        SlotHold::query()
            ->where('appointment_request_id', $appointment->id)
            ->delete();
    }

    public function confirmSlot(AppointmentRequest $appointment): void
    {
        if (in_array($appointment->status, [
            AppointmentRequest::STATUS_PENDING,
            AppointmentRequest::STATUS_EXPIRED,
        ], true)) {
            $appointment->forceFill([
                'status' => AppointmentRequest::STATUS_CONFIRMED,
                'status_updated_at' => now(),
            ])->save();
        }
    }

    private function completeAppointmentPaymentWithinTransaction(
        MpesaPayment $payment,
        ?AppointmentRequest $appointment,
        array $payload = [],
    ): void {
        if ($appointment) {
            $appointment = AppointmentRequest::query()
                ->lockForUpdate()
                ->find($appointment->id) ?? $appointment;
            $previousStatus = $appointment->status;
            $shouldAutoConfirm = $appointment->hospital?->shouldAutoConfirmPaidAppointments() ?? false;
            $attributes = [
                'payment_status' => 'paid',
                'payment_amount' => $payment->amount,
                'paid_at' => now(),
            ];

            $appointment->forceFill($attributes)->save();
            if ($shouldAutoConfirm) {
                $this->confirmSlot($appointment);
            }

            $this->releaseSlot($appointment);

            $payload = array_merge($payload, [
                'appointment_status_before' => $previousStatus,
                'appointment_status_after' => $appointment->status,
            ]);

            $event = $shouldAutoConfirm
                ? 'payment_completed'
                : 'payment_completed_awaiting_confirmation';
            $this->dispatchNotificationAfterCommit($payment, $event);

            if (! $shouldAutoConfirm) {
                $this->dispatchNotificationAfterCommit($payment, $event, 'admin');
            }
        } else {
            $event = 'payment_completed';
            $this->dispatchNotificationAfterCommit($payment, $event);
        }

        $this->recordEvent($payment, $event, $payload);
    }

    private function failAppointmentPaymentWithinTransaction(
        MpesaPayment $payment,
        ?AppointmentRequest $appointment,
        string $event,
        array $payload = [],
    ): void {
        if ($appointment) {
            $appointment = AppointmentRequest::query()
                ->lockForUpdate()
                ->find($appointment->id) ?? $appointment;
            $previousStatus = $appointment->status;
            $attributes = [
                'payment_status' => 'unpaid',
                'payment_amount' => $payment->amount,
                'paid_at' => null,
            ];

            if ($previousStatus === AppointmentRequest::STATUS_PENDING) {
                $attributes['status'] = AppointmentRequest::STATUS_CANCELLED;
                $attributes['status_updated_at'] = now();
            }

            $appointment->forceFill($attributes)->save();

            $this->releaseSlot($appointment);

            $payload = array_merge($payload, [
                'appointment_status_before' => $previousStatus,
                'appointment_status_after' => $appointment->status,
            ]);
        }

        $this->recordEvent($payment, $event, $payload);
        if ($appointment) {
            $this->dispatchNotificationAfterCommit($payment, $event);
        }
    }

    public function recordEvent(MpesaPayment $payment, string $event, array $payload = []): void
    {
        PaymentEvent::query()->create([
            'hospital_id' => $payment->hospital_id,
            'payment_id' => $payment->id,
            'event' => $event,
            'payload' => $payload,
        ]);
    }

    public function cancelAppointmentAfterFailedInitiation(int $appointmentId): void
    {
        DB::transaction(function () use ($appointmentId): void {
            $appointment = AppointmentRequest::query()
                ->lockForUpdate()
                ->find($appointmentId);

            if (! $appointment || $appointment->status !== AppointmentRequest::STATUS_PENDING) {
                return;
            }

            $appointment->forceFill([
                'payment_status' => 'unpaid',
                'paid_at' => null,
                'status' => AppointmentRequest::STATUS_CANCELLED,
                'status_updated_at' => now(),
            ])->save();

            $this->releaseSlot($appointment);
        });
    }

    private function dispatchNotificationAfterCommit(
        MpesaPayment $payment,
        string $outcome,
        string $recipientRole = 'patient',
    ): void {
        if ($payment->appointment_request_id === null) {
            return;
        }

        SendPaymentNotification::dispatch(
            $payment->id,
            (int) $payment->hospital_id,
            $outcome,
            $recipientRole,
        )->afterCommit();
    }
}
