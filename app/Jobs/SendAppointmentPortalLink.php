<?php

namespace App\Jobs;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Services\WhatsAppService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

class SendAppointmentPortalLink implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30];

    public function __construct(
        public readonly int $appointmentId,
        public readonly int $hospitalId,
    ) {}

    public function handle(WhatsAppService $whatsApp): void
    {
        $previousHospital = app()->bound('currentHospital') ? app('currentHospital') : null;

        try {
            $hospital = Hospital::withoutGlobalScopes()->findOrFail($this->hospitalId);
            app()->instance('currentHospital', $hospital);

            $appointment = AppointmentRequest::query()
                ->where('hospital_id', $this->hospitalId)
                ->with([
                    'doctor' => fn ($query) => $query->where('hospital_id', $this->hospitalId),
                    'service' => fn ($query) => $query->where('hospital_id', $this->hospitalId),
                ])
                ->findOrFail($this->appointmentId);

            if (blank($appointment->phone)) {
                Log::warning('Appointment portal link was not sent because the patient has no phone number.', [
                    'appointment_id' => $appointment->id,
                    'hospital_id' => $this->hospitalId,
                ]);

                return;
            }

            $expiresAt = CarbonImmutable::now()->addDays(7);
            $portalUrl = URL::temporarySignedRoute(
                'patient-portal.show',
                $expiresAt,
                ['appointment' => $appointment->id],
            );
            $message = implode("\n", [
                $appointment->status === AppointmentRequest::STATUS_CONFIRMED
                    ? 'Your appointment is confirmed.'
                    : 'Your appointment request has been received.',
                'Booking: '.$appointment->booking_reference,
                sprintf(
                    '%s with %s on %s at %s',
                    $appointment->service?->name ?? $appointment->reason,
                    $appointment->doctor?->name ?? 'your doctor',
                    $appointment->preferred_date?->format('D M j') ?? 'the scheduled date',
                    $appointment->slot_start_time
                        ? CarbonImmutable::parse($appointment->slot_start_time)->format('g:i A')
                        : 'the scheduled time',
                ),
                'Manage your appointment: '.$portalUrl,
            ]);

            if ($appointment->status === AppointmentRequest::STATUS_CONFIRMED && filled($appointment->intake_token)) {
                $message .= "\n\nBefore your visit, please fill this short form (takes 1 minute):\n"
                    .route('intake.show', ['token' => $appointment->intake_token]);
            }

            $result = $whatsApp->sendMessage($appointment->phone, $message, $appointment->id);
            if (! $result['success']) {
                Log::warning('Appointment portal link WhatsApp message was not accepted.', [
                    'appointment_id' => $appointment->id,
                    'hospital_id' => $this->hospitalId,
                    'message' => $result['message'],
                ]);
            }
        } catch (Throwable $exception) {
            Log::error('Unable to send an appointment portal link.', [
                'appointment_id' => $this->appointmentId,
                'hospital_id' => $this->hospitalId,
                'exception' => $exception,
            ]);

            throw $exception;
        } finally {
            if ($previousHospital !== null) {
                app()->instance('currentHospital', $previousHospital);
            } else {
                app()->forgetInstance('currentHospital');
            }
        }
    }
}
