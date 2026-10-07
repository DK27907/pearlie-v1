<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Models\AppointmentRequest;
use App\Services\DoctorAvailabilityService;
use App\Services\DoctorDashboardService;
use App\Services\NoShowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DoctorAvailabilityService $availabilityService,
        private readonly DoctorDashboardService $dashboardService,
    ) {}

    public function index(Request $request): View
    {
        $data = $this->dashboardService->dashboardData($request->user());
        $data['today_available_slots'] = $this->availabilityService->getAvailableSlots(
            $request->user()->id,
            now()->toDateString(),
        );

        return view('doctor.dashboard', $data);
    }

    public function appointments(Request $request): View
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'string', 'in:'.implode(',', AppointmentRequest::statuses())],
        ]);

        return view('doctor.appointments', [
            'appointments' => $this->dashboardService->appointments($request->user(), $filters),
        ]);
    }

    public function appointmentDetails(Request $request, int $id): View
    {
        $appointment = $this->dashboardService->ownedAppointmentOrFail($request->user(), $id);

        return view('doctor.appointment-details', [
            'appointment' => $appointment,
            'repeatNoShowCount' => max(
                0,
                AppointmentRequest::getNoShowCount((string) $appointment->phone) - ($appointment->isNoShow() ? 1 : 0),
            ),
        ]);
    }

    public function markNoShow(Request $request, int $id, NoShowService $noShowService): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $appointment = $this->dashboardService->ownedAppointmentOrFail($request->user(), $id);

        $noShowService->markAsNoShow($appointment, $validated['reason'] ?? null);

        return redirect()
            ->route('doctor.appointments.show', $id)
            ->with('status', 'Appointment marked as a no-show.');
    }

    public function confirmAppointment(Request $request, int $id): RedirectResponse
    {
        $this->dashboardService->confirmAppointment($request->user(), $id);

        return redirect()
            ->route('doctor.appointments.show', $id)
            ->with('status', 'Appointment confirmed.');
    }

    public function completeAppointment(Request $request, int $id): RedirectResponse
    {
        $this->dashboardService->completeAppointment($request->user(), $id);

        return redirect()
            ->route('doctor.appointments.show', $id)
            ->with('status', 'Appointment marked as completed.');
    }

    public function availability(Request $request): View
    {
        $data = $this->dashboardService->availabilitySettings($request->user());
        $data['today_slots'] = $this->availabilityService->getAvailableSlots(
            $request->user()->id,
            now()->toDateString(),
        );

        return view('doctor.availability', $data);
    }

    public function updateAvailability(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'availabilities' => ['required', 'array', 'size:7'],
            'availabilities.*.day_of_week' => ['required', 'integer', 'between:0,6', 'distinct'],
            'availabilities.*.start_time' => ['required', 'date_format:H:i'],
            'availabilities.*.end_time' => ['required', 'date_format:H:i'],
            'availabilities.*.slot_duration_minutes' => ['required', 'integer', 'between:5,240'],
            'availabilities.*.max_patients_per_slot' => ['required', 'integer', 'between:1,50'],
            'availabilities.*.is_active' => ['sometimes', 'boolean'],
        ]);

        $validator = Validator::make($validated, []);
        $validator->after(function ($validator) use ($validated): void {
            foreach ($validated['availabilities'] as $index => $availability) {
                if ($availability['start_time'] >= $availability['end_time']) {
                    $validator->errors()->add("availabilities.{$index}.end_time", 'The end time must be later than the start time.');
                }
            }
        });
        $validator->validate();

        $this->dashboardService->updateAvailability($request->user(), $validated['availabilities']);

        return back()->with('status', 'Availability updated.');
    }

    public function markUnavailable(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->dashboardService->markUnavailable(
            $request->user(),
            $validated['date'],
            $validated['reason'] ?? null,
        );

        return back()->with('status', 'Unavailable date saved.');
    }

    public function removeUnavailable(Request $request, int $id): RedirectResponse
    {
        $this->dashboardService->removeUnavailable($request->user(), $id);

        return back()->with('status', 'Unavailable date removed.');
    }
}
