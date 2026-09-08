<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\AppointmentRequest;
use Illuminate\Support\Facades\Response;

class AppointmentController extends Controller
{
    public function index(Request $request)
    {
        $query = AppointmentRequest::query();

        if ($q = $request->input('q')) {
            $query->where(function($r) use ($q) {
                $r->where('name', 'like', "%$q%")
                  ->orWhere('phone', 'like', "%$q%")
                  ->orWhere('reason', 'like', "%$q%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $sort = $request->input('sort', 'created_at');
        $dir = $request->input('dir', 'desc');

        $appointments = $query->orderBy($sort, $dir)->paginate(20)->appends($request->except('page'));
        return view('admin.appointments.index', compact('appointments'));
    }

    public function exportCsv()
    {
        $items = AppointmentRequest::orderBy('created_at','desc')->get();
        $csv = "id,session_id,name,phone,preferred_date,reason,status,created_at\n";
        foreach ($items as $i) {
            $csv .= sprintf('%d,%s,%s,%s,%s,%s,%s,%s\n', $i->id, $i->session_id, str_replace(',', ' ', $i->name), str_replace(',', ' ', $i->phone), $i->preferred_date, str_replace(',', ' ', $i->reason), $i->status, $i->created_at);
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="appointments-all.csv"',
        ]);
    }
    public function confirm(int $id)
    {
        $appt = AppointmentRequest::findOrFail($id);
        $appt->status = 'confirmed';
        $appt->save();

        // Notify patient via SMS (if phone present)
        try {
            if ($appt->phone) {
                $message = sprintf('Hello %s, your appointment request (ID %d) has been confirmed. We will contact you with details.', $appt->name ?? 'Patient', $appt->id);
                app(\App\Services\NotificationService::class)->sendSms($appt->phone, $message);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to notify patient after confirm: ' . $e->getMessage());
        }

        return redirect()->route('admin.appointments.show', $appt->id)->with('status', 'Appointment confirmed and patient notified.');
    }

    public function show(int $id)
    {
        $appt = AppointmentRequest::findOrFail($id);
        return view('admin.appointments.show', compact('appt'));
    }

    public function updateStatus(Request $request, int $id)
    {
        $request->validate([
            'status' => 'required|string|in:pending,confirmed,cancelled,completed',
        ]);

        $appt = AppointmentRequest::findOrFail($id);
        $appt->status = $request->input('status');
        $appt->save();

        return redirect()->route('admin.appointments.show', $appt->id)->with('status', 'Appointment status updated.');
    }

    public function bulkAction(Request $request)
    {
        $request->validate([
            'action' => 'required|string|in:update_status,delete,export',
            'ids' => 'required|array',
            'status' => 'nullable|string',
        ]);

        $ids = $request->input('ids');
        $action = $request->input('action');

        if ($action === 'update_status') {
            $status = $request->input('status');
            AppointmentRequest::whereIn('id', $ids)->update(['status' => $status]);
            return redirect()->back()->with('status', 'Statuses updated.');
        }

        if ($action === 'delete') {
            AppointmentRequest::whereIn('id', $ids)->delete();
            return redirect()->back()->with('status', 'Selected appointments deleted.');
        }

        if ($action === 'export') {
            $items = AppointmentRequest::whereIn('id', $ids)->get();
            $csv = "id,session_id,name,phone,preferred_date,reason,status,created_at\n";
            foreach ($items as $i) {
                $csv .= sprintf('%d,%s,%s,%s,%s,%s,%s,%s\n', $i->id, $i->session_id, str_replace(',', ' ', $i->name), str_replace(',', ' ', $i->phone), $i->preferred_date, str_replace(',', ' ', $i->reason), $i->status, $i->created_at);
            }

            return response($csv, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="appointments-export.csv"',
            ]);
        }

        return redirect()->back();
    }
}
