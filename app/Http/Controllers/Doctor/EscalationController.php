<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Escalation;
use App\Services\EscalationConflictException;
use App\Services\EscalationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class EscalationController extends Controller
{
    public function __construct(private readonly EscalationService $escalations) {}

    public function index(Request $request): View|Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['all', ...Escalation::statuses()])],
        ]);
        $filters['status'] = ($filters['status'] ?? 'all') === 'all' ? null : $filters['status'];
        $data = [
            'escalations' => $this->escalations->queue($filters),
            'queueRoutePrefix' => 'doctor',
        ];

        return $request->boolean('_partial')
            ? response()->view('escalations.queue', $data)
            : view('escalations.index', $data);
    }

    public function show(int $id): View
    {
        $escalation = Escalation::query()
            ->with(['assignedWorker', 'resolvedBy', 'latestAppointment', 'latestConversation', 'latestScoredConversation'])
            ->findOrFail($id);

        return view('escalations.show', [
            'escalation' => $escalation,
            'conversation' => $this->escalations->conversation($escalation),
            'queueRoutePrefix' => 'doctor',
        ]);
    }

    public function claim(Request $request, int $id): RedirectResponse
    {
        try {
            $result = $this->escalations->claim(
                Escalation::findOrFail($id),
                $request->user(),
            );
        } catch (EscalationConflictException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $message = $result['patient_notified']
            ? 'Escalation claimed. The patient has been notified.'
            : 'Escalation claimed. No patient phone is available, or messaging could not be delivered.';

        return redirect()->route('doctor.escalations.show', $id)->with('status', $message);
    }

    public function resolve(Request $request, int $id): RedirectResponse
    {
        try {
            $this->escalations->resolve(Escalation::findOrFail($id), $request->user());
        } catch (EscalationConflictException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }

        return redirect()->route('doctor.escalations.show', $id)->with('status', 'Escalation resolved.');
    }

    public function reply(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'channel' => ['required', Rule::in(['whatsapp', 'sms'])],
        ]);

        try {
            $this->escalations->reply(
                Escalation::findOrFail($id),
                $request->user(),
                $validated['message'],
                $validated['channel'],
            );
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['message' => $exception->getMessage()]);
        }

        return redirect()->route('doctor.escalations.show', $id)->with('status', 'Reply sent to the patient.');
    }
}
