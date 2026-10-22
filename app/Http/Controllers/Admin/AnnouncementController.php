<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Hospital;
use App\Services\AnnouncementService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class AnnouncementController extends Controller
{
    public function index(): View
    {
        $announcements = $this->currentHospital()->announcements()
            ->with('sentBy:id,name')
            ->withCount('recipients')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return view('admin.announcements.index', compact('announcements'));
    }

    public function create(): View
    {
        $hospital = $this->currentHospital();

        return view('admin.announcements.create', [
            'doctors' => $hospital->doctors()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'services' => $hospital->services()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function preview(Request $request, AnnouncementService $announcements): JsonResponse
    {
        $validated = $this->validatedAnnouncement($request, scheduled: false);
        $hospital = $this->currentHospital();
        $announcement = new Announcement([
            'hospital_id' => $hospital->id,
            'message' => $validated['message'],
            'audience_filter' => $this->audienceFilter($validated),
        ]);
        $appointment = $announcements->buildRecipients($announcement)->first();
        $sample = $appointment
            ? $announcement->renderFor(
                $appointment->name,
                $appointment->doctor?->name ?? 'the hospital team',
                trim(($appointment->preferred_date?->format('d M Y') ?? 'Not provided').' '.(
                    $appointment->slot_start_time
                        ? CarbonImmutable::parse($appointment->slot_start_time)->format('g:i A')
                        : 'Not provided'
                )),
            )
            : $announcement->renderFor(
                'Test Patient',
                'Dr. Amina Njeri',
                now()->addDay()->format('d M Y').' 10:30 AM',
            );

        return response()->json([
            'preview' => $sample,
            'recipient_found' => $appointment !== null,
        ]);
    }

    public function store(Request $request, AnnouncementService $announcements): RedirectResponse
    {
        $validated = $this->validatedAnnouncement($request, scheduled: true);
        $hospital = $this->currentHospital();
        $scheduledFor = ($validated['send_option'] ?? 'now') === 'scheduled'
            ? CarbonImmutable::parse($validated['scheduled_for'])
            : null;
        $announcement = $hospital->announcements()->create([
            'sent_by_user_id' => $request->user()->id,
            'type' => 'announcement',
            'message' => $validated['message'],
            'audience_filter' => $this->audienceFilter($validated),
            'channel' => $validated['channel'],
            'scheduled_for' => $scheduledFor,
            'status' => $scheduledFor?->isFuture() ? 'scheduled' : 'draft',
        ]);

        if (! $scheduledFor?->isFuture()) {
            try {
                $announcements->sendAnnouncement($announcement);
                $announcement->refresh();
                if ($announcement->status === 'failed') {
                    return redirect()
                        ->route('admin.announcements.show', $announcement)
                        ->with('error', 'Announcement delivery failed for one or more recipients.');
                }
            } catch (Throwable $exception) {
                Log::error('An admin announcement could not be sent.', [
                    'announcement_id' => $announcement->id,
                    'hospital_id' => $hospital->id,
                    'exception' => $exception,
                ]);

                return redirect()
                    ->route('admin.announcements.show', $announcement)
                    ->with('error', 'Announcement could not be sent. Review the delivery status and logs.');
            }
        }

        return redirect()
            ->route('admin.announcements.show', $announcement)
            ->with('status', $scheduledFor?->isFuture()
                ? 'Announcement scheduled.'
                : 'Announcement dispatch completed.');
    }

    public function show(int $announcement): View
    {
        $record = $this->currentHospital()->announcements()
            ->with('recipients.appointment')
            ->findOrFail($announcement);

        return view('admin.announcements.show', ['announcement' => $record]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAnnouncement(Request $request, bool $scheduled): array
    {
        $hospital = $this->currentHospital();
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'audience' => ['required', Rule::in(['tomorrow', 'doctor', 'service', 'upcoming'])],
            'doctor_id' => [
                Rule::requiredIf($request->input('audience') === 'doctor'),
                'nullable',
                Rule::exists('users', 'id')->where('hospital_id', $hospital->id),
            ],
            'service_id' => [
                Rule::requiredIf($request->input('audience') === 'service'),
                'nullable',
                Rule::exists('services', 'id')->where('hospital_id', $hospital->id),
            ],
            'channel' => ['required', Rule::in(['sms', 'whatsapp', 'both'])],
            'send_option' => [$scheduled ? 'nullable' : 'sometimes', Rule::in(['now', 'scheduled'])],
            'scheduled_for' => [
                Rule::requiredIf($scheduled && $request->input('send_option') === 'scheduled'),
                'nullable',
                'date',
                'after:now',
            ],
        ]);

        if (($validated['audience'] ?? null) === 'doctor') {
            abort_unless($hospital->doctors()->whereKey($validated['doctor_id'])->exists(), 422);
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function audienceFilter(array $validated): array
    {
        return [
            'appointment_date' => match ($validated['audience']) {
                'upcoming' => 'upcoming',
                default => 'tomorrow',
            },
            'doctor_id' => $validated['audience'] === 'doctor' ? (int) $validated['doctor_id'] : null,
            'service_id' => $validated['audience'] === 'service' ? (int) $validated['service_id'] : null,
        ];
    }

    private function currentHospital(): Hospital
    {
        $hospital = hospital();
        abort_unless($hospital instanceof Hospital, 403, 'No hospital is bound to this request.');

        return $hospital;
    }
}
