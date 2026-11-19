<?php

namespace App\Http\Controllers;

use App\Exceptions\AppointmentLifecycleException;
use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\Service;
use App\Models\User;
use App\Services\AppointmentLifecycleService;
use App\Services\DoctorAvailabilityService;
use App\Services\WhatsAppService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PatientPortalController extends Controller
{
    public function show(int $appointment): View
    {
        $appointment = $this->appointmentForPortal($appointment);
        $expiresAt = $this->signedExpiresAt();

        return view('portal.appointment', [
            'appointment' => $appointment,
            'hospital' => $appointment->hospital,
            'rescheduleUrl' => URL::temporarySignedRoute(
                'patient-portal.reschedule',
                $expiresAt,
                ['appointment' => $appointment->id],
            ),
            'cancelUrl' => URL::temporarySignedRoute(
                'patient-portal.cancel',
                $expiresAt,
                ['appointment' => $appointment->id],
            ),
            'calendarUrl' => URL::temporarySignedRoute(
                'patient-portal.calendar',
                $expiresAt,
                ['appointment' => $appointment->id],
            ),
        ]);
    }

    public function rescheduleForm(
        int $appointment,
        DoctorAvailabilityService $availability,
    ): View {
        $appointment = $this->appointmentForPortal($appointment);
        abort_unless(in_array($appointment->status, [
            AppointmentRequest::STATUS_PENDING,
            AppointmentRequest::STATUS_CONFIRMED,
        ], true), 422);

        $slots = $appointment->doctor_id
            ? $availability->getNextAvailableSlots((int) $appointment->doctor_id, 20)
            : [];
        $expiresAt = $this->signedExpiresAt();

        return view('portal.reschedule', [
            'appointment' => $appointment,
            'hospital' => $appointment->hospital,
            'slots' => $slots,
            'rescheduleUrl' => URL::temporarySignedRoute(
                'patient-portal.reschedule',
                $expiresAt,
                ['appointment' => $appointment->id],
            ),
            'showUrl' => URL::temporarySignedRoute(
                'patient-portal.show',
                $expiresAt,
                ['appointment' => $appointment->id],
            ),
        ]);
    }

    public function reschedule(
        Request $request,
        int $appointment,
        AppointmentLifecycleService $lifecycle,
        WhatsAppService $whatsApp,
    ): RedirectResponse {
        $validated = $request->validate([
            'date_time' => ['required', 'regex:/^[0-9]{4}-[0-9]{2}-[0-9]{2}\|(?:[01][0-9]|2[0-3]):[0-5][0-9]$/'],
        ]);
        [$date, $time] = explode('|', $validated['date_time'], 2);
        $appointment = $this->appointmentForPortal($appointment);

        try {
            $result = $lifecycle->reschedule(
                (int) $appointment->id,
                (int) $appointment->hospital_id,
                (int) $appointment->doctor_id,
                $date,
                $time,
                null,
            );
        } catch (AppointmentLifecycleException $exception) {
            return back()->withErrors(['slot' => $exception->getMessage()])->withInput();
        }

        $updated = $result->appointment;
        $notificationSent = $this->sendPatientMessage(
            $whatsApp,
            $updated,
            sprintf(
                'Your appointment has been rescheduled to %s at %s. Booking: %s.',
                $updated->preferred_date?->format('D M j'),
                CarbonImmutable::parse((string) $updated->slot_start_time)->format('g:i A'),
                $updated->booking_reference,
            ),
        );

        return redirect()->route('patient-portal.show', [
            'appointment' => $updated->id,
            ...$this->signedQuery('patient-portal.show', (int) $updated->id),
        ])->with(
            $notificationSent ? 'status' : 'warning',
            $notificationSent
                ? 'Your appointment has been rescheduled and a WhatsApp confirmation was sent.'
                : 'Your appointment was rescheduled, but we could not send the WhatsApp confirmation.',
        );
    }

    public function cancel(
        int $appointment,
        AppointmentLifecycleService $lifecycle,
        WhatsAppService $whatsApp,
    ): RedirectResponse {
        $appointment = $this->appointmentForPortal($appointment);

        try {
            $result = $lifecycle->cancelFromPortal(
                (int) $appointment->id,
                (int) $appointment->hospital_id,
            );
        } catch (AppointmentLifecycleException $exception) {
            return back()->withErrors(['cancellation' => $exception->getMessage()]);
        }

        $notificationSent = $this->sendPatientMessage(
            $whatsApp,
            $result->appointment,
            'Your appointment has been cancelled. Booking: '.$result->appointment->booking_reference.'.',
        );

        return redirect()->route('patient-portal.show', [
            'appointment' => $appointment->id,
            ...$this->signedQuery('patient-portal.show', (int) $appointment->id),
        ])->with(
            $notificationSent ? 'status' : 'warning',
            $notificationSent
                ? 'Your appointment has been cancelled and a WhatsApp confirmation was sent.'
                : 'Your appointment was cancelled, but we could not send the WhatsApp confirmation.',
        );
    }

    public function calendar(int $appointment): Response
    {
        $appointment = $this->appointmentForPortal($appointment);
        abort_unless($appointment->preferred_date && $appointment->slot_start_time, 422);

        $start = CarbonImmutable::parse(
            $appointment->preferred_date->format('Y-m-d').' '.$appointment->slot_start_time,
            config('app.timezone'),
        )->utc();
        $end = filled($appointment->slot_end_time)
            ? CarbonImmutable::parse(
                $appointment->preferred_date->format('Y-m-d').' '.$appointment->slot_end_time,
                config('app.timezone'),
            )->utc()
            : $start->addMinutes((int) ($appointment->hospital->slot_duration_minutes ?: 30));
        $summary = $this->escapeCalendarText(
            ($appointment->service?->name ?? $appointment->reason).' at '.$appointment->hospital->name,
        );
        $description = $this->escapeCalendarText(
            'Booking reference: '.$appointment->booking_reference
                .($appointment->doctor ? "\nDoctor: ".$appointment->doctor->name : ''),
        );
        $location = $this->escapeCalendarText(implode(', ', array_filter([
            $appointment->hospital->address,
            $appointment->hospital->city,
        ])));
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//MediDesk Assistant//Patient Portal//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:appointment-'.$appointment->id.'@'.parse_url(config('app.url'), PHP_URL_HOST),
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$start->format('Ymd\THis\Z'),
            'DTEND:'.$end->format('Ymd\THis\Z'),
            'SUMMARY:'.$summary,
            'DESCRIPTION:'.$description,
            'LOCATION:'.$location,
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);

        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="appointment-'.$appointment->id.'.ics"',
        ]);
    }

    private function appointmentForPortal(int $appointmentId): AppointmentRequest
    {
        $appointment = AppointmentRequest::withoutGlobalScope('hospital')
            ->whereKey($appointmentId)
            ->firstOrFail();
        $hospital = Hospital::withoutGlobalScopes()->findOrFail($appointment->hospital_id);
        app()->instance('currentHospital', $hospital);

        $appointment->setRelation('hospital', $hospital);
        $appointment->setRelation('doctor', $appointment->doctor_id
            ? User::withoutGlobalScope('hospital')
                ->where('hospital_id', $hospital->id)
                ->find($appointment->doctor_id)
            : null);
        $appointment->setRelation('service', $appointment->service_id
            ? Service::withoutGlobalScope('hospital')
                ->where('hospital_id', $hospital->id)
                ->find($appointment->service_id)
            : null);

        return $appointment;
    }

    private function sendPatientMessage(
        WhatsAppService $whatsApp,
        AppointmentRequest $appointment,
        string $message,
    ): bool {
        if (blank($appointment->phone)) {
            Log::warning('Appointment portal update was not sent because the patient has no phone number.', [
                'appointment_id' => $appointment->id,
                'hospital_id' => $appointment->hospital_id,
            ]);

            return false;
        }

        $result = $whatsApp->sendMessage($appointment->phone, $message, $appointment->id);

        return $result['success'];
    }

    /**
     * @return array{expires: int, signature: string}
     */
    private function signedQuery(string $route, int $appointmentId): array
    {
        $signedUrl = URL::temporarySignedRoute(
            $route,
            $this->signedExpiresAt(),
            ['appointment' => $appointmentId],
        );
        parse_str((string) parse_url($signedUrl, PHP_URL_QUERY), $query);

        return [
            'expires' => (int) ($query['expires'] ?? 0),
            'signature' => (string) ($query['signature'] ?? ''),
        ];
    }

    private function signedExpiresAt(): CarbonImmutable
    {
        $expires = request()->query('expires');

        return is_numeric($expires)
            ? CarbonImmutable::createFromTimestamp((int) $expires)
            : CarbonImmutable::now()->addDays(7);
    }

    private function escapeCalendarText(string $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n", "\r"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'],
            $value,
        );
    }
}
