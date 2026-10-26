<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AppointmentLifecycleException;
use App\Http\Controllers\Controller;
use App\Models\AppointmentRequest;
use App\Models\DoctorAvailability;
use App\Models\Hospital;
use App\Models\User;
use App\Services\AppointmentLifecycleService;
use App\Services\DoctorAvailabilityService;
use App\Services\NoShowService;
use App\Services\NotificationService;
use App\Services\PaymentLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                    ->orWhere('reason', 'like', "%$q%")
                    ->orWhere('booking_reference', 'like', "%$q%");
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
        fputcsv($handle, ['id', 'booking_reference', 'session_id', 'name', 'phone', 'preferred_date', 'reason', 'status', 'created_at']);
        foreach ($items as $i) {
            fputcsv($handle, [$i->id, $i->booking_reference, $i->session_id, $i->name, $i->phone, $i->preferred_date, $i->reason, $i->status, $i->created_at]);
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
        abort_unless(in_array($appt->status, [
            AppointmentRequest::STATUS_PENDING,
            AppointmentRequest::STATUS_CONFIRMED,
            AppointmentRequest::STATUS_EXPIRED,
        ], true), 422, 'This appointment cannot be confirmed again.');
        $wasConfirmed = $appt->status === AppointmentRequest::STATUS_CONFIRMED;
        if (! $wasConfirmed) {
            $appt->forceFill([
                'status' => AppointmentRequest::STATUS_CONFIRMED,
                'status_updated_at' => now(),
            ])->save();
        }

        $notified = $wasConfirmed || $this->notifyPatientOfConfirmation($appt);

        return redirect()->route('admin.appointments.show', $appt->id)->with(
            $notified ? 'status' : 'error',
            $notified ? 'Appointment confirmed and patient notified.' : 'Appointment confirmed, but the patient notification could not be sent.'
        );
    }

    public function show(int $id)
    {
        $appt = AppointmentRequest::query()->with(['receipt', 'paidBy'])->findOrFail($id);

        return view('admin.appointments.show', [
            'appt' => $appt,
            'doctors' => hospital()->doctors()->where('is_active', true)->orderBy('name')->get(),
            'repeatNoShowCount' => max(
                0,
                AppointmentRequest::getNoShowCount((string) $appt->phone) - ($appt->isNoShow() ? 1 : 0),
            ),
        ]);
    }

    public function markPaid(
        Request $request,
        int $id,
        PaymentLifecycleService $payments,
    ): RedirectResponse {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'payment_method' => ['required', 'string', Rule::in(['cash', 'card', 'bank', 'other'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $cashier = $request->user();
        abort_unless($cashier instanceof User, 403);
        $appointment = AppointmentRequest::query()->findOrFail($id);
        $receipt = $payments->markPaidAtHospital(
            $appointment,
            (float) $validated['amount'],
            $validated['payment_method'],
            $cashier,
            $validated['notes'] ?? null,
        );

        return redirect()
            ->route('admin.appointments.show', $appointment->id)
            ->with('status', "Marked as paid, receipt #{$receipt->receipt_number} generated.");
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

    public function updateStatus(
        Request $request,
        int $id,
        AppointmentLifecycleService $lifecycle,
        DoctorAvailabilityService $availability,
    ): RedirectResponse {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(array_diff(
                AppointmentRequest::statuses(),
                [AppointmentRequest::STATUS_NO_SHOW],
            ))],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $appt = AppointmentRequest::findOrFail($id);
        if ($validated['status'] === AppointmentRequest::STATUS_CANCELLED) {
            try {
                $lifecycle->cancel(
                    $appt->id,
                    (int) $appt->hospital_id,
                    $request->user(),
                    staffOverride: true,
                    reason: $validated['reason'] ?? 'Cancelled by hospital administrator',
                );
            } catch (AppointmentLifecycleException $exception) {
                return back()->withErrors(['status' => $exception->getMessage()]);
            }

            return redirect()->route('admin.appointments.show', $appt->id)->with('status', 'Appointment cancelled by hospital staff.');
        }
        try {
            [$appt, $wasConfirmed] = DB::transaction(function () use (
                $id,
                $validated,
                $availability,
            ): array {
                $appointment = AppointmentRequest::query()
                    ->lockForUpdate()
                    ->findOrFail($id);
                if ($appointment->status === AppointmentRequest::STATUS_CANCELLED
                    && $validated['status'] !== AppointmentRequest::STATUS_CANCELLED
                ) {
                    throw new AppointmentLifecycleException('Cancelled appointments cannot be reactivated.');
                }

                $wasConfirmed = $appointment->status === AppointmentRequest::STATUS_CONFIRMED;
                $wasCapacityActive = in_array($appointment->status, [
                    AppointmentRequest::STATUS_PENDING,
                    AppointmentRequest::STATUS_CONFIRMED,
                ], true);
                $willCapacityBeActive = in_array($validated['status'], [
                    AppointmentRequest::STATUS_PENDING,
                    AppointmentRequest::STATUS_CONFIRMED,
                ], true);

                if (! $wasCapacityActive && $willCapacityBeActive) {
                    if (! $appointment->doctor_id
                        || ! $appointment->preferred_date
                        || ! $appointment->slot_start_time
                    ) {
                        throw new AppointmentLifecycleException('This appointment has no assigned slot to reactivate.');
                    }

                    $date = CarbonImmutable::parse($appointment->preferred_date);
                    $doctorAvailability = DoctorAvailability::query()
                        ->where('hospital_id', $appointment->hospital_id)
                        ->where('doctor_id', $appointment->doctor_id)
                        ->where('day_of_week', $date->dayOfWeek)
                        ->where('is_active', true)
                        ->lockForUpdate()
                        ->get()
                        ->first(fn (DoctorAvailability $record): bool => collect($record->generateSlots())
                            ->contains(fn (array $slot): bool => $slot['start'] === CarbonImmutable::parse(
                                (string) $appointment->slot_start_time,
                            )->format('H:i')));
                    if (! $doctorAvailability) {
                        throw new AppointmentLifecycleException('The appointment slot is no longer available.');
                    }

                    $slotCapacity = $availability->reserveSlotCapacity(
                        Hospital::query()->findOrFail($appointment->hospital_id),
                        (int) $appointment->doctor_id,
                        $date,
                        (string) $appointment->slot_start_time,
                        (int) $doctorAvailability->max_patients_per_slot,
                    );
                    if (! $slotCapacity) {
                        throw new AppointmentLifecycleException('The appointment slot is at capacity.');
                    }
                }

                $appointment->status = $validated['status'];
                $appointment->save();
                if ($wasCapacityActive && ! $willCapacityBeActive
                    && $appointment->doctor_id
                    && $appointment->preferred_date
                    && $appointment->slot_start_time
                ) {
                    $availability->releaseSlotCapacity(
                        (int) $appointment->hospital_id,
                        (int) $appointment->doctor_id,
                        $appointment->preferred_date->format('Y-m-d'),
                        (string) $appointment->slot_start_time,
                    );
                }

                return [$appointment, $wasConfirmed];
            });
        } catch (AppointmentLifecycleException $exception) {
            return back()->withErrors(['status' => $exception->getMessage()]);
        }

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
            $reference = filled($appointment->booking_reference)
                ? ' (reference '.$appointment->booking_reference.')'
                : '';
            $message = sprintf(
                'Hello %s, your %s appointment request%s has been confirmed. Our team will contact you with the final details.',
                $appointment->name ?: 'Patient',
                pearlie_config('hospital.name'),
                $reference,
            );

            return app(NotificationService::class)->sendSms($appointment->phone, $message, $appointment->id);
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
