<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppointmentRequest;
use App\Services\NoShowService;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    public function index(Request $request)
    {
        $query = AppointmentRequest::query();

        if ($q = $request->input('q')) {
            $query->where(function ($r) use ($q) {
                $r->where('name', 'like', "%$q%")
                    ->orWhere('phone', 'like', "%$q%")
                    ->orWhere('reason', 'like', "%$q%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $sort = $request->input('sort', 'created_at');
        $sort = in_array($sort, ['id', 'status', 'created_at', 'preferred_date'], true) ? $sort : 'created_at';
        $dir = $request->input('dir', 'desc');
        $dir = in_array($dir, ['asc', 'desc'], true) ? $dir : 'desc';

        $appointments = $query->orderBy($sort, $dir)->paginate(20)->appends($request->except('page'));

        return view('admin.appointments.index', compact('appointments'));
    }

    public function exportCsv()
    {
        $items = AppointmentRequest::orderBy('created_at', 'desc')->get();
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['id', 'session_id', 'name', 'phone', 'preferred_date', 'reason', 'status', 'created_at']);
        foreach ($items as $i) {
            fputcsv($handle, [$i->id, $i->session_id, $i->name, $i->phone, $i->preferred_date, $i->reason, $i->status, $i->created_at]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="appointments-all.csv"',
        ]);
    }

    public function confirm(int $id)
    {
        $appt = AppointmentRequest::findOrFail($id);
        abort_unless(
            $appt->status !== AppointmentRequest::STATUS_NO_SHOW,
            422,
            'A no-show appointment cannot be confirmed again.',
        );
        $wasConfirmed = $appt->status === AppointmentRequest::STATUS_CONFIRMED;
        $appt->status = AppointmentRequest::STATUS_CONFIRMED;
        $appt->save();

        $notified = $wasConfirmed || $this->notifyPatientOfConfirmation($appt);

        return redirect()->route('admin.appointments.show', $appt->id)->with(
            $notified ? 'status' : 'error',
            $notified ? 'Appointment confirmed and patient notified.' : 'Appointment confirmed, but the patient notification could not be sent.'
        );
    }

    public function show(int $id)
    {
        $appt = AppointmentRequest::findOrFail($id);

        return view('admin.appointments.show', [
            'appt' => $appt,
            'repeatNoShowCount' => max(
                0,
                AppointmentRequest::getNoShowCount((string) $appt->phone) - ($appt->isNoShow() ? 1 : 0),
            ),
        ]);
    }

    public function markNoShow(Request $request, int $id, NoShowService $noShowService): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $appointment = AppointmentRequest::findOrFail($id);

        $noShowService->markAsNoShow($appointment, $validated['reason'] ?? null);

        return redirect()
            ->route('admin.appointments.show', $id)
            ->with('status', 'Appointment marked as a no-show.');
    }

    public function updateStatus(Request $request, int $id)
    {
        $request->validate([
            'status' => ['required', 'string', Rule::in(array_diff(
                AppointmentRequest::statuses(),
                [AppointmentRequest::STATUS_NO_SHOW],
            ))],
        ]);

        $appt = AppointmentRequest::findOrFail($id);
        $wasConfirmed = $appt->status === AppointmentRequest::STATUS_CONFIRMED;
        $appt->status = $request->input('status');
        $appt->save();

        $statusMessage = 'Appointment status updated.';
        $flashType = 'status';
        if ($appt->status === AppointmentRequest::STATUS_CONFIRMED && ! $wasConfirmed) {
            $flashType = $this->notifyPatientOfConfirmation($appt) ? 'status' : 'error';
            $statusMessage = $flashType === 'status'
                ? 'Appointment confirmed and patient notified.'
                : 'Appointment confirmed, but the patient notification could not be sent.';
        }

        return redirect()->route('admin.appointments.show', $appt->id)->with($flashType, $statusMessage);
    }

    private function notifyPatientOfConfirmation(AppointmentRequest $appointment): bool
    {
        if (! $appointment->phone) {
            Log::warning('Appointment confirmed without a patient phone number.', ['appointment_id' => $appointment->id]);

            return false;
        }

        try {
            $message = sprintf(
                'Hello %s, your %s appointment request (ID %d) has been confirmed. Our team will contact you with the final details.',
                $appointment->name ?: 'Patient',
                pearlie_config('hospital.name'),
                $appointment->id,
            );

            return app(NotificationService::class)->sendSms($appointment->phone, $message);
        } catch (\Throwable $e) {
            Log::error('Failed to notify patient after appointment confirmation.', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function bulkAction(Request $request)
    {
        $request->validate([
            'action' => 'required|string|in:update_status,delete,export',
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:appointment_requests,id'],
            'status' => ['required_if:action,update_status', Rule::in(array_diff(
                AppointmentRequest::statuses(),
                [AppointmentRequest::STATUS_NO_SHOW],
            ))],
        ]);

        $ids = $request->input('ids');
        $action = $request->input('action');

        if ($action === 'update_status') {
            $status = $request->input('status');
            $appointments = AppointmentRequest::whereIn('id', $ids)->get();
            $notificationFailures = 0;

            foreach ($appointments as $appointment) {
                $wasConfirmed = $appointment->status === AppointmentRequest::STATUS_CONFIRMED;
                $appointment->status = $status;
                $appointment->save();

                if ($status === AppointmentRequest::STATUS_CONFIRMED && ! $wasConfirmed && ! $this->notifyPatientOfConfirmation($appointment)) {
                    $notificationFailures++;
                }
            }

            return redirect()->back()->with(
                $notificationFailures > 0 ? 'error' : 'status',
                $notificationFailures > 0
                    ? "Statuses updated, but {$notificationFailures} patient notification(s) could not be sent."
                    : ($status === AppointmentRequest::STATUS_CONFIRMED
                        ? 'Statuses updated and confirmed patients notified.'
                        : 'Statuses updated.')
            );
        }

        if ($action === 'delete') {
            AppointmentRequest::whereIn('id', $ids)->delete();

            return redirect()->back()->with('status', 'Selected appointments deleted.');
        }

        if ($action === 'export') {
            $items = AppointmentRequest::whereIn('id', $ids)->get();
            $handle = fopen('php://temp', 'r+');
            fputcsv($handle, ['id', 'session_id', 'name', 'phone', 'preferred_date', 'reason', 'status', 'created_at']);
            foreach ($items as $i) {
                fputcsv($handle, [$i->id, $i->session_id, $i->name, $i->phone, $i->preferred_date, $i->reason, $i->status, $i->created_at]);
            }
            rewind($handle);
            $csv = stream_get_contents($handle);
            fclose($handle);

            return response($csv, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="appointments-export.csv"',
            ]);
        }

        return redirect()->back();
    }
}
