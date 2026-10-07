<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Escalation;
use App\Services\EscalationService;
use App\Services\EscalationConflictException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class EscalationController extends Controller
{
    public function __construct(private readonly EscalationService $escalationService) {}

    public function index(Request $request): View|Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['all', ...Escalation::statuses()])],
        ]);
        $filters['status'] = ($filters['status'] ?? 'all') === 'all' ? null : $filters['status'];

        $data = [
            'escalations' => $this->escalationService->queue($filters),
            'queueRoutePrefix' => 'admin',
        ];

        return $request->boolean('_partial')
            ? response()->view('escalations.queue', $data)
            : view('escalations.index', $data);
    }

    public function exportCsv(): Response
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

    public function show(int $id): View
    {
        $esc = Escalation::findOrFail($id);
        $esc->load(['assignedWorker', 'resolvedBy', 'latestAppointment', 'latestConversation', 'latestScoredConversation']);

        return view('escalations.show', [
            'escalation' => $esc,
            'conversation' => $this->escalationService->conversation($esc),
            'queueRoutePrefix' => 'admin',
        ]);
    }

    public function claim(Request $request, int $id): RedirectResponse
    {
        $escalation = Escalation::findOrFail($id);

        try {
            $result = $this->escalationService->claim($escalation, $request->user());
        } catch (EscalationConflictException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $message = $result['patient_notified']
            ? 'Escalation claimed. The patient has been notified.'
            : 'Escalation claimed. No patient phone is available, or messaging could not be delivered.';

        return redirect()->route('admin.escalations.show', $id)->with('status', $message);
    }

    public function resolve(Request $request, int $id): RedirectResponse
    {
        $this->escalationService->resolve(Escalation::findOrFail($id), $request->user());

        return redirect()->route('admin.escalations.show', $id)->with('status', 'Escalation resolved.');
    }

    public function reply(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'channel' => ['required', Rule::in(['whatsapp', 'sms'])],
        ]);

        try {
            $this->escalationService->reply(
                Escalation::findOrFail($id),
                $request->user(),
                $validated['message'],
                $validated['channel'],
            );
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['message' => $exception->getMessage()]);
        }

        return redirect()->route('admin.escalations.show', $id)->with('status', 'Reply sent to the patient.');
    }

    public function updateStatus(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(Escalation::statuses())],
        ]);
        $escalation = Escalation::findOrFail($id);

        if ($validated['status'] === Escalation::STATUS_IN_PROGRESS) {
            return $this->claim($request, $id);
        }

        if ($validated['status'] === Escalation::STATUS_RESOLVED) {
            return $this->resolve($request, $id);
        }

        $escalation->forceFill([
            'status' => $validated['status'],
            'assigned_worker_id' => null,
            'claimed_at' => null,
            'resolved_by_id' => null,
            'resolved_at' => null,
        ])->save();

        return redirect()->route('admin.escalations.show', $id)->with('status', 'Escalation status updated.');
    }

    public function resend(int $id): RedirectResponse
    {
        $escalation = Escalation::findOrFail($id);
        $this->escalationService->resendEscalationNotification($escalation);

        return redirect()->route('admin.escalations.show', $id)->with('status', 'Escalation notifications were queued.');
    }

    public function bulkAction(Request $request): Response|RedirectResponse
    {
        $validated = $request->validate([
            'action' => 'required|string|in:update_status,delete,export',
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:escalations,id'],
            'status' => ['required_if:action,update_status', Rule::in(Escalation::statuses())],
        ]);

        $ids = $validated['ids'];
        $action = $validated['action'];

        if ($action === 'update_status') {
            foreach (Escalation::query()->whereIn('id', $ids)->get() as $escalation) {
                if ($validated['status'] === Escalation::STATUS_IN_PROGRESS) {
                    $this->escalationService->claim($escalation, $request->user());
                } elseif ($validated['status'] === Escalation::STATUS_RESOLVED) {
                    $this->escalationService->resolve($escalation, $request->user());
                } else {
                    $escalation->forceFill([
                        'status' => $validated['status'],
                        'assigned_worker_id' => null,
                        'claimed_at' => null,
                        'resolved_by_id' => null,
                        'resolved_at' => null,
                    ])->save();
                }
            }

            return redirect()->back()->with('status', 'Statuses updated.');
        }

        if ($action === 'delete') {
            Escalation::query()->whereIn('id', $ids)->delete();

            return redirect()->back()->with('status', 'Selected escalations deleted.');
        }

        if ($action === 'export') {
            $items = Escalation::query()->whereIn('id', $ids)->get();
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
