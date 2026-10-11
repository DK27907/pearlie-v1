<?php

namespace App\Http\Controllers\Patient;

use App\Http\Controllers\Controller;
use App\Models\AppointmentRequest;
use App\Services\AppointmentPolicyService;
use Illuminate\View\View;

class AppointmentController extends Controller
{
    public function index(): View
    {
        $appointments = AppointmentRequest::query()
            ->with(['doctor', 'service', 'mpesaPayment'])
            ->where('hospital_id', auth()->user()->hospital_id)
            ->where('patient_id', auth()->id())
            ->orderByDesc('preferred_date')
            ->orderByDesc('id')
            ->paginate(15);

        return view('patient.appointments.index', [
            'appointments' => $appointments,
        ]);
    }

    public function show(AppointmentRequest $appointment, AppointmentPolicyService $policy): View
    {
        abort_unless(
            (int) $appointment->patient_id === (int) auth()->id()
                && (int) $appointment->hospital_id === (int) auth()->user()->hospital_id,
            404,
        );

        $appointment->load(['doctor', 'service', 'mpesaPayment.events']);
        $lifecyclePolicy = $policy->evaluate($appointment);

        return view('patient.appointments.show', [
            'appointment' => $appointment,
            'lifecyclePolicy' => $lifecyclePolicy,
        ]);
    }
}
