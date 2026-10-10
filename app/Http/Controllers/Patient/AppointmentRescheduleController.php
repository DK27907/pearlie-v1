<?php

namespace App\Http\Controllers\Patient;

use App\Exceptions\AppointmentLifecycleException;
use App\Http\Controllers\Controller;
use App\Models\AppointmentRequest;
use App\Models\User;
use App\Services\AppointmentLifecycleService;
use App\Services\AppointmentPolicyService;
use App\Services\DoctorAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppointmentRescheduleController extends Controller
{
    public function create(
        Request $request,
        int $id,
        AppointmentPolicyService $policy,
        DoctorAvailabilityService $availability,
    ): View {
        $appointment = $this->patientAppointment($request, $id);
        $decision = $policy->evaluate($appointment);
        abort_unless($decision->canReschedule, 403);

        $date = $request->query('date', CarbonImmutable::tomorrow()->toDateString());
        $validated = validator(['date' => $date], [
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.now()->addYear()->toDateString()],
        ])->validate();
        $doctorSlots = $availability->getAllDoctorsWithSlots($validated['date']);
        $requiredSpecialty = $appointment->service?->requires_specialty;
        if (filled($requiredSpecialty)) {
            $matchingDoctorSlots = collect($doctorSlots)
                ->filter(fn (array $doctorSlot): bool => $availability->doctorMatchesSpecialty(
                    $doctorSlot['doctor'],
                    $requiredSpecialty,
                ))
                ->values();

            if ($matchingDoctorSlots->isNotEmpty()) {
                $doctorSlots = $matchingDoctorSlots->all();
            }
        }

        return view('patient.appointments.reschedule', [
            'appointment' => $appointment,
            'date' => $validated['date'],
            'doctorSlots' => $doctorSlots,
        ]);
    }

    public function store(
        Request $request,
        int $id,
        AppointmentLifecycleService $lifecycle,
    ): RedirectResponse {
        $user = $request->user();
        $appointment = $this->patientAppointment($request, $id);
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.now()->addYear()->toDateString()],
            'slot' => ['required', 'regex:/^\d+\|(?:[01]\d|2[0-3]):[0-5]\d$/'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        [$doctorId, $time] = explode('|', $validated['slot'], 2);

        $doctorExists = User::query()
            ->where('hospital_id', $user->hospital_id)
            ->where('is_doctor', true)
            ->where('is_active', true)
            ->whereKey($doctorId)
            ->exists();
        if (! $doctorExists) {
            return back()->withErrors(['slot' => 'Select an active doctor at this hospital.'])->withInput();
        }

        try {
            $lifecycle->reschedule(
                $appointment->id,
                (int) $user->hospital_id,
                (int) $doctorId,
                $validated['date'],
                $time,
                $user,
                reason: $validated['reason'] ?? 'Patient self-service reschedule',
            );
        } catch (AppointmentLifecycleException $exception) {
            return back()->withErrors(['appointment' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('appointments.show', $appointment)->with('status', 'Appointment rescheduled successfully.');
    }

    private function patientAppointment(Request $request, int $id): AppointmentRequest
    {
        $user = $request->user();

        return AppointmentRequest::query()
            ->where('hospital_id', $user->hospital_id)
            ->where('patient_id', $user->id)
            ->findOrFail($id);
    }
}
