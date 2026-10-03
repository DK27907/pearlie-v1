<?php

namespace App\Services;

use App\Models\AppointmentRequest;
use App\Models\Conversation;
use App\Models\Escalation;
use App\Models\User;
use App\Notifications\EscalationPatientMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

class EscalationService
{
    public function __construct(
        private readonly EscalationNotificationService $notifications,
        private readonly NotificationService $notificationService,
        private readonly WhatsAppService $whatsApp,
    ) {}

    public function hasOpenPatientHandoff(string $sessionId): bool
    {
        return Escalation::query()
            ->where('session_id', $sessionId)
            ->whereIn('status', [Escalation::STATUS_PENDING, Escalation::STATUS_IN_PROGRESS])
            ->exists();
    }

    public function createEscalation(
        string $sessionId,
        string $userMessage,
        ?string $aiResponse = null,
        ?string $userPhone = null,
    ): Escalation {
        try {
            $patientPhone = $userPhone ?: $this->patientPhoneForSession($sessionId);
            $identity = $patientPhone ? $this->normalizePhoneForIdentity($patientPhone) : $sessionId;
            $phoneVariants = $patientPhone ? $this->phoneVariants($identity) : [];
            $lock = Cache::lock('escalation-rate-limit:'.hash('sha256', $identity), 10);

            $escalation = $lock->block(5, function () use ($sessionId, $userMessage, $aiResponse, $identity, $phoneVariants, $patientPhone): Escalation {
                $recent = Escalation::query()
                    ->where('created_at', '>=', now()->subMinutes(5))
                    ->where(function ($query) use ($sessionId, $identity, $phoneVariants): void {
                        $query->where('session_id', $sessionId);
                        if ($identity !== $sessionId) {
                            $matchingSessions = AppointmentRequest::query()
                                ->where(function ($appointments) use ($phoneVariants): void {
                                    $appointments->whereIn('phone', $phoneVariants)
                                        ->orWhereIn('mpesa_phone', $phoneVariants);
                                })
                                ->select('session_id');

                            $query->orWhereIn('session_id', $matchingSessions);
                        }
                    })
                    ->latest('id')
                    ->first();

                if ($recent) {
                    return $recent;
                }

                return Escalation::query()->create([
                    'session_id' => $sessionId,
                    'user_message' => $userMessage,
                    'user_phone' => $patientPhone,
                    'ai_response' => $aiResponse,
                    'status' => Escalation::STATUS_PENDING,
                ]);
            });

            if ($patientPhone && blank($escalation->user_phone)) {
                $escalation->forceFill(['user_phone' => $patientPhone])->save();
            }

            if ($escalation->wasRecentlyCreated) {
                $this->notifications->notifyHealthWorkers($escalation);
            }

            return $escalation;
        } catch (Throwable $exception) {
            Log::error('Unable to create or rate-limit an escalation.', [
                'session_id' => $sessionId,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    /**
     * @return array{response: string, confidence: float, source: string, escalated: bool, appointment_id: ?int}|null
     */
    public function handleActivePatientMessage(
        string $sessionId,
        string $message,
        string $channel,
    ): ?array {
        $escalation = Escalation::query()
            ->with('assignedWorker')
            ->where('session_id', $sessionId)
            ->whereIn('status', [Escalation::STATUS_PENDING, Escalation::STATUS_IN_PROGRESS])
            ->latest('id')
            ->first();

        if (! $escalation) {
            return null;
        }

        $isSwahili = (bool) preg_match('/\b(nataka|msaada|dharura|haraka|tafadhali|naomba|miadi)\b/iu', $message);
        $acknowledgement = $escalation->status === Escalation::STATUS_PENDING
            ? ($isSwahili
                ? 'Ombi lako la kuzungumza na mhudumu wa afya liko kwenye foleni. Ujumbe wako umetumwa kwa timu ya afya.'
                : 'Your request to speak with a health worker is in the queue. Your message has been shared with the care team.')
            : ($isSwahili
                ? 'Mhudumu wa afya anashughulikia mazungumzo haya. Ujumbe wako umetumwa kwake, naye atakujibu hivi karibuni.'
                : 'A health worker is handling this conversation. Your message has been shared with them, and they will reply shortly.');
        Conversation::query()->create([
            'session_id' => $sessionId,
            'user_message' => $message,
            'ai_response' => $acknowledgement,
            'confidence_score' => null,
            'channel' => 'human_handoff',
            'escalated' => true,
        ]);

        if ($escalation->assignedWorker) {
            try {
                Notification::send(
                    $escalation->assignedWorker,
                    new EscalationPatientMessage(
                        $escalation,
                        $message,
                        route('doctor.escalations.show', $escalation->id),
                    ),
                );
            } catch (Throwable $exception) {
                Log::error('Unable to notify the assigned worker of a patient reply.', [
                    'escalation_id' => $escalation->id,
                    'worker_id' => $escalation->assigned_worker_id,
                    'exception' => $exception,
                ]);
            }
        }

        return [
            'response' => $acknowledgement,
            'confidence' => 1.0,
            'source' => 'human_handoff',
            'escalated' => true,
            'appointment_id' => null,
        ];
    }

    /**
     * @param  array{q?: string, status?: string}  $filters
     */
    public function queue(array $filters = []): LengthAwarePaginator
    {
        $query = Escalation::query()
            ->with(['assignedWorker', 'latestConversation', 'latestScoredConversation', 'latestAppointment'])
            ->when($filters['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status))
            ->when($filters['q'] ?? null, function ($builder, $search) use ($filters): void {
                $search = '%'.addcslashes($search, '\\%_').'%';
                $phoneDigits = preg_replace('/\D+/', '', (string) $filters['q']) ?? '';
                $phoneSearches = [$search];
                if (str_starts_with($phoneDigits, '2547') && strlen($phoneDigits) === 12) {
                    $phoneSearches[] = '%0'.substr($phoneDigits, 3).'%';
                } elseif (str_starts_with($phoneDigits, '07') && strlen($phoneDigits) === 10) {
                    $phoneSearches[] = '%254'.substr($phoneDigits, 1).'%';
                }

                $builder->where(function ($builder) use ($search, $phoneSearches): void {
                    $builder->where('user_message', 'like', $search)
                        ->orWhere('session_id', 'like', $search)
                        ->orWhereHas('appointmentRequests', function ($appointments) use ($search, $phoneSearches): void {
                            $appointments->where('name', 'like', $search)
                                ->orWhere(function ($phones) use ($phoneSearches): void {
                                    foreach ($phoneSearches as $phoneSearch) {
                                        $phones->orWhere('phone', 'like', $phoneSearch)
                                            ->orWhere('mpesa_phone', 'like', $phoneSearch);
                                    }
                                });
                        });
                });
            });

        return $query
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END")
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(20)
            ->appends(array_filter($filters, fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * @return array{claimed: bool, patient_notified: bool}
     */
    public function claim(Escalation $escalation, User $worker): array
    {
        if (! $worker->isAdmin() && ! $worker->isDoctor()) {
            throw new AuthorizationException;
        }

        $updated = Escalation::query()
            ->whereKey($escalation->id)
            ->where('status', Escalation::STATUS_PENDING)
            ->update([
                'status' => Escalation::STATUS_IN_PROGRESS,
                'assigned_worker_id' => $worker->id,
                'claimed_at' => now(),
            ]);

        $escalation->refresh();

        if ($updated === 0) {
            if (
                $escalation->status === Escalation::STATUS_IN_PROGRESS
                && (int) $escalation->assigned_worker_id === $worker->id
            ) {
                return ['claimed' => false, 'patient_notified' => false];
            }

            throw new EscalationConflictException('This escalation has already been claimed or resolved.');
        }

        return [
            'claimed' => true,
            'patient_notified' => $this->notifyPatientOfClaim($escalation, $worker),
        ];
    }

    public function resolve(Escalation $escalation, User $worker): void
    {
        if (! $worker->isAdmin() && $escalation->assigned_worker_id !== $worker->id) {
            throw new AuthorizationException;
        }

        if ($escalation->status === Escalation::STATUS_RESOLVED) {
            return;
        }

        if ($escalation->status !== Escalation::STATUS_IN_PROGRESS && ! $worker->isAdmin()) {
            throw new EscalationConflictException('Claim this escalation before resolving it.');
        }

        $escalation->forceFill([
            'status' => Escalation::STATUS_RESOLVED,
            'resolved_by_id' => $worker->id,
            'resolved_at' => now(),
        ])->save();
    }

    public function reply(Escalation $escalation, User $worker, string $message, string $channel): void
    {
        if (! $worker->isAdmin() && $escalation->assigned_worker_id !== $worker->id) {
            throw new AuthorizationException;
        }

        $phone = $escalation->patient_phone;
        if (! $phone) {
            throw new RuntimeException('No patient phone number is available for this conversation.');
        }

        $botName = hospital()?->chatbotName() ?? 'Assistant';
        $body = sprintf('%s escalation #%d — %s', $botName, $escalation->id, $message);

        try {
            $sent = $channel === 'whatsapp'
                ? $this->whatsApp->sendMessage($phone, $body)['success']
                : $this->notificationService->sendSms($phone, $body);

            if (! $sent) {
                throw new RuntimeException('The selected patient messaging channel did not accept the reply.');
            }

            Conversation::query()->create([
                'session_id' => $escalation->session_id,
                'user_message' => '',
                'ai_response' => $body,
                'confidence_score' => null,
                'channel' => 'human',
                'escalated' => true,
            ]);
        } catch (Throwable $exception) {
            Log::error('Unable to send a health-worker reply to the patient.', [
                'escalation_id' => $escalation->id,
                'worker_id' => $worker->id,
                'channel' => $channel,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function conversation(Escalation $escalation): array
    {
        return Conversation::query()
            ->where('session_id', $escalation->session_id)
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function resendEscalationNotification(Escalation $escalation): void
    {
        $this->notifications->notifyHealthWorkers($escalation);
    }

    private function notifyPatientOfClaim(Escalation $escalation, User $worker): bool
    {
        $phone = $escalation->patient_phone;
        if (! $phone) {
            Log::warning('Patient could not be notified of escalation claim because no phone number is available.', [
                'escalation_id' => $escalation->id,
            ]);

            return false;
        }

        $botName = hospital()?->chatbotName() ?? 'Assistant';
        $message = sprintf(
            "👋 Hi %s, this is %s, a health worker at %s. I've read your conversation with %s. How can I help you?",
            $escalation->patient_name,
            $worker->name,
            pearlie_config('hospital.name'),
            $botName,
        );

        try {
            if ($this->whatsApp->sendMessage($phone, $message)['success']) {
                return true;
            }
        } catch (Throwable $exception) {
            Log::error('Unable to notify patient by WhatsApp after an escalation was claimed.', [
                'escalation_id' => $escalation->id,
                'exception' => $exception,
            ]);
        }

        try {
            if ($this->notificationService->sendSms($phone, $message)) {
                return true;
            }
        } catch (Throwable $exception) {
            Log::error('Unable to notify patient by SMS after an escalation was claimed.', [
                'escalation_id' => $escalation->id,
                'exception' => $exception,
            ]);
        }

        Log::warning('Patient was not reachable by WhatsApp or SMS after an escalation was claimed.', [
            'escalation_id' => $escalation->id,
        ]);

        return false;
    }

    private function patientPhoneForSession(string $sessionId): ?string
    {
        $phone = AppointmentRequest::query()
            ->where('session_id', $sessionId)
            ->latest('id')
            ->value('mpesa_phone');

        $phone ??= AppointmentRequest::query()
            ->where('session_id', $sessionId)
            ->latest('id')
            ->value('phone');

        if ($phone) {
            return $this->normalizePhoneForIdentity($phone);
        }

        return str_starts_with($sessionId, 'whatsapp:')
            ? $this->normalizePhoneForIdentity(substr($sessionId, strlen('whatsapp:')))
            : null;
    }

    private function normalizePhoneForIdentity(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '0')) {
            return '254'.substr($digits, 1);
        }

        if (strlen($digits) === 9 && str_starts_with($digits, '7')) {
            return '254'.$digits;
        }

        return $digits;
    }

    /**
     * @return array<int, string>
     */
    private function phoneVariants(string $normalized): array
    {
        $variants = [$normalized, '+'.$normalized];
        if (str_starts_with($normalized, '254')) {
            $variants[] = '0'.substr($normalized, 3);
        }

        return array_values(array_unique($variants));
    }
}
