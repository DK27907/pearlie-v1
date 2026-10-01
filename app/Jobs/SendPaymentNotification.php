<?php

namespace App\Jobs;

use App\Models\Hospital;
use App\Models\MpesaPayment;
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
                ->findOrFail($this->paymentId);
            $appointment = $payment->appointment;

            if (! $appointment || $payment->notified_at) {
                return;
            }

            if ($this->outcome === 'completed') {
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

                $message = $this->outcome === 'completed_without_confirmation'
                    ? 'Your M-Pesa payment was received, but the appointment is not confirmed. Please contact the hospital for assistance.'
                    : 'Your appointment request was cancelled because the M-Pesa payment was not completed. Please contact the hospital to book again.';
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
}
