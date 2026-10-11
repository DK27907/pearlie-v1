<?php

namespace App\Http\Controllers\Patient;

use App\Exceptions\AppointmentLifecycleException;
use App\Http\Controllers\Controller;
use App\Models\AppointmentRequest;
use App\Services\AppointmentLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AppointmentCancelController extends Controller
{
    public function store(
        Request $request,
        int $id,
        AppointmentLifecycleService $lifecycle,
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $user = $request->user();
        $appointment = AppointmentRequest::query()
            ->where('hospital_id', $user->hospital_id)
            ->where('patient_id', $user->id)
            ->findOrFail($id);

        try {
            $result = $lifecycle->cancel(
                $appointment->id,
                (int) $user->hospital_id,
                $user,
                reason: $validated['reason'] ?? null,
            );
        } catch (AppointmentLifecycleException $exception) {
            return back()->withErrors(['appointment' => $exception->getMessage()]);
        }

        $message = $result->paymentOutcome === 'refund_pending'
            ? 'Appointment cancelled. Any refundable balance is pending manual payment review.'
            : 'Appointment cancelled.';
        if ($result->forfeitedAmount > 0) {
            $message .= ' KSh '.number_format($result->forfeitedAmount, 2).' was retained under the hospital cancellation policy.';
        }

        return redirect()->route('appointments.show', $appointment)->with('status', $message);
    }
}
