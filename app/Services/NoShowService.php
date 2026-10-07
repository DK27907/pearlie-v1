<?php

namespace App\Services;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Notifications\NoShowNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class NoShowService
{
    public function __construct(
        private readonly WhatsAppService $whatsApp,
        private readonly HospitalMailService $mail,
    ) {}

    public function markAsNoShow(AppointmentRequest $appointment, ?string $reason = null): AppointmentRequest
    {
        try {
            $updatedAppointment = DB::transaction(function () use ($appointment, $reason): AppointmentRequest {
                $lockedAppointment = AppointmentRequest::query()
                    ->lockForUpdate()
                    ->findOrFail($appointment->id);

                abort_unless(
                    $lockedAppointment->status === AppointmentRequest::STATUS_CONFIRMED
                        && $lockedAppointment->payment_status === 'paid',
                    422,
                    'Only confirmed, paid appointments can be marked as no-shows.',
                );

                $lockedAppointment->markAsNoShow($reason);

                return $lockedAppointment->refresh();
            });

            Log::info('Appointment marked as a no-show.', [
                'appointment_id' => $updatedAppointment->id,
                'reason' => $reason,
            ]);
            $this->notifyPatient($updatedAppointment);

            return $updatedAppointment;
        } catch (Throwable $exception) {
            Log::error('Unable to mark appointment as a no-show.', [
                'appointment_id' => $appointment->id,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function pendingNoShowAppointments(): Builder
    {
        $graceMinutes = max(
            0,
            (int) (hospital()?->no_show_grace_minutes ?? config('pearlie.no_show.grace_minutes', 30)),
        );
        $cutoffTime = now()->subMinutes($graceMinutes)->format('H:i:s');

        return AppointmentRequest::query()
            ->where('status', AppointmentRequest::STATUS_CONFIRMED)
            ->where('payment_status', 'paid')
            ->whereDate('preferred_date', today())
            ->whereNotNull('slot_end_time')
            ->where('slot_end_time', '<', $cutoffTime)
            ->whereNull('marked_no_show_at');
    }

    public function markOverdueAppointments(): int
    {
        $markedCount = 0;
        $previousHospital = hospital();

        try {
            Hospital::query()->orderBy('id')->chunkById(100, function ($hospitals) use (&$markedCount): void {
                foreach ($hospitals as $tenant) {
                    app()->instance('currentHospital', $tenant);
                    $this->pendingNoShowAppointments()
                        ->orderBy('id')
                        ->chunkById(100, function ($appointments) use (&$markedCount): void {
                            foreach ($appointments as $appointment) {
                                try {
                                    $this->markAsNoShow($appointment, 'Auto-marked after grace period');
                                    $markedCount++;
                                } catch (Throwable $exception) {
                                    Log::error('Scheduled no-show processing failed for an appointment.', [
                                        'appointment_id' => $appointment->id,
                                        'exception' => $exception,
                                    ]);
                                }
                            }
                        });
                }
            });
        } finally {
            app()->instance('currentHospital', $previousHospital);
        }

        return $markedCount;
    }

    private function notifyPatient(AppointmentRequest $appointment): void
    {
        $message = sprintf(
            'Hello %s, our records show you missed your appointment at %s. The booking deposit is retained for missed appointments. Call %s to arrange a new visit.',
            $appointment->name ?: 'there',
            pearlie_config('hospital.name'),
            pearlie_config('hospital.appointment_phone'),
        );

        if ($appointment->phone) {
            try {
                $result = $this->whatsApp->sendMessage($appointment->phone, $message);
                if (! $result['success']) {
                    Log::warning('WhatsApp no-show notice was not accepted for delivery.', [
                        'appointment_id' => $appointment->id,
                        'message' => $result['message'],
                    ]);
                }
            } catch (Throwable $exception) {
                Log::error('WhatsApp no-show notice failed.', [
                    'appointment_id' => $appointment->id,
                    'exception' => $exception,
                ]);
            }
        }

        if ($appointment->email) {
            try {
                $this->mail->runForHospital(
                    (int) $appointment->hospital_id,
                    fn () => Notification::route('mail', $appointment->email)
                        ->notify(new NoShowNotification($appointment)),
                );
            } catch (Throwable $exception) {
                Log::error('Email no-show notice failed.', [
                    'appointment_id' => $appointment->id,
                    'exception' => $exception,
                ]);
            }
        }
    }
}
