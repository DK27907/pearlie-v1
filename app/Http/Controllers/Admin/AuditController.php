<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnnouncementRecipient;
use App\Models\AppointmentHistory;
use App\Models\AppointmentRequest;
use App\Models\MpesaPayment;
use App\Models\NotificationLog;
use App\Models\PaymentEvent;
use App\Models\Receipt;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $hospital = hospital();
        abort_unless($hospital, 404);

        $filters = $request->validate([
            'appointment_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $appointment = isset($filters['appointment_id'])
            ? AppointmentRequest::query()
                ->where('hospital_id', $hospital->id)
                ->findOrFail($filters['appointment_id'])
            : null;

        $appointmentId = $appointment?->id;
        $history = AppointmentHistory::query()
            ->where('hospital_id', $hospital->id)
            ->when($appointmentId, fn ($query) => $query->where('appointment_request_id', $appointmentId))
            ->with(['actor:id,name', 'appointment:id,name,preferred_date'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'history_page')
            ->withQueryString();

        $payments = MpesaPayment::query()
            ->where('hospital_id', $hospital->id)
            ->when($appointmentId, fn ($query) => $query->where('appointment_request_id', $appointmentId))
            ->with('appointment:id,name,preferred_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'payments_page')
            ->withQueryString();

        $paymentEvents = PaymentEvent::query()
            ->where('hospital_id', $hospital->id)
            ->when($appointmentId, function ($query) use ($appointmentId, $hospital): void {
                $query->whereHas('payment', function ($paymentQuery) use ($appointmentId, $hospital): void {
                    $paymentQuery
                        ->where('hospital_id', $hospital->id)
                        ->where('appointment_request_id', $appointmentId);
                });
            })
            ->with('payment:id,appointment_request_id,status')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'events_page')
            ->withQueryString();

        $receipts = Receipt::query()
            ->where('hospital_id', $hospital->id)
            ->when($appointmentId, fn ($query) => $query->where('appointment_request_id', $appointmentId))
            ->with(['appointment:id,name,preferred_date', 'issuedBy:id,name'])
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'receipts_page')
            ->withQueryString();

        $notifications = NotificationLog::query()
            ->where('hospital_id', $hospital->id)
            ->when($appointmentId, fn ($query) => $query->where('appointment_request_id', $appointmentId))
            ->with('appointment:id,name,preferred_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'notifications_page')
            ->withQueryString();

        $announcementRecipients = AnnouncementRecipient::query()
            ->whereHas('announcement', fn ($query) => $query->where('hospital_id', $hospital->id))
            ->when($appointmentId, fn ($query) => $query->where('appointment_request_id', $appointmentId))
            ->with([
                'announcement:id,hospital_id,type,channel',
                'appointment' => fn ($query) => $query->where('hospital_id', $hospital->id)
                    ->select('id', 'hospital_id', 'name', 'preferred_date'),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'announcement_recipients_page')
            ->withQueryString();

        return view('admin.audit.index', compact(
            'appointment',
            'filters',
            'history',
            'payments',
            'paymentEvents',
            'receipts',
            'notifications',
            'announcementRecipients',
        ));
    }
}
