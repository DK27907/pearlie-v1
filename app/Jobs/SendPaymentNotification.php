<?php

namespace App\Jobs;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\MpesaPayment;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SendPaymentNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30];

    public function __construct(
        public readonly int $paymentId,
        public readonly int $hospitalId,
        public readonly string $outcome,
        public readonly string $recipientRole = 'patient',
    ) {}

    public function handle(
        WhatsAppService $whatsapp,
        NotificationService $notifications,
    ): void {
        $hadCurrentHospital = app()->bound('currentHospital');
        $previousHospital = $hadCurrentHospital ? app('currentHospital') : null;
        try {
            $hospital = Hospital::withoutGlobalScopes()->findOrFail($this->hospitalId);
            app()->instance('currentHospital', $hospital);

            $payment = MpesaPayment::query()
                ->with('appointment')
                ->find($this->paymentId);

            if (! $payment) {
                Log::warning('SendPaymentNotification skipped: payment missing.', [
                    'payment_id' => $this->paymentId,
                    'hospital_id' => $this->hospitalId,
                ]);

                return;
            }

            $appointment = $payment->appointment;

            if (! $appointment) {
                return;
            }

            if ($this->recipientRole === 'admin') {
                if (! in_array($this->outcome, [
                    'payment_completed_awaiting_confirmation',
                    'payment_received_slot_expired',
                ], true)) {
                    throw new RuntimeException('Unsupported hospital-admin payment notification outcome.');
                }

                $this->notifyHospitalAdmins($appointment, $notifications, $this->outcome);

                return;
            }

            if ($this->recipientRole !== 'patient') {
                throw new RuntimeException('Unsupported payment notification recipient role.');
            }

            if ($payment->notified_at) {
                return;
            }

            if (in_array($this->outcome, ['completed', 'payment_completed'], true)) {
                $sent = $whatsapp->sendAppointmentConfirmation($appointment);
            } else {
                $phone = $appointment->mpesa_phone ?: $appointment->phone;
                if ($phone === null) {
                    Log::warning('Unable to send an M-Pesa payment lifecycle notification without a phone number.', [
                        'payment_id' => $payment->id,
                        'hospital_id' => $this->hospitalId,
                        'outcome' => $this->outcome,
                    ]);

                    return;
                }

                $message = match ($this->outcome) {
                    'payment_completed_awaiting_confirmation' => 'Payment received. Your appointment is being reviewed and you will receive a confirmation shortly.',
                    'payment_received_slot_expired' => 'Your payment was received, but the slot is no longer available. Our team will contact you to rebook or refund.',
                    'completed_without_confirmation' => 'Your M-Pesa payment was received, but the appointment is not confirmed. Please contact the hospital for assistance.',
                    'payment_failed', 'payment_timeout' => 'Your appointment request was cancelled because the M-Pesa payment was not completed. Please contact the hospital to book again.',
                    default => throw new RuntimeException('Unsupported patient payment notification outcome.'),
                };
                $sent = $notifications->sendSms(
                    $phone,
                    $message,
                );
            }

            if (! $sent) {
                throw new RuntimeException('The payment lifecycle notification provider did not accept the message.');
            }

            $payment->forceFill(['notified_at' => now()])->save();
        } catch (Throwable $exception) {
            Log::error('Unable to send an M-Pesa payment lifecycle notification.', [
                'payment_id' => $this->paymentId,
                'hospital_id' => $this->hospitalId,
                'outcome' => $this->outcome,
                'exception' => $exception,
            ]);

            throw $exception;
        } finally {
            if ($hadCurrentHospital) {
                app()->instance('currentHospital', $previousHospital);
            } else {
                app()->forgetInstance('currentHospital');
            }
        }
    }

    private function notifyHospitalAdmins(
        AppointmentRequest $appointment,
        NotificationService $notifications,
        string $outcome,
    ): void {
        $admins = User::query()
            ->where('hospital_id', $this->hospitalId)
            ->whereNotNull('phone')
            ->where(function ($query): void {
                $query->whereIn('role', ['admin', 'hospital_admin'])
                    ->orWhere('is_admin', true)
                    ->orWhereHas('roles', fn ($roles) => $roles->whereIn('name', ['admin', 'hospital_admin']));
            })
            ->get();

        if ($admins->isEmpty()) {
            Log::warning('No hospital administrator with a phone number is available for a paid appointment review notification.', [
                'appointment_id' => $appointment->id,
                'hospital_id' => $this->hospitalId,
                'payment_id' => $this->paymentId,
            ]);

            return;
        }

        $message = $outcome === 'payment_received_slot_expired'
            ? sprintf(
                'Payment for expired appointment #%d was received. Please contact the patient to arrange rebooking or a refund.',
                $appointment->id,
            )
            : sprintf(
                'Paid appointment #%d is awaiting confirmation. Please review it in the admin dashboard.',
                $appointment->id,
            );

        foreach ($admins as $admin) {
            if (! $notifications->sendSms((string) $admin->phone, $message)) {
                throw new RuntimeException('The hospital-admin payment notification provider did not accept the message.');
            }
        }
    }
}
