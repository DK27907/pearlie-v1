<?php

namespace App\Jobs;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\User;
use App\Notifications\AppointmentLifecycleNotification;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class SendAppointmentLifecycleNotifications implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30];

    public function __construct(
        public readonly int $appointmentId,
        public readonly int $hospitalId,
        public readonly string $action,
        public readonly string $reason,
    ) {}

    public function handle(NotificationService $notifications): void
    {
        $hadCurrentHospital = app()->bound('currentHospital');
        $previousHospital = $hadCurrentHospital ? app('currentHospital') : null;

        try {
            $hospital = Hospital::withoutGlobalScopes()->findOrFail($this->hospitalId);
            app()->instance('currentHospital', $hospital);

            $appointment = AppointmentRequest::query()
                ->where('hospital_id', $this->hospitalId)
                ->findOrFail($this->appointmentId);
            $recipientIds = array_filter([
                $appointment->patient_id,
                $appointment->doctor_id,
            ]);
            $recipients = User::query()
                ->where('hospital_id', $this->hospitalId)
                ->where(function ($query) use ($recipientIds): void {
                    $query->whereIn('id', $recipientIds)
                        ->orWhere('role', 'hospital_admin')
                        ->orWhere('is_admin', true);
                })
                ->get();

            if ($recipients->isEmpty()) {
                Log::warning('No recipients were available for an appointment lifecycle notification.', [
                    'appointment_id' => $appointment->id,
                    'hospital_id' => $this->hospitalId,
                    'action' => $this->action,
                ]);

                return;
            }

            Notification::send(
                $recipients,
                new AppointmentLifecycleNotification($appointment, $this->action, $this->reason),
            );

            $message = sprintf(
                'Appointment #%d was %s. Scheduled for %s at %s.',
                $appointment->id,
                $this->action,
                $appointment->preferred_date?->format('d/m/Y') ?? 'a new date',
                $appointment->slot_start_time ? substr((string) $appointment->slot_start_time, 0, 5) : 'a new time',
            );
            foreach ($recipients->pluck('phone')->filter()->unique() as $phone) {
                if (! $notifications->sendSms((string) $phone, $message)) {
                    Log::warning('Appointment lifecycle SMS notification was not accepted by a provider.', [
                        'appointment_id' => $appointment->id,
                        'hospital_id' => $this->hospitalId,
                        'action' => $this->action,
                    ]);
                }
            }
        } catch (Throwable $exception) {
            Log::error('Unable to send an appointment lifecycle notification.', [
                'appointment_id' => $this->appointmentId,
                'hospital_id' => $this->hospitalId,
                'action' => $this->action,
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
