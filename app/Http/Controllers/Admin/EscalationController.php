<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendEscalationReply;
use App\Models\Escalation;
use App\Models\EscalationReply;
use App\Services\EscalationConflictException;
use App\Services\EscalationService;
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
        $items = Escalation::orderBy('created_at', 'desc')->get();
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
        $currentHospital = hospital();
        abort_unless($currentHospital, 404);
        $doctors = $currentHospital->users()
            ->where('is_doctor', true)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['users.id', 'users.name']);

        return view('escalations.show', [
            'escalation' => $esc,
            'conversation' => $this->escalationService->conversation($esc),
            'replies' => $esc->replies()->latest('id')->get(),
            'queueRoutePrefix' => 'admin',
            'doctors' => $doctors,
        ]);
    }

    public function assign(Request $request, Escalation $escalation): RedirectResponse
    {
        $validated = $request->validate([
            'doctor_id' => ['required', 'integer'],
        ]);
        $currentHospital = hospital();
        abort_unless($currentHospital, 404);
        $doctor = $currentHospital->users()
            ->whereKey($validated['doctor_id'])
            ->where('is_doctor', true)
            ->where('is_active', true)
            ->firstOrFail();

        $escalation->update(['assigned_worker_id' => $doctor->id]);

        return redirect()
            ->route('admin.escalations.show', $escalation)
            ->with('status', 'Escalation assigned.');
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
            $reply = $this->escalationService->reply(
                Escalation::findOrFail($id),
                $request->user(),
                $validated['message'],
                $validated['channel'],
            );
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['message' => $exception->getMessage()]);
        }

        $status = $reply->fresh()->status === EscalationReply::STATUS_SENT
            ? 'Reply sent to the patient.'
            : 'Reply queued for delivery.';

        return redirect()->route('admin.escalations.show', $id)->with('status', $status);
    }

    public function retryReply(int $replyId): RedirectResponse
    {
        $hospital = hospital();
        abort_unless($hospital, 404);

        $reply = EscalationReply::query()
            ->where('hospital_id', $hospital->id)
            ->whereKey($replyId)
            ->firstOrFail();
        abort_unless($reply->status === EscalationReply::STATUS_FAILED, 409);

        $reply->forceFill([
            'status' => EscalationReply::STATUS_PENDING,
            'last_error' => null,
        ])->save();

        SendEscalationReply::dispatch($reply->id, (int) $reply->hospital_id)->afterCommit();

        return redirect()
            ->route('admin.escalations.show', $reply->escalation_id)
            ->with('status', 'Failed reply queued for retry.');
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
            'ids.*' => ['integer', 'distinct'],
            'status' => ['required_if:action,update_status', Rule::in(Escalation::statuses())],
        ]);

        $ids = $validated['ids'];
        $action = $validated['action'];
        $visibleIds = Escalation::query()->whereIn('id', $ids)->pluck('id')->all();
        abort_unless(count($visibleIds) === count($ids), 404);

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
