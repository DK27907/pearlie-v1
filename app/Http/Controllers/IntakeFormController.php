<?php

namespace App\Http\Controllers;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class IntakeFormController extends Controller
{
    public function show(string $token): View
    {
        $appointment = $this->appointmentForToken($token);
        abort_if($appointment->intake_completed_at !== null, 404);

        return view('portal.intake', ['appointment' => $appointment]);
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        $validated = $request->validate([
            'allergies' => ['nullable', 'string', 'max:5000'],
            'medications' => ['nullable', 'string', 'max:5000'],
            'symptoms' => ['required', 'string', 'max:5000'],
            'emergency_contact_name' => ['required', 'string', 'max:255'],
            'emergency_contact_phone' => ['required', 'string', 'max:40'],
        ]);
        $appointmentId = $this->appointmentIdFromToken($token);
        $appointment = DB::transaction(function () use ($appointmentId, $token, $validated): AppointmentRequest {
            $appointment = AppointmentRequest::withoutGlobalScope('hospital')
                ->whereKey($appointmentId)
                ->lockForUpdate()
                ->firstOrFail();
            $storedToken = $appointment->intake_token;
            abort_if(
                $appointment->intake_completed_at !== null
                    || ! is_string($storedToken)
                    || ! hash_equals($storedToken, $token),
                404,
            );

            $appointment->forceFill([
                'intake_data' => [
                    'allergies' => trim((string) ($validated['allergies'] ?? '')),
                    'medications' => trim((string) ($validated['medications'] ?? '')),
                    'symptoms' => trim($validated['symptoms']),
                    'emergency_contact_name' => trim($validated['emergency_contact_name']),
                    'emergency_contact_phone' => trim($validated['emergency_contact_phone']),
                ],
                'intake_completed_at' => now(),
                'intake_token' => null,
            ])->save();

            return $appointment;
        });

        return redirect()->route('intake.thank-you', ['appointment' => $appointment->id]);
    }

    public function thankYou(int $appointment): View
    {
        $record = AppointmentRequest::withoutGlobalScope('hospital')
            ->whereKey($appointment)
            ->firstOrFail();

        return view('portal.intake-thank-you', ['appointment' => $record]);
    }

    private function appointmentForToken(string $token): AppointmentRequest
    {
        $appointment = AppointmentRequest::withoutGlobalScope('hospital')
            ->whereKey($this->appointmentIdFromToken($token))
            ->firstOrFail();
        $hospital = Hospital::withoutGlobalScopes()->findOrFail($appointment->hospital_id);
        app()->instance('currentHospital', $hospital);

        abort_if(
            $appointment->intake_completed_at !== null
                || ! is_string($appointment->intake_token)
                || ! hash_equals($appointment->intake_token, $token),
            404,
        );

        return $appointment;
    }

    private function appointmentIdFromToken(string $token): int
    {
        abort_unless(preg_match('/^([1-9][0-9]*)-[A-Za-z0-9]{48}$/', $token, $matches) === 1, 404);

        return (int) $matches[1];
    }
}
