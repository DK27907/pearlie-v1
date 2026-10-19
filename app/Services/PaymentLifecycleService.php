<?php

namespace App\Services;

use App\Jobs\SendPaymentNotification;
use App\Models\AppointmentRequest;
use App\Models\Conversation;
use App\Models\MpesaPayment;
use App\Models\PaymentEvent;
use App\Models\SlotHold;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentLifecycleService
{
    public function __construct(
        private readonly DoctorAvailabilityService $availability,
    ) {}

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
                ['timeout_after_seconds' => 1800],
            );

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordLateSuccess(MpesaPayment $payment, array $metadata): bool
    {
        return DB::transaction(function () use ($payment, $metadata): bool {
            $lockedPayment = MpesaPayment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedPayment || ! in_array($lockedPayment->status, [
                MpesaPayment::STATUS_FAILED,
                MpesaPayment::STATUS_TIMEOUT,
            ], true)) {
                return false;
            }

            $previousStatus = $lockedPayment->status;
            $lockedPayment->forceFill([
                'status' => MpesaPayment::STATUS_LATE_SUCCESS_REFUND_PENDING,
                'result_code' => $metadata['result_code'] ?? 0,
                'result_description' => $metadata['result_description']
                    ?? 'Payment succeeded after the payment had already failed or timed out.',
                'mpesa_receipt' => $metadata['mpesa_receipt'] ?? null,
                'callback_payload' => $metadata['callback_payload'] ?? null,
                'processed_at' => now(),
            ])->save();

            $appointment = $lockedPayment->appointment_request_id === null
                ? null
                : AppointmentRequest::query()
                    ->whereKey($lockedPayment->appointment_request_id)
                    ->lockForUpdate()
                    ->first();

            if ($appointment && $appointment->payment_status !== 'paid') {
                $appointment->forceFill([
                    'payment_status' => 'refund_pending',
                    'payment_amount' => $lockedPayment->amount,
                    'mpesa_result_code' => $metadata['result_code'] ?? 0,
                    'mpesa_result_description' => $metadata['result_description']
                        ?? 'Payment succeeded after the payment had already failed or timed out.',
                    'mpesa_receipt' => $metadata['mpesa_receipt'] ?? null,
                    'paid_at' => null,
                ])->save();
            }

            $eventPayload = [
                'previous_status' => $previousStatus,
                'receipt' => $metadata['mpesa_receipt'] ?? null,
            ];
            $this->recordEvent($lockedPayment, 'late_success', $eventPayload);

            Log::warning('M-Pesa payment succeeded after failure or timeout; refund or manual review is required.', [
                'payment_id' => $lockedPayment->id,
                'hospital_id' => $lockedPayment->hospital_id,
                'appointment_id' => $lockedPayment->appointment_request_id,
                'checkout_request_id' => $lockedPayment->checkout_request_id,
                'previous_status' => $previousStatus,
                'receipt' => $metadata['mpesa_receipt'] ?? null,
            ]);

            return true;
        });
    }

    public function releaseSlot(AppointmentRequest $appointment): void
    {
        SlotHold::query()
            ->where('hospital_id', $appointment->hospital_id)
            ->where('appointment_request_id', $appointment->id)
            ->delete();
    }

    public function confirmSlot(AppointmentRequest $appointment): void
    {
        if ($appointment->status === AppointmentRequest::STATUS_PENDING) {
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
            $slotExpired = $previousStatus === AppointmentRequest::STATUS_EXPIRED;
            $shouldAutoConfirm = $appointment->hospital?->shouldAutoConfirmPaidAppointments() ?? false;
            $attributes = [
                'payment_status' => $slotExpired ? 'refund_pending' : 'paid',
                'payment_amount' => $payment->amount,
                'paid_at' => now(),
            ];

            $appointment->forceFill($attributes)->save();
            if ($shouldAutoConfirm && ! $slotExpired) {
                $this->confirmSlot($appointment);
            }

            $this->releaseSlot($appointment);

            $payload = array_merge($payload, [
                'appointment_status_before' => $previousStatus,
                'appointment_status_after' => $appointment->status,
            ]);

            $event = match (true) {
                $slotExpired => 'payment_received_slot_expired',
                $shouldAutoConfirm => 'payment_completed',
                default => 'payment_completed_awaiting_confirmation',
            };
            $this->dispatchNotificationAfterCommit($payment, $event);

            if (! $shouldAutoConfirm || $slotExpired) {
                $this->dispatchNotificationAfterCommit($payment, $event, 'admin');
            }

            if (! $slotExpired && $appointment->payment_status === 'paid') {
                $this->createChatPaymentReceipt($appointment, $payment);
            }
        } else {
            $event = 'payment_completed';
            $this->dispatchNotificationAfterCommit($payment, $event);
        }

        $this->recordEvent($payment, $event, $payload);
    }

    private function createChatPaymentReceipt(
        AppointmentRequest $appointment,
        MpesaPayment $payment,
    ): void {
        if (! is_string($appointment->session_id) || $appointment->session_id === '') {
            return;
        }

        // Payment callbacks may run outside the browser request, so bind reads to the appointment tenant.
        $latestConversation = Conversation::withoutGlobalScopes()
            ->where('hospital_id', $appointment->hospital_id)
            ->where('session_id', $appointment->session_id)
            ->where('channel', 'web')
            ->latest('id')
            ->first();

        if (! $latestConversation) {
            return;
        }

        $isSwahili = data_get($latestConversation->chat_state, 'language') === 'sw'
            || preg_match('/\b(?:ndiyo|sawa|malipo)\b/iu', $latestConversation->user_message) === 1;
        $bookingDate = $appointment->preferred_date?->format('d/m/Y') ?? 'Not provided';
        $bookingTime = is_string($appointment->slot_start_time) && $appointment->slot_start_time !== ''
            ? Carbon::parse($appointment->slot_start_time)->format('g:i A')
            : 'Not provided';
        $receipt = $payment->mpesa_receipt ?: 'Not provided';
        $bookingStatus = $appointment->status === AppointmentRequest::STATUS_CONFIRMED
            ? ($isSwahili ? 'Imethibitishwa' : 'Confirmed')
            : ($isSwahili ? 'Inasubiri uthibitisho wa hospitali' : 'Awaiting hospital confirmation');
        $message = $isSwahili
            ? implode("\n", [
                'Malipo ya miadi yamepokelewa',
                'Nambari ya miadi: #'.$appointment->id,
                'Mgonjwa: '.$appointment->name,
                'Huduma: '.$appointment->reason,
                'Tarehe: '.$bookingDate,
                'Saa: '.$bookingTime,
                'Kiasi kilicholipwa: KSh '.number_format((float) $payment->amount, 2),
                'Hali ya malipo: Imelipwa',
                'Stakabadhi ya M-Pesa: '.$receipt,
                'Hali ya miadi: '.$bookingStatus,
            ])
            : implode("\n", [
                'Booking payment received',
                'Booking: #'.$appointment->id,
                'Patient: '.$appointment->name,
                'Service: '.$appointment->reason,
                'Date: '.$bookingDate,
                'Time: '.$bookingTime,
                'Amount paid: KSh '.number_format((float) $payment->amount, 2),
                'Payment status: Paid',
                'M-Pesa receipt: '.$receipt,
                'Booking status: '.$bookingStatus,
            ]);

        // The callback receipt is a system event with its tenant copied from the locked appointment.
        Conversation::withoutGlobalScopes()->forceCreate([
            'hospital_id' => $appointment->hospital_id,
            'session_id' => $appointment->session_id,
            'user_message' => '',
            'ai_response' => $message,
            'confidence_score' => 1.0,
            'channel' => 'web',
            'chat_state' => [
                'system_event' => 'payment_receipt',
                'appointment_id' => $appointment->id,
            ],
            'chat_state_updated_at' => now(),
        ]);
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

            if ($previousStatus === AppointmentRequest::STATUS_PENDING
                && $appointment->doctor_id
                && $appointment->preferred_date
                && $appointment->slot_start_time
            ) {
                $this->availability->releaseSlotCapacity(
                    (int) $appointment->hospital_id,
                    (int) $appointment->doctor_id,
                    $appointment->preferred_date->format('Y-m-d'),
                    (string) $appointment->slot_start_time,
                );
            }

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
        PaymentEvent::withoutEvents(fn (): PaymentEvent => PaymentEvent::query()->forceCreate([
            'hospital_id' => $payment->hospital_id,
            'payment_id' => $payment->id,
            'event' => $event,
            'payload' => $payload,
        ]));
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

            if ($appointment->doctor_id
                && $appointment->preferred_date
                && $appointment->slot_start_time
            ) {
                $this->availability->releaseSlotCapacity(
                    (int) $appointment->hospital_id,
                    (int) $appointment->doctor_id,
                    $appointment->preferred_date->format('Y-m-d'),
                    (string) $appointment->slot_start_time,
                );
            }

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
