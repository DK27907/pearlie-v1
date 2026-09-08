<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Escalation;
use App\Models\Conversation;
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
        $direction = $request->input('dir', 'desc');

        $escalations = $query->orderBy($sort, $direction)->paginate(20)->appends($request->except('page'));

        return view('admin.escalations.index', compact('escalations'));
    }

    public function exportCsv()
    {
        $items = Escalation::orderBy('created_at','desc')->get();
        $csv = "id,session_id,user_message,ai_response,status,created_at\n";
        foreach ($items as $i) {
            $csv .= sprintf('%d,%s,%s,%s,%s,%s\n', $i->id, $i->session_id, str_replace(',', ' ', $i->user_message), str_replace(',', ' ', $i->ai_response), $i->status, $i->created_at);
        }

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
            'ids' => 'required|array',
            'status' => 'nullable|string',
        ]);

        $ids = $request->input('ids');
        $action = $request->input('action');

        if ($action === 'update_status') {
            $status = $request->input('status');
            \App\Models\Escalation::whereIn('id', $ids)->update(['status' => $status]);
            return redirect()->back()->with('status', 'Statuses updated.');
        }

        if ($action === 'delete') {
            \App\Models\Escalation::whereIn('id', $ids)->delete();
            return redirect()->back()->with('status', 'Selected escalations deleted.');
        }

        if ($action === 'export') {
            $items = \App\Models\Escalation::whereIn('id', $ids)->get();
            $csv = "id,session_id,user_message,ai_response,status,created_at\n";
            foreach ($items as $i) {
                $csv .= sprintf('%d,%s,%s,%s,%s,%s\n', $i->id, $i->session_id, str_replace(',', ' ', $i->user_message), str_replace(',', ' ', $i->ai_response), $i->status, $i->created_at);
            }

            return response($csv, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="escalations-export.csv"',
            ]);
        }

        return redirect()->back();
    }
}
