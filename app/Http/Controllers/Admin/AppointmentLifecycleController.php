<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AppointmentLifecycleException;
use App\Http\Controllers\Controller;
use App\Models\AppointmentRequest;
use App\Services\AppointmentLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AppointmentLifecycleController extends Controller
{
    public function cancel(Request $request, int $id, AppointmentLifecycleService $lifecycle): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $user = $request->user();
        $appointment = AppointmentRequest::query()
            ->where('hospital_id', $user->hospital_id)
            ->findOrFail($id);

        try {
            $lifecycle->cancel(
                $appointment->id,
                (int) $user->hospital_id,
                $user,
                staffOverride: true,
                reason: $validated['reason'] ?? 'Cancelled by hospital administrator',
            );
        } catch (AppointmentLifecycleException $exception) {
            return back()->withErrors(['appointment' => $exception->getMessage()]);
        }

        return redirect()->route('admin.appointments.show', $id)->with('status', 'Appointment cancelled by hospital staff.');
    }

    public function reschedule(Request $request, int $id, AppointmentLifecycleService $lifecycle): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'doctor_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.now()->addYear()->toDateString()],
            'time' => ['required', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $appointment = AppointmentRequest::query()
            ->where('hospital_id', $user->hospital_id)
            ->findOrFail($id);

        try {
            $lifecycle->reschedule(
                $appointment->id,
                (int) $user->hospital_id,
                (int) $validated['doctor_id'],
                $validated['date'],
                $validated['time'],
                $user,
                staffOverride: true,
                reason: $validated['reason'] ?? 'Rescheduled by hospital administrator',
            );
        } catch (AppointmentLifecycleException $exception) {
            return back()->withErrors(['appointment' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('admin.appointments.show', $id)->with('status', 'Appointment rescheduled by hospital staff.');
    }
}
