<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Escalation;
use App\Models\Conversation;
use Illuminate\Validation\Rule;
use App\Services\EscalationService;

class EscalationController extends Controller
{
    protected EscalationService $escalationService;

    public function __construct(EscalationService $escalationService)
    {
        $this->escalationService = $escalationService;
    }

    public function index(Request $request)
    {
        $query = Escalation::query();

        if ($q = $request->input('q')) {
            $query->where('user_message', 'like', '%' . $q . '%');
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $sort = $request->input('sort', 'created_at');
        $sort = in_array($sort, ['id', 'status', 'created_at'], true) ? $sort : 'created_at';
        $direction = $request->input('dir', 'desc');
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc';

        $escalations = $query->orderBy($sort, $direction)->paginate(20)->appends($request->except('page'));

        return view('admin.escalations.index', compact('escalations'));
    }

    public function exportCsv()
    {
        $items = Escalation::orderBy('created_at','desc')->get();
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['id', 'session_id', 'user_message', 'ai_response', 'status', 'created_at']);
        foreach ($items as $i) {
            fputcsv($handle, [$i->id, $i->session_id, $i->user_message, $i->ai_response, $i->status, $i->created_at]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="escalations-all.csv"',
        ]);
    }

    public function show(int $id)
    {
        $esc = Escalation::findOrFail($id);

        // Get recent conversation context
        $conversation = Conversation::where('session_id', $esc->session_id)
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get()
            ->reverse();

        return view('admin.escalations.show', compact('esc', 'conversation'));
    }

    public function updateStatus(Request $request, int $id)
    {
        $request->validate([
            'status' => 'required|string|in:pending,in_progress,resolved,closed',
        ]);

        $esc = Escalation::findOrFail($id);
        $esc->status = $request->input('status');
        $esc->save();

        return redirect()->route('admin.escalations.show', $esc->id)->with('status', 'Escalation status updated.');
    }

    public function resend(int $id)
    {
        $esc = Escalation::findOrFail($id);

        try {
            $this->escalationService->resendEscalationNotification($esc);
            return redirect()->route('admin.escalations.show', $esc->id)->with('status', 'Notification resend queued.');
        } catch (\Throwable $e) {
            return redirect()->route('admin.escalations.show', $esc->id)->with('error', 'Failed to resend notification: ' . $e->getMessage());
        }
    }

    public function bulkAction(Request $request)
    {
        $request->validate([
            'action' => 'required|string|in:update_status,delete,export',
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:escalations,id'],
            'status' => ['required_if:action,update_status', Rule::in(Escalation::statuses())],
        ]);

        $ids = $request->input('ids');
        $action = $request->input('action');

        if ($action === 'update_status') {
            $status = $request->input('status');
            Escalation::whereIn('id', $ids)->update(['status' => $status]);
            return redirect()->back()->with('status', 'Statuses updated.');
        }

        if ($action === 'delete') {
            \App\Models\Escalation::whereIn('id', $ids)->delete();
            return redirect()->back()->with('status', 'Selected escalations deleted.');
        }

        if ($action === 'export') {
            $items = \App\Models\Escalation::whereIn('id', $ids)->get();
            $handle = fopen('php://temp', 'r+');
            fputcsv($handle, ['id', 'session_id', 'user_message', 'ai_response', 'status', 'created_at']);
            foreach ($items as $i) {
                fputcsv($handle, [$i->id, $i->session_id, $i->user_message, $i->ai_response, $i->status, $i->created_at]);
            }
            rewind($handle);
            $csv = stream_get_contents($handle);
            fclose($handle);

            return response($csv, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="escalations-export.csv"',
            ]);
        }

        return redirect()->back();
    }
}
