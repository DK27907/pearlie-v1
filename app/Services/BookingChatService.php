<?php

namespace App\Services;

use App\Exceptions\AppointmentLifecycleException;
use App\Models\AppointmentRequest;
use App\Models\Conversation;
use App\Models\Hospital;
use App\Models\Service;
use App\Models\User;
use App\Services\Chat\ChatResponse;
use App\Support\DateParser;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class BookingChatService
{
    private const STATE_IDLE = 'IDLE';

    private const STATE_IDENTIFYING = 'IDENTIFYING';

    private const STATE_COLLECTING = 'COLLECTING';

    private const STATE_CONFIRMING = 'CONFIRMING';

    private const STATE_PAYMENT_PENDING = 'PAYMENT_PENDING';

    private const STATE_COMPLETED = 'COMPLETED';

    private const STATE_CANCELLED = 'CANCELLED';

    private const STATE_LIFECYCLE_SELECT = 'LIFECYCLE_SELECT';

    private const STATE_LIFECYCLE_CANCEL_CONFIRM = 'LIFECYCLE_CANCEL_CONFIRM';

    private const STATE_LIFECYCLE_RESCHEDULE_DATE = 'LIFECYCLE_RESCHEDULE_DATE';

    private const STATE_LIFECYCLE_RESCHEDULE_TIME = 'LIFECYCLE_RESCHEDULE_TIME';

    private const STATE_LIFECYCLE_RESCHEDULE_CONFIRM = 'LIFECYCLE_RESCHEDULE_CONFIRM';

    /**
     * @var array<string, string>
     */
    private const FIELD_PROMPTS = [
        'service_id' => 'What service do you need?',
        'doctor_id' => 'Which doctor would you prefer?',
        'date' => 'What date would you prefer? Use DD/MM/YYYY (e.g. 15/10/2026), YYYY-MM-DD, or say "tomorrow".',
        'time' => 'What time would you prefer?',
        'patient_name' => 'What is your full name?',
        'patient_phone' => 'What Kenyan mobile number should we use?',
    ];

    public function __construct(
        private readonly DoctorAvailabilityService $availability,
        private readonly MpesaService $mpesa,
        private readonly PearlieServiceV2 $assistant,
        private readonly KnowledgeBaseService $knowledgeBase,
        private readonly AppointmentPolicyService $appointmentPolicy,
        private readonly AppointmentLifecycleService $appointmentLifecycle,
    ) {}

    public function handle(string $channelId, string $message, string $channel = 'web'): ChatResponse
    {
        $hospital = hospital();
        if (! $hospital instanceof Hospital) {
            throw new RuntimeException('A hospital context is required to process a booking conversation.');
        }

        $sessionId = $this->sessionId($channelId, $channel);

        return Cache::lock(
            'booking-chat-state:'.hash('sha256', implode(':', [$hospital->id, $channel, $sessionId])),
            120,
        )->block(10, fn (): ChatResponse => $this->handleWithinSessionLock(
            $channelId,
            $sessionId,
            $message,
            $channel,
            $hospital,
        ));
    }

    private function handleWithinSessionLock(
        string $channelId,
        string $sessionId,
        string $message,
        string $channel,
        Hospital $hospital,
    ): ChatResponse {
        $previousState = $this->persistedState($channelId, $channel);
        $state = $this->reconcilePaymentState($previousState);

        if (($previousState['state'] ?? self::STATE_IDLE) === self::STATE_PAYMENT_PENDING
            && in_array($state['state'], [self::STATE_COMPLETED, self::STATE_CANCELLED], true)
        ) {
            $callbackResponse = $state['state'] === self::STATE_COMPLETED
                ? 'Payment received. Your appointment has been updated.'
                : 'The payment was not completed and the booking has been cancelled.';

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                $callbackResponse,
                $state,
                $state['appointment_id'],
            );
        }

        if (in_array($state['state'], [self::STATE_COMPLETED, self::STATE_CANCELLED], true)) {
            $state = $this->emptyState();
        }

        if (trim($message) === '') {
            return $this->respond(
                $sessionId,
                $channel,
                $message,
                'Please type a message so I can help.',
                $state,
            );
        }

        if (preg_match('/[\pL\pN]/u', $message) !== 1) {
            return $this->respond(
                $sessionId,
                $channel,
                $message,
                'Please send a question using words so I can help.',
                $state,
                source: 'clarification',
            );
        }

        if ($this->knowledgeBase->isEmergencyQuery($message)) {
            return $this->respond(
                $sessionId,
                $channel,
                $message,
                $this->knowledgeBase->search($message)
                    ?? 'For an emergency, contact the hospital emergency team immediately.',
                $state,
                source: 'emergency',
            );
        }

        $intent = $this->extractIntent($message, (string) $state['state']);

        if (in_array($state['state'], [
            self::STATE_LIFECYCLE_SELECT,
            self::STATE_LIFECYCLE_CANCEL_CONFIRM,
            self::STATE_LIFECYCLE_RESCHEDULE_DATE,
            self::STATE_LIFECYCLE_RESCHEDULE_TIME,
            self::STATE_LIFECYCLE_RESCHEDULE_CONFIRM,
        ], true)) {
            return $this->handleAppointmentLifecycleState(
                $sessionId,
                $channel,
                $message,
                $hospital,
                $state,
            );
        }

        if (in_array($intent, ['cancel_appointment', 'reschedule_appointment'], true)) {
            return $this->beginAppointmentLifecycle(
                $sessionId,
                $channel,
                $message,
                $hospital,
                $state,
                $intent === 'cancel_appointment' ? 'cancel' : 'reschedule',
            );
        }

        if ($state['state'] === self::STATE_PAYMENT_PENDING) {
            return $this->respond(
                $sessionId,
                $channel,
                $message,
                'Your booking is waiting for the M-Pesa payment callback.',
                $state,
                $state['appointment_id'],
                true,
            );
        }

        if ($intent === 'reset') {
            $state = $this->emptyState();

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                'Your booking details have been cleared. What would you like help with?',
                $state,
                source: 'booking_reset',
            );
        }

        if ($intent === 'cancel') {
            $state = $this->emptyState(self::STATE_CANCELLED);

            return $this->respond($sessionId, $channel, $message, 'Your booking has been cancelled.', $state);
        }

        if ($intent === 'book') {
            $state['escalation_pending'] = false;
            $state['pending_escalation_message'] = null;
        }

        if (($state['escalation_pending'] ?? false) && $this->extractPhone($message) !== null) {
            $pendingMessage = (string) ($state['pending_escalation_message'] ?? $message);
            app(EscalationService::class)->createEscalation(
                $sessionId,
                $pendingMessage,
                sprintf(
                    "Thank you. Your request has been passed to the %s team. I'm connecting you to a health worker now; someone will reach out shortly.",
                    $hospital->name,
                ),
                $this->extractPhone($message),
            );
            $state['escalation_pending'] = false;
            $state['pending_escalation_message'] = null;

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                sprintf(
                    "Thank you. Your request has been passed to the %s team. I'm connecting you to a health worker now; someone will reach out shortly.",
                    $hospital->name,
                ),
                $state,
                source: 'human_escalation',
                escalated: true,
            );
        }

        if ($intent === 'escalation') {
            return $this->handleExplicitHumanRequest($sessionId, $channel, $message, $state, $hospital);
        }

        if ($intent === 'continue_booking' && ! empty($state['state']) && $state['state'] !== self::STATE_IDLE) {
            $state['escalation_pending'] = false;

            return $this->advanceState($sessionId, $channel, $message, $hospital, $state);
        }

        // Handle yes/no clarification before delegating to assistant
        // This prevents "yes" or "no" in IDLE state from triggering escalation
        // But skip this check if in CONFIRMING state (handled specially below)
        if (in_array($intent, ['yes', 'no'], true) && $state['state'] !== self::STATE_CONFIRMING) {
            $response = $state['state'] === self::STATE_COLLECTING
                ? $this->promptForNextField($state, $hospital, $message, $sessionId, $channel)
                : 'There is no booking waiting for confirmation. Tell me what service or hospital information you need.';

            return $this->respond($sessionId, $channel, $message, $response, $state, source: 'clarification');
        }

        if ($intent === 'other'
            && $this->assistant->shouldEscalateMessage($message, $sessionId)
            && ! $this->containsBookingKeyword($message)
            && ! $this->containsEscalationPhrase($message)
            && $this->extractService($message, $hospital) === null
        ) {
            return $this->delegateToAssistant($sessionId, $channel, $message, $state);
        }

        if ($intent === 'pricing') {
            $response = preg_match('/\b(bei|ada)\b/iu', $message)
                ? sprintf(
                    'Kwa bei za sasa, wasiliana na %s kwa %s. Pia naweza kukusaidia kuweka miadi.',
                    $hospital->name,
                    $hospital->phone ?: 'namba ya mawasiliano ya hospitali',
                )
                : sprintf(
                    'For current service prices, please contact %s%s. I can also help you book an appointment.',
                    $hospital->name,
                    $hospital->phone ? ' at '.$hospital->phone : '',
                );

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                $response,
                $state,
            );
        }

        if ($intent === 'greeting') {
            $isSwahiliGreeting = preg_match(
                '/\b(habari|hujambo|jambo|mambo|niaje|sasa|shikamoo|marahaba|salama|poa)\b/iu',
                $message,
            ) === 1;
            $greeting = $isSwahiliGreeting
                ? null
                : $this->knowledgeBase->search($message);

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                $greeting ?? ($isSwahiliGreeting
                    ? sprintf(
                        'Habari! Mimi ni %s, msaidizi wa afya wa %s. Naweza kukusaidia na huduma, taarifa za hospitali, au kuweka miadi.',
                        $hospital->chatbotName(),
                        $hospital->name,
                    )
                    : sprintf(
                        "Hello! I'm %s, %s's healthcare assistant. I can help with services, hospital information, or booking an appointment.",
                        $hospital->chatbotName(),
                        $hospital->name,
                    )),
                $state,
                source: $greeting !== null ? 'knowledge_base' : 'hospital_greeting',
            );
        }

        if ($intent === 'thanks') {
            $response = preg_match('/\b(asante|shukran)\b/iu', $message)
                ? 'Karibu! Naweza kukusaidia na huduma au miadi.'
                : "You're welcome! I can help with hospital information or an appointment.";

            return $this->respond($sessionId, $channel, $message, $response, $state, source: 'acknowledgement');
        }

        if ($intent === 'services') {
            if (! $this->isBroadServicesOverview($message)
                && $this->extractService($message, $hospital) !== null
            ) {
                return $this->delegateToAssistant($sessionId, $channel, $message, $state);
            }

            $services = $hospital->services()->active()->orderBy('name')->pluck('name');
            $response = $services->isEmpty()
                ? sprintf('%s has no active services listed right now. Please contact the hospital for help.', $hospital->name)
                : sprintf('Available services at %s: %s.', $hospital->name, $services->implode(', '));

            return $this->respond($sessionId, $channel, $message, $response, $state, source: 'hospital_services');
        }

        if ($intent === 'doctors') {
            $doctors = $hospital->doctors()
                ->where('is_active', true)
                ->orderBy('name')
                ->get();
            $normalizedMessage = mb_strtolower($message);
            $specialtyTerms = match (true) {
                preg_match('/\b(dentist|dentists|dental)\b/iu', $normalizedMessage) === 1 => ['dent', 'oral'],
                preg_match('/\b(ob\/gyn|obstetrician|gynecologist|gynaecologist)\b/iu', $normalizedMessage) === 1 => ['obstetric', 'gynec', 'gynaec'],
                preg_match('/\b(radiologist|radiology)\b/iu', $normalizedMessage) === 1 => ['radiolog'],
                preg_match('/\b(physiotherapist|physiotherapy)\b/iu', $normalizedMessage) === 1 => ['physiotherap'],
                default => [],
            };
            if ($specialtyTerms !== []) {
                $doctors = $doctors->filter(
                    fn (User $doctor): bool => collect($specialtyTerms)
                        ->contains(fn (string $term): bool => str_contains(mb_strtolower((string) $doctor->specialization), $term)),
                );
            }

            $response = $doctors->isEmpty()
                ? sprintf(
                    'I could not find an active %s listed at %s. Please contact the hospital to confirm current clinic availability.',
                    $specialtyTerms !== [] ? 'doctor in that specialty' : 'doctor',
                    $hospital->name,
                )
                : sprintf(
                    'Doctors currently listed%s: %s. Tell me if you would like me to check appointment times.',
                    $specialtyTerms !== [] ? ' for this specialty' : ' at '.$hospital->name,
                    $doctors->map(fn (User $doctor): string => $doctor->name.(
                        filled($doctor->specialization) ? ' ('.$doctor->specialization.')' : ''
                    ))->implode(', '),
                );

            return $this->respond($sessionId, $channel, $message, $response, $state, source: 'hospital_doctors');
        }

        if (in_array($intent, ['hours', 'location', 'contact'], true)) {
            if ($intent === 'hours') {
                $businessHours = collect($hospital->business_hours ?? [])
                    ->map(function (mixed $hours, string $day): ?string {
                        if (! is_array($hours)) {
                            return null;
                        }

                        if ($hours['closed'] ?? false) {
                            return ucfirst($day).': closed';
                        }

                        if (! filled($hours['open'] ?? null) || ! filled($hours['close'] ?? null)) {
                            return null;
                        }

                        return ucfirst($day).': '.$hours['open'].'-'.$hours['close'];
                    })
                    ->filter()
                    ->implode('; ');
                $outpatientHours = $businessHours !== ''
                    ? $businessHours
                    : ($hospital->hours_outpatient ?: 'Please contact the hospital to confirm');

                $response = sprintf(
                    '%s outpatient hours: %s. Emergency hours: %s.',
                    $hospital->name,
                    $outpatientHours,
                    $hospital->hours_emergency ?: 'Please contact the hospital to confirm',
                );
            } elseif ($intent === 'location') {
                $response = $hospital->address
                    ? sprintf('%s is located at %s.', $hospital->name, $hospital->address)
                    : sprintf('Please contact %s for its current location.', $hospital->name);
            } else {
                $contacts = collect([
                    $hospital->phone ? 'Phone: '.$hospital->phone : null,
                    $hospital->email ? 'Email: '.$hospital->email : null,
                    $hospital->website ? 'Website: '.$hospital->website : null,
                ])->filter();
                $response = $contacts->isNotEmpty()
                    ? $contacts->implode('. ')
                    : sprintf('Please contact %s through its usual channels.', $hospital->name);
            }

            return $this->respond($sessionId, $channel, $message, $response, $state, source: 'hospital_information');
        }

        if ($intent === 'out_of_scope') {
            return $this->respond(
                $sessionId,
                $channel,
                $message,
                sprintf(
                    'I can help with %s services, appointments, and hospital information. What can I help you with?',
                    $hospital->name,
                ),
                $state,
            );
        }

        if ($intent === 'status') {
            $appointment = AppointmentRequest::query()
                ->where('session_id', $sessionId)
                ->latest('id')
                ->first();
            $response = $appointment
                ? sprintf(
                    'Your latest appointment request is %s. Payment status: %s.',
                    str_replace('_', ' ', (string) $appointment->status),
                    str_replace('_', ' ', (string) $appointment->payment_status),
                )
                : 'I could not find an appointment request in this chat yet. I can help you book one.';

            return $this->respond($sessionId, $channel, $message, $response, $state, source: 'appointment_status');
        }

        if ($intent === 'help') {
            return $this->respond(
                $sessionId,
                $channel,
                $message,
                sprintf(
                    'I can help with %s services, hospital information, and appointment bookings. To book, tell me the service you need.',
                    $hospital->name,
                ),
                $state,
            );
        }

        if (! $hospital->hasFeature('booking')
            && ($intent === 'book' || $state['state'] !== self::STATE_IDLE)
        ) {
            return $this->respond(
                $sessionId,
                $channel,
                $message,
                'Online appointment booking is not included in this hospital plan yet. Please contact the hospital directly for assistance.',
                $this->emptyState(),
                source: 'subscription_feature_unavailable',
            );
        }

        if ($state['state'] === self::STATE_CONFIRMING) {
            if ($intent === 'yes') {
                return $this->confirmBooking($sessionId, $channel, $message, $hospital, $state);
            }

            if ($intent === 'no') {
                $state = $this->emptyState();

                return $this->respond($sessionId, $channel, $message, 'No problem. Your booking was not submitted.', $state);
            }

            $state = $this->mergeFields($state, $message, $hospital);

            return $this->advanceState($sessionId, $channel, $message, $hospital, $state);
        }

        if ($state['state'] === self::STATE_IDLE
            && ! in_array($intent, ['book'], true)
            && $this->extractService($message, $hospital) === null
            && ! $this->containsBookingKeyword($message)
        ) {
            return $this->delegateToAssistant($sessionId, $channel, $message, $state);
        }

        $state['state'] = self::STATE_IDENTIFYING;
        if ($channel === 'whatsapp' && ! filled($state['patient_phone'])) {
            $state['patient_phone'] = substr($sessionId, strlen('whatsapp:'));
        }
        $state = $this->mergeFields($state, $message, $hospital);

        return $this->advanceState($sessionId, $channel, $message, $hospital, $state);
    }

    /**
     * @return array<string, mixed>
     */
    public function getState(string $channelId, string $channel = 'web'): array
    {
        $conversation = $this->latestConversation($channelId, $channel);
        if (! $conversation || ! is_array($conversation->chat_state)) {
            return $this->emptyState();
        }

        return $this->reconcilePaymentState(
            $this->validatedState($conversation->chat_state, $channelId, $channel),
        );
    }

    public function reset(string $channelId, string $channel = 'web'): void
    {
        $state = $this->emptyState();
        $this->persistState(
            $this->sessionId($channelId, $channel),
            $channel,
            '[booking reset]',
            '',
            $state,
        );
    }

    private function advanceState(
        string $sessionId,
        string $channel,
        string $message,
        Hospital $hospital,
        array $state,
    ): ChatResponse {
        $state['state'] = self::STATE_COLLECTING;

        if (! $state['service_id']) {
            $isContinueBookingRequest = preg_match('/\b(continue booking|resume booking)\b/u', $message) === 1;
            $hasAdditionalBookingContent = $this->hasAdditionalBookingContent($message);
            $servicePrompt = $this->containsBookingKeyword($message)
                && ! $this->isPlainBookingIntent($message)
                && ! $this->hasExplicitHumanPhrase($message)
                && ! $isContinueBookingRequest
                && $hasAdditionalBookingContent
                ? $this->serviceUnavailablePrompt($hospital)
                : $this->servicePrompt($hospital);

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                $servicePrompt,
                $state,
            );
        }

        $automaticallySelectedDoctor = null;

        if (! $state['doctor_id']) {
            $service = $this->serviceById((int) $state['service_id'], $hospital);
            $doctors = $this->eligibleDoctors($hospital, $service);
            $requiredSpecialty = $this->requiredSpecialty($service);
            $hasMatchingSpecialty = $requiredSpecialty !== null
                && $this->specialtyDoctors($hospital, $requiredSpecialty)->isNotEmpty();
            $mentionedDoctor = $this->extractDoctor($message, $hospital);

            if ($mentionedDoctor !== null
                && $requiredSpecialty !== null
                && $hasMatchingSpecialty
                && ! $doctors->contains('id', $mentionedDoctor->id)
            ) {
                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    sprintf(
                        '%s does not offer %s. Would you like %s instead?',
                        $mentionedDoctor->name,
                        $service->name,
                        $doctors->pluck('name')->implode(' or '),
                    ),
                    $state,
                );
            }

            if ($requiredSpecialty !== null && ! $hasMatchingSpecialty && $doctors->isNotEmpty()) {
                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    sprintf(
                        'No doctors with the %s specialty are currently available, but these doctors can help: %s. Which doctor would you prefer?',
                        $requiredSpecialty,
                        $doctors->pluck('name')->implode(', '),
                    ),
                    $state,
                );
            }

            $serviceTokens = $this->meaningfulTokens($service->name);
            $messageTokens = $this->meaningfulTokens($message);
            $serviceNameInMessage = collect($serviceTokens)
                ->intersect($messageTokens)
                ->isNotEmpty();

            if ($doctors->count() === 1 && $serviceNameInMessage) {
                $state['doctor_id'] = $doctors->first()->id;
                $automaticallySelectedDoctor = $doctors->first();
            } elseif ($doctors->isEmpty()) {
                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    'There are no available doctors for this service. Please contact the hospital for assistance.',
                    $state,
                );
            } else {
                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    'Which doctor would you prefer? Available doctors: '.$doctors->pluck('name')->implode(', ').'.',
                    $state,
                );
            }
        }

        $state['state'] = self::STATE_COLLECTING;

        if ($this->nextMissingField($state) === 'date') {
            $requestedDate = DateParser::parse($message);
            $previousState = $this->persistedState($sessionId, $channel);
            $dateWasRequested = $previousState['state'] === self::STATE_COLLECTING
                && $this->nextMissingField($previousState) === 'date';
            $dateError = match (true) {
                $requestedDate === null && $dateWasRequested => 'invalid',
                $requestedDate !== null && $requestedDate->lessThan(CarbonImmutable::today()) => 'past',
                $requestedDate !== null && $requestedDate->greaterThan(CarbonImmutable::today()->addYear()) => 'too_far',
                default => null,
            };

            if ($dateError !== null) {
                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    $this->datePrompt($message, $sessionId, $channel, $dateError),
                    $state,
                );
            }
        }

        if (filled($state['time'] ?? null) && $this->isTimeOutsideHospitalHours((string) $state['time'])) {
            $requestedTime = (string) $state['time'];
            $doctor = $hospital->users()->find((int) $state['doctor_id']);
            $doctorName = $doctor?->name ?? 'the doctor';
            $requestedDate = CarbonImmutable::parse((string) $state['date']);
            $availableTimes = collect($this->availability->getAvailableSlots(
                (int) $doctor->id,
                $requestedDate->toDateString(),
            ))
                ->filter(fn (bool $isAvailable): bool => $isAvailable)
                ->keys()
                ->take(5)
                ->all();

            $state['state'] = self::STATE_COLLECTING;
            $state['time'] = null;

            $response = $availableTimes !== []
                ? sprintf(
                    "%s doesn't have availability at %s.\nShe's available from 8:00 AM to 5:00 PM. The next available times with %s on %s are: %s. What time works?",
                    $doctorName,
                    $this->formatTimeForResponse($requestedTime),
                    $doctorName,
                    $requestedDate->format('F j'),
                    implode(', ', $availableTimes),
                )
                : sprintf(
                    "%s doesn't have availability at %s.\nShe's available from 8:00 AM to 5:00 PM. What time works?",
                    $doctorName,
                    $this->formatTimeForResponse($requestedTime),
                );

            return $this->respond($sessionId, $channel, $message, $response, $state);
        }

        foreach (self::FIELD_PROMPTS as $field => $prompt) {
            if ($field === 'doctor_id' && $state[$field]) {
                continue;
            }

            if (! filled($state[$field] ?? null)) {
                $promptMessage = $field === 'service_id'
                    ? $this->servicePrompt($hospital)
                    : $prompt;

                if ($field === 'date' && $automaticallySelectedDoctor !== null) {
                    $promptMessage = $this->datePrompt(
                        $message,
                        $sessionId,
                        $channel,
                        doctorName: $automaticallySelectedDoctor->name,
                    );
                } elseif ($field === 'date' && filled($state['doctor_id'] ?? null)) {
                    $selectedDoctor = $hospital->users()->find((int) $state['doctor_id']);
                    if ($selectedDoctor !== null) {
                        $promptMessage = $this->datePrompt(
                            $message,
                            $sessionId,
                            $channel,
                            doctorName: $selectedDoctor->name,
                        );
                    } else {
                        $promptMessage = $this->datePrompt($message, $sessionId, $channel);
                    }
                } elseif ($field === 'date') {
                    $promptMessage = $this->datePrompt($message, $sessionId, $channel);
                }

                if ($field === 'time' && $this->looksLikeTimeAttempt($message)) {
                    $promptMessage = "I couldn't understand the time you entered. Please use a format like:\n- 9am or 9:30am\n- 14:00 or 14:30\n- 2pm or 2:30pm\n\nWhat time works for you?";
                }

                return $this->respond($sessionId, $channel, $message, $promptMessage, $state);
            }
        }

        if (! $this->availability->isSlotAvailable(
            (int) $state['doctor_id'],
            (string) $state['date'],
            (string) $state['time'],
        )) {
            return $this->offerBookingAlternatives($sessionId, $channel, $message, $hospital, $state);
        }

        $state['state'] = self::STATE_CONFIRMING;
        $state['summary'] = $this->bookingSummary($state, $hospital);

        return $this->respond(
            $sessionId,
            $channel,
            $message,
            $state['summary'].' Reply YES to confirm or NO to cancel.',
            $state,
        );
    }

    private function confirmBooking(
        string $sessionId,
        string $channel,
        string $message,
        Hospital $hospital,
        array $state,
    ): ChatResponse {
        $service = $this->serviceById((int) $state['service_id'], $hospital);
        $appointment = $this->availability->bookAppointment(
            (string) $state['date'],
            (string) $state['time'],
            [
                'name' => (string) $state['patient_name'],
                'phone' => (string) $state['patient_phone'],
                'email' => $state['patient_email'],
                'reason' => $service->name,
                'raw_message' => $message,
                'session_id' => $sessionId,
            ],
            (int) $state['doctor_id'],
            $service,
        );

        if (! $appointment) {
            return $this->offerBookingAlternatives($sessionId, $channel, $message, $hospital, $state);
        }

        $amount = (float) ($appointment->payment_amount ?? $service->price);
        if (! $hospital->hasFeature('mpesa') || $amount <= 0) {
            $state['state'] = self::STATE_COMPLETED;
            $state['appointment_id'] = $appointment->id;

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                'Your appointment request has been submitted. The hospital will contact you to confirm the details.',
                $state,
                $appointment->id,
            );
        }

        $state['state'] = self::STATE_PAYMENT_PENDING;
        $state['appointment_id'] = $appointment->id;
        $this->persistState(
            $sessionId,
            $channel,
            $message,
            'Starting the M-Pesa payment request.',
            $state,
            $appointment->id,
        );

        try {
            $payment = $this->mpesa->initiateStkPush($appointment);
        } catch (Throwable $exception) {
            Log::error('Unable to start the booking chat payment request.', [
                'appointment_id' => $appointment->id,
                'channel' => $channel,
                'exception' => $exception,
            ]);
            $state['state'] = self::STATE_CANCELLED;

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                'Your appointment request was saved, but the M-Pesa prompt could not be sent. Please contact the hospital for help.',
                $state,
                $appointment->id,
            );
        }

        return $this->respond(
            $sessionId,
            $channel,
            $message,
            sprintf(
                'We sent an M-Pesa prompt to %s. Your appointment will be updated when payment is verified.',
                $this->mpesa->formatPhone((string) $state['patient_phone']),
            ),
            $state,
            $appointment->id,
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function mergeFields(array $state, string $message, Hospital $hospital): array
    {
        $state['service_id'] ??= $this->extractService($message, $hospital)?->id;
        $service = $state['service_id']
            ? $this->serviceById((int) $state['service_id'], $hospital)
            : null;
        $state['doctor_id'] ??= $this->extractDoctor($message, $hospital, $service)?->id;
        $state['date'] ??= $this->extractDate($message)?->toDateString();
        $state['time'] ??= $this->extractTime($message);
        $state['patient_phone'] ??= $this->extractPhone($message);
        $state['patient_email'] ??= $this->extractEmail($message);
        $state['patient_name'] ??= $this->extractName($message);

        if (! $state['patient_name'] && $this->nextMissingField($state) === 'patient_name') {
            $plainName = trim($message);
            if (preg_match('/^[\pL][\pL\' -]{1,79}$/u', $plainName)
                && ! preg_match('/\b(book|appointment|doctor|tomorrow|today|yes|no)\b/iu', $plainName)
            ) {
                $state['patient_name'] = $plainName;
            }
        }

        return $state;
    }

    private function containsBookingKeyword(string $message): bool
    {
        return preg_match(
            '/\b(book|booking|appointment|schedule|reserve|see\s+(?:a\s+)?doctor|i want to book|need\s+(?:an?\s+)?appointment|need\s+to\s+book)\b|'
                .'\b(?:nataka|naomba)\s+(?:kuweka|kupata|kuomba)\s+miadi\b|\bweka\s+miadi\b/iu',
            $message,
        ) === 1;
    }

    private function looksLikeSpecificServiceRequest(string $message): bool
    {
        return preg_match('/\b(dental|consultation|scan|xray|x-ray|physio|dialysis|renal|antenatal|prenatal|oncology|cancer|optical|eye|checkup|clinic|general)\b/iu', $message) === 1;
    }

    private function isPlainBookingIntent(string $message): bool
    {
        $trimmed = trim($message);

        return preg_match('/^(?:i\s+want\s+to\s+book\s+an\s+appointment|i\s+need\s+an\s+appointment|i\s+need\s+a\s+booking|book(?:ing)?(?:\s+an\s+appointment)?|appointment|schedule(?:\s+an\s+appointment)?|reserve(?:\s+an\s+appointment)?)$/iu', $trimmed) === 1;
    }

    private function hasExplicitHumanPhrase(string $message): bool
    {
        return preg_match('/\b(talk\s+to\s+a\s+human|speak\s+to\s+someone|i\s+need\s+a\s+human|i\s+need\s+someone|call\s+me|need\s+someone\s+to\s+call|real\s+person|health\s+worker|talk\s+to\s+someone|human\s+please|need\s+a\s+human|need\s+someone|speak\s+to\s+a\s+person)\b/iu', $message) === 1;
    }

    private function hasAdditionalBookingContent(string $message): bool
    {
        $message = preg_replace('/\b(book|booking|appointment|schedule|reserve|see\s+(?:a\s+)?doctor|i want to book|need\s+(?:an?\s+)?appointment|need\s+to\s+book|bookings?)\b/iu', ' ', $message);
        $message = preg_replace('/\b(talk\s+to\s+a\s+human|speak\s+to\s+someone|i\s+need\s+a\s+human|i\s+need\s+someone|call\s+me|need\s+someone\s+to\s+call|real\s+person|health\s+worker|talk\s+to\s+someone|human\s+please|need\s+a\s+human|need\s+someone|speak\s+to\s+a\s+person)\b/iu', ' ', $message);

        return trim((string) $message) !== '';
    }

    private function containsEscalationPhrase(string $message): bool
    {
        if ($this->containsBookingKeyword($message)) {
            return false;
        }

        return $this->hasExplicitHumanPhrase($message);
    }

    /**
     * @return array<string, string>
     */
    private function serviceAliases(): array
    {
        return [
            'ct' => 'CT Scan (Head/Chest/Abdomen)',
            'ct scan' => 'CT Scan (Head/Chest/Abdomen)',
            'cat scan' => 'CT Scan (Head/Chest/Abdomen)',
            'dental cleaning' => 'Dental Checkup & Cleaning',
            'dental checkup' => 'Dental Checkup & Cleaning',
            'dental' => 'Dental Checkup & Cleaning',
            'checkup' => 'Dental Checkup & Cleaning',
            'general consultation' => 'General Consultation',
            'general' => 'General Consultation',
            'consultation' => 'General Consultation',
            'scan' => 'Ultrasound Scan (Obstetric)',
            'ultrasound' => 'Ultrasound Scan (Obstetric)',
            'ultrasound scan' => 'Ultrasound Scan (Obstetric)',
            'xray' => 'Digital X-Ray',
            'x-ray' => 'Digital X-Ray',
            'radiograph' => 'Digital X-Ray',
            'physio' => 'Physiotherapy Session',
            'physiotherapy' => 'Physiotherapy Session',
            'dialysis' => 'Renal Dialysis Session',
            'renal' => 'Renal Dialysis Session',
            'antenatal' => 'Antenatal Clinic Visit',
            'prenatal' => 'Antenatal Clinic Visit',
            'oncology' => 'Oncology Consultation',
            'cancer' => 'Oncology Consultation',
            'optical' => 'Optical Eye Checkup',
            'eye checkup' => 'Optical Eye Checkup',
            'vision' => 'Optical Eye Checkup',
        ];
    }

    private function extractService(string $message, Hospital $hospital): ?Service
    {
        $normalizedMessage = $this->normalize($message);
        $services = $hospital->services()->active()->orderBy('id')->get();
        $exactMatches = $services->filter(function (Service $service) use ($normalizedMessage): bool {
            $normalizedName = $this->normalize($service->name);

            return $normalizedName !== ''
                && preg_match('/(?<![\pL\pN])'.preg_quote($normalizedName, '/').'(?![\pL\pN])/u', $normalizedMessage) === 1;
        });
        if ($exactMatches->count() === 1) {
            return $exactMatches->first();
        }
        if ($exactMatches->count() > 1) {
            $longestName = $exactMatches->sortByDesc(
                fn (Service $service): int => mb_strlen($this->normalize($service->name)),
            )->values();

            return mb_strlen($this->normalize($longestName[0]->name))
                > mb_strlen($this->normalize($longestName[1]->name))
                ? $longestName[0]
                : null;
        }

        $matchedAliases = [];
        foreach ($this->serviceAliases() as $alias => $serviceName) {
            $normalizedAlias = $this->normalize($alias);
            if ($normalizedAlias !== ''
                && preg_match('/(?<![\pL\pN])'.preg_quote($normalizedAlias, '/').'(?![\pL\pN])/u', $normalizedMessage)
            ) {
                $matchedAliases[$normalizedAlias] = $this->normalize($serviceName);
            }
        }

        foreach ($matchedAliases as $alias => $target) {
            foreach ($matchedAliases as $otherAlias => $unusedTarget) {
                if ($alias !== $otherAlias
                    && mb_strlen($otherAlias) > mb_strlen($alias)
                    && str_contains(' '.$otherAlias.' ', ' '.$alias.' ')
                ) {
                    unset($matchedAliases[$alias]);
                    break;
                }
            }
        }

        $aliasMatches = [];
        foreach ($matchedAliases as $alias => $target) {
            $exactTargetMatches = $services->filter(
                fn (Service $service): bool => $this->normalize($service->name) === $target,
            );
            if ($exactTargetMatches->isNotEmpty()) {
                foreach ($exactTargetMatches as $service) {
                    $aliasMatches[$service->id] = $service;
                }

                continue;
            }

            $aliasTokens = $this->meaningfulTokens($alias);
            $aliasTokenMatches = $services->filter(function (Service $service) use ($aliasTokens): bool {
                $serviceTokens = $this->meaningfulTokens($service->name);

                return $aliasTokens !== []
                    && count(array_intersect($aliasTokens, $serviceTokens)) === count($aliasTokens);
            });
            if (count($aliasTokens) === 1 && $aliasTokenMatches->count() > 1) {
                foreach ($aliasTokenMatches as $service) {
                    $aliasMatches[$service->id] = $service;
                }

                continue;
            }
            if ($aliasTokenMatches->isNotEmpty()) {
                foreach ($aliasTokenMatches as $service) {
                    $aliasMatches[$service->id] = $service;
                }

                continue;
            }

            $targetTokens = $this->meaningfulTokens($target);
            $rankedMatches = $services->map(fn (Service $service): array => [
                'service' => $service,
                'score' => count(array_intersect($targetTokens, $this->meaningfulTokens($service->name))),
            ]);
            $bestScore = (int) $rankedMatches->max('score');
            if ($bestScore > 0) {
                foreach ($rankedMatches->where('score', $bestScore)->pluck('service') as $service) {
                    $aliasMatches[$service->id] = $service;
                }
            }
        }

        if (count($aliasMatches) === 1) {
            return array_values($aliasMatches)[0];
        }

        if (count($aliasMatches) > 1) {
            return null;
        }

        $messageTokens = explode(' ', $normalizedMessage);
        $matches = [];
        $tokenFrequency = $services->flatMap(
            fn (Service $candidate): array => $this->meaningfulTokens($candidate->name),
        )->countBy();
        foreach ($services as $service) {
            $serviceTokens = $this->meaningfulTokens($service->name);
            if ($serviceTokens === []) {
                continue;
            }

            $matchedCount = 0;
            $matchedTokens = [];
            foreach ($serviceTokens as $serviceToken) {
                foreach ($messageTokens as $messageToken) {
                    if ($serviceToken === $messageToken
                        || (abs(mb_strlen($serviceToken) - mb_strlen($messageToken)) <= 1
                            && levenshtein($serviceToken, $messageToken) <= 1)
                    ) {
                        $matchedCount++;
                        $matchedTokens[] = $serviceToken;
                        break;
                    }
                }
            }

            $coverage = $matchedCount / count($serviceTokens);
            $isUniqueSingleTokenMatch = $matchedCount === 1
                && count($serviceTokens) > 1
                && ($tokenFrequency[$matchedTokens[0]] ?? 0) === 1;
            if ($coverage >= 0.6 || $isUniqueSingleTokenMatch) {
                $matches[] = ['service' => $service, 'coverage' => $coverage, 'matched' => $matchedCount];
            }
        }

        usort($matches, fn (array $left, array $right): int => [
            $right['coverage'],
            $right['matched'],
        ] <=> [
            $left['coverage'],
            $left['matched'],
        ]);

        if ($matches === []
            || (isset($matches[1])
                && $matches[0]['coverage'] === $matches[1]['coverage']
                && $matches[0]['matched'] === $matches[1]['matched'])
        ) {
            return null;
        }

        return $matches[0]['service'];
    }

    private function extractDoctor(string $message, Hospital $hospital, ?Service $service = null): ?User
    {
        if (preg_match('/\b(?:dr|doctor)\.?\s+([\pL][\pL\'-]*(?:\s+[\pL][\pL\'-]*)?)/iu', $message, $matches)) {
            $searchText = $matches[1];
        } else {
            foreach ([
                $this->extractName($message),
                $this->extractPhone($message),
                $this->extractEmail($message),
            ] as $personalField) {
                if ($personalField !== null) {
                    $message = preg_replace('/'.preg_quote($personalField, '/').'/i', ' ', $message) ?? $message;
                }
            }

            $searchText = $message;
        }

        $doctors = $this->eligibleDoctors($hospital, $service);
        $tokens = collect($this->meaningfulTokens($searchText));
        $matches = $doctors->filter(function (User $doctor) use ($tokens): bool {
            $nameTokens = collect(explode(' ', $this->normalize(preg_replace('/^(?:dr|doctor)\s+/i', '', $doctor->name) ?? $doctor->name)));
            $distinctMatches = $nameTokens->intersect($tokens)->unique();

            return $distinctMatches->isNotEmpty()
                && $nameTokens->contains(fn (string $token): bool => mb_strlen($token) >= 3 && $distinctMatches->contains($token));
        });

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function extractDate(string $message): ?CarbonImmutable
    {
        $date = DateParser::parse($message);

        if ($date === null
            || $date->lessThan(CarbonImmutable::today())
            || $date->greaterThan(CarbonImmutable::today()->addYear())
        ) {
            return null;
        }

        return $date;
    }

    private function datePrompt(
        string $message,
        string $sessionId,
        string $channel,
        ?string $error = null,
        ?string $doctorName = null,
    ): string {
        $isSwahili = $this->isSwahiliDateMessage($message);
        if (! $isSwahili) {
            $previousMessage = $this->latestConversation($sessionId, $channel)?->user_message;
            $isSwahili = is_string($previousMessage) && $this->isSwahiliDateMessage($previousMessage);
        }

        if ($isSwahili) {
            $prompt = match ($error) {
                'past' => 'Tarehe hiyo imepita. Tafadhali chagua tarehe ijayo kwa muundo wa DD/MM/YYYY (mfano 15/10/2026).',
                'too_far' => 'Tarehe hiyo iko mbali sana. Tafadhali chagua tarehe ndani ya miezi 12 ijayo kwa muundo wa DD/MM/YYYY.',
                'invalid' => "Samahani, sikuweza kuelewa tarehe. Tafadhali ingiza tarehe kama DD/MM/YYYY (mfano 15/10/2026), YYYY-MM-DD, au sema 'kesho'.",
                default => "Ungependa miadi tarehe gani? Tafadhali ingiza DD/MM/YYYY (mfano 15/10/2026), YYYY-MM-DD, au sema 'kesho'.",
            };

            return $doctorName !== null && $error === null
                ? $doctorName.' ana huduma kwa miadi hiyo. '.$prompt
                : $prompt;
        }

        $prompt = match ($error) {
            'past' => 'That date is in the past. Please choose a future date in DD/MM/YYYY format (e.g. 15/10/2026).',
            'too_far' => 'That date is too far ahead. Please choose a date within the next 12 months in DD/MM/YYYY format (e.g. 15/10/2026).',
            'invalid' => "Sorry, I didn't catch that. What date would you prefer? Please enter it as DD/MM/YYYY (e.g. 15/10/2026), YYYY-MM-DD, or say 'tomorrow'.",
            default => 'What date would you prefer? Use DD/MM/YYYY (e.g. 15/10/2026), YYYY-MM-DD, or say "tomorrow".',
        };

        return $doctorName !== null && $error === null
            ? $doctorName.' is available for this service. '.$prompt
            : $prompt;
    }

    private function isSwahiliDateMessage(string $message): bool
    {
        return preg_match(
            '/\b(habari|hujambo|sijambo|jambo|mambo|vipi|niaje|sasa|shikamoo|marahaba|salama|poa|freshi|mzuri|nzuri|miadi|daktari|tafadhali|samahani|ndiyo|hapana|kesho|leo|huduma|namba|simu|jina|naomba|nataka|tarehe|siku|jumatatu|jumanne|jumatano|alhamisi|ijumaa|jumamosi|jumapili)\b/iu',
            $message,
        ) === 1;
    }

    private function extractTime(string $message): ?string
    {
        $normalized = trim($message);

        if (preg_match('/\bnoon\b/i', $normalized)) {
            return '12:00';
        }

        if (preg_match('/\bmidnight\b/i', $normalized)) {
            return '00:00';
        }

        if (preg_match('/\b(?:quarter|a quarter)\s+(?:past|after)\s+([1-9]|1[0-2])\b/i', $normalized, $matches)) {
            return sprintf('%02d:15', (int) $matches[1]);
        }

        if (preg_match('/\bhalf\s+(?:past|after)\s+([1-9]|1[0-2])\b/i', $normalized, $matches)) {
            return sprintf('%02d:30', (int) $matches[1]);
        }

        if (preg_match('/\bquarter\s+to\s+([1-9]|1[0-2])\b/i', $normalized, $matches)) {
            $hour = (int) $matches[1];
            $minute = 45;

            return sprintf('%02d:%02d', $hour === 12 ? 11 : ($hour % 12), $minute);
        }

        if (preg_match('/\b([1-9]|1[0-2])\s*o\s*[\'’]?clock\b/i', $normalized, $matches)) {
            return sprintf('%02d:00', (int) $matches[1]);
        }

        if (preg_match('/\b([1-9]|1[0-2])(?::([0-5]\d))?\s*(a\.?m\.?|p\.?m\.?)\b/iu', $normalized, $matches)) {
            $hour = (int) $matches[1] % 12;
            if (str_starts_with(mb_strtolower($matches[3]), 'p')) {
                $hour += 12;
            }

            return sprintf('%02d:%02d', $hour, (int) ($matches[2] ?? 0));
        }

        if (preg_match('/\b([01]\d|2[0-3])\s*(?:[:.]\s*([0-5]\d))\b/', $normalized, $matches)) {
            return sprintf('%02d:%02d', (int) $matches[1], (int) ($matches[2] ?? 0));
        }

        if (preg_match('/\b([01]\d|2[0-3])\b/', $normalized, $matches)) {
            return sprintf('%02d:00', (int) $matches[1]);
        }

        return null;
    }

    private function looksLikeTimeAttempt(string $message): bool
    {
        return preg_match('/\b(?:noon|midnight|quarter\s+(?:past|to)|half\s+past|[0-9]{1,2}\s*(?:[.:][0-9]{2})?\s*(?:am|pm)|[0-9]{1,2}\s*o\s*[\'’]?clock)\b/i', $message) === 1;
    }

    private function isTimeOutsideHospitalHours(string $time): bool
    {
        try {
            $hour = CarbonImmutable::parse($time)->hour;

            return $hour < 8 || $hour >= 17;
        } catch (Throwable) {
            return false;
        }
    }

    private function formatTimeForResponse(string $time): string
    {
        try {
            $parsedTime = CarbonImmutable::parse($time);
            $format = $parsedTime->format('g:i A');

            return $parsedTime->hour === 0 ? '12:00 AM' : $format;
        } catch (Throwable) {
            return 'that time';
        }
    }

    private function extractPhone(string $message): ?string
    {
        if (preg_match('/(?<!\d)(2547\d{8}|07\d{8})(?!\d)/', $message, $matches)) {
            return str_starts_with($matches[1], '07')
                ? '254'.substr($matches[1], 1)
                : $matches[1];
        }

        return null;
    }

    private function extractEmail(string $message): ?string
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $message, $matches)
            && filter_var($matches[0], FILTER_VALIDATE_EMAIL)
        ) {
            return mb_strtolower($matches[0]);
        }

        return null;
    }

    private function extractName(string $message): ?string
    {
        if (preg_match(
            '/\b(?:my name is|i am|i\'m|this is)\s+([\pL][\pL\'-]*(?:\s+[\pL][\pL\'-]*){0,3}?)(?=\s+(?:and|with|phone|email|tomorrow|today|next)\b|[,.;]|$)/iu',
            $message,
            $matches,
        )) {
            return trim($matches[1]);
        }

        return null;
    }

    private function extractIntent(string $message, string $state): string
    {
        $normalized = $this->normalizeIntentKeywords($message);

        if (preg_match('/\b(cancel|cancell?ation|futa|ghairi)\b.{0,30}\b(my\s+)?(appointment|booking|miadi)\b|\b(futa|ghairi)\s+(miadi|appointment)\b/u', $normalized) === 1) {
            return 'cancel_appointment';
        }
        if (preg_match('/\b(reschedule|rebook|change)\b.{0,30}\b(my\s+)?(appointment|booking|miadi)\b|\b(badilisha|hamisha)\s+(miadi|appointment)\b/u', $normalized) === 1) {
            return 'reschedule_appointment';
        }
        if ($state !== self::STATE_IDLE
            && preg_match('/\b(start over|start again|restart|reset|begin again|anza upya|tuanzie upya)\b/u', $normalized)
        ) {
            return 'reset';
        }
        if ($state !== self::STATE_IDLE
            && preg_match('/\b(cancel|never mind|nevermind|hapana|stop booking|forget it|don\'t|stop)\b/u', $normalized)
        ) {
            return 'cancel';
        }
        // Extract yes/no in all states, but handle specially based on state
        if (preg_match('/^\s*(?:yes|y|yeah|yep|ok|confirm|book it|proceed|ndiyo|ndio|sawa)\b/u', $normalized)) {
            return 'yes';
        }
        if (preg_match('/^\s*(?:no|n|nope)\b/u', $normalized)) {
            return 'no';
        }
        if (preg_match('/\b(status|payment status|booking status)\b/u', $normalized)) {
            return 'status';
        }
        if (preg_match('/\b(continue booking|resume booking)\b/u', $normalized)) {
            return 'continue_booking';
        }
        if ($this->containsBookingKeyword($normalized)) {
            return 'book';
        }
        if ($this->containsEscalationPhrase($message)) {
            return 'escalation';
        }
        if (preg_match('/\b(price|prices|fee|fees|cost|costs|how much|charge|charges|bei|ada)\b/u', $normalized)) {
            return 'pricing';
        }
        if (preg_match('/\b(what services|which services|services do you offer|services are available|hospital services|huduma zenu|huduma gani)\b/iu', $normalized)) {
            return 'services';
        }
        if (preg_match('/\b(dentist|dentists|doctor|doctors|doc|daktari|madaktari|specialist|specialists|radiologist|physiotherapist|obstetrician|gynecologist|gynaecologist)\b/iu', $normalized)
            && (
                preg_match('/\b(do you have|is there|are there|available|availability|which|who|can i see|mna|yupo|wapo|yupi|yuko|gimme|give me)\b/iu', $normalized)
                || preg_match('/^\s*(?:a\s+)?(?:dentists?|doctors?|docs?|daktari|madaktari)\s*[?!.]*\s*$/iu', $normalized)
            )
        ) {
            return 'doctors';
        }
        if (preg_match('/\b(opening hours|working hours|outpatient hours|emergency hours|what time|when do you open|when are you open|hours|saa ngapi|mnafunga)\b/iu', $normalized)) {
            return 'hours';
        }
        if (preg_match('/\b(where are you|where is|location|address|directions|mko wapi|iko wapi|mnapatikana wapi)\b/iu', $normalized)) {
            return 'location';
        }
        if (preg_match('/\b(contact(?: the hospital)?|phone number|hospital phone|telephone number|email address|hospital email|website|whatsapp number|call the hospital|what is your phone number)\b/iu', $normalized)) {
            return 'contact';
        }
        if ($state === self::STATE_IDLE
            && preg_match('/^(?:hi|hello|hey|habari|hujambo|jambo|mambo(?:\s+vipi)?|vipi|niaje|sasa|shikamoo|marahaba|salama|poa|freshi|mzuri|nzuri|yo|sup|what\s+s\s+up|whats\s+up|good morning|good afternoon|good evening)[.!?,\s]*$/u', $normalized)
        ) {
            return 'greeting';
        }
        if (preg_match('/\b(help|support|what can you do|how does this work)\b/u', $normalized)) {
            return 'help';
        }
        if (preg_match('/\b(thank you|thanks|thank you very much|asante|shukran|appreciate it)\b/iu', $normalized)) {
            return 'thanks';
        }
        if (preg_match('/\b(capital of|president of|who is (?:the )?president|population of|weather|stock price|who invented|who won|tell me (?:a )?joke|define|meaning of|translate)\b/u', $normalized)) {
            return 'out_of_scope';
        }

        return 'other';
    }

    private function normalizeIntentKeywords(string $message): string
    {
        $normalized = $this->normalize($message);
        $normalized = preg_replace('/\bu\b/u', 'you', $normalized) ?? $normalized;
        $normalized = preg_replace('/\bwanna\b/u', 'want to', $normalized) ?? $normalized;
        $normalized = preg_replace('/\bgimme\b/u', 'give me', $normalized) ?? $normalized;
        $keywords = [
            'appointment',
            'booking',
            'book',
            'cancel',
            'cancer',
            'dentist',
            'doctor',
            'hello',
            'location',
            'located',
            'look',
            'pricing',
            'price',
            'prize',
            'schedule',
            'support',
            'took',
            'what',
            'whats',
            'where',
        ];
        $tokens = explode(' ', $normalized);

        foreach ($tokens as &$token) {
            $closestMatches = [];
            $closestDistance = PHP_INT_MAX;

            foreach ($keywords as $keyword) {
                $distance = levenshtein($token, $keyword);
                $maximumDistance = 1;

                if ($distance > $maximumDistance) {
                    continue;
                }

                if ($distance < $closestDistance) {
                    $closestMatches = [$keyword];
                    $closestDistance = $distance;
                } elseif ($distance === $closestDistance) {
                    $closestMatches[] = $keyword;
                }
            }

            if (count($closestMatches) === 1) {
                $token = $closestMatches[0];
            }
        }
        unset($token);

        return implode(' ', $tokens);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function beginAppointmentLifecycle(
        string $sessionId,
        string $channel,
        string $message,
        Hospital $hospital,
        array $state,
        string $action,
    ): ChatResponse {
        $user = auth()->user();
        $appointments = AppointmentRequest::query()
            ->where('hospital_id', $hospital->id)
            ->whereNotNull('slot_start_time')
            ->whereIn('status', [
                AppointmentRequest::STATUS_PENDING,
                AppointmentRequest::STATUS_CONFIRMED,
            ])
            ->whereDate('preferred_date', '>=', now()->toDateString())
            ->where(function ($query) use ($sessionId, $user): void {
                $query->where('session_id', $sessionId);
                if ($user instanceof User && (int) $user->hospital_id === (int) hospital()->id) {
                    $query->orWhere('patient_id', $user->id);
                }
            })
            ->orderByDesc('preferred_date')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $eligible = $appointments->filter(function (AppointmentRequest $appointment) use ($action): bool {
            $decision = $this->appointmentPolicy->evaluate($appointment);

            return $action === 'cancel' ? $decision->canCancel : $decision->canReschedule;
        })->values();

        if ($eligible->isEmpty()) {
            if (in_array($state['state'], [
                self::STATE_IDENTIFYING,
                self::STATE_COLLECTING,
                self::STATE_CONFIRMING,
            ], true)) {
                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    'I could not find an upcoming appointment to change. Your in-progress booking details are unchanged.',
                    $state,
                );
            }

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                'I could not find an upcoming appointment linked to this chat that can be changed. Please sign in to manage appointments in your patient dashboard.',
                $this->emptyState(),
            );
        }

        $state = $this->emptyState(self::STATE_LIFECYCLE_SELECT);
        $state['lifecycle_action'] = $action;
        $state['appointment_candidates'] = $eligible->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if ($eligible->count() === 1) {
            return $this->selectLifecycleAppointment(
                $sessionId,
                $channel,
                $message,
                $hospital,
                $state,
                (int) $eligible->first()->id,
            );
        }

        $isSwahili = $this->isSwahiliDateMessage($message);
        $items = $eligible->values()->map(fn (AppointmentRequest $appointment, int $index): string => sprintf(
            '%d. #%d — %s %s',
            $index + 1,
            $appointment->id,
            $appointment->preferred_date?->format('d/m/Y'),
            $appointment->slot_start_time ? substr((string) $appointment->slot_start_time, 0, 5) : '',
        ))->implode("\n");
        $prompt = $isSwahili
            ? "Umepangiwa miadi zaidi ya mmoja. Jibu kwa nambari ya miadi unayotaka kubadilisha:\n".$items
            : "You have more than one eligible appointment. Reply with the number of the appointment to change:\n".$items;

        return $this->respond($sessionId, $channel, $message, $prompt, $state);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function handleAppointmentLifecycleState(
        string $sessionId,
        string $channel,
        string $message,
        Hospital $hospital,
        array $state,
    ): ChatResponse {
        if ($state['state'] === self::STATE_LIFECYCLE_SELECT) {
            if (preg_match('/^\s*([1-9]\d?)\s*$/', $message, $matches) !== 1) {
                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    'Reply with the number beside the appointment you want to change.',
                    $state,
                );
            }

            $index = (int) $matches[1] - 1;
            $appointmentId = $state['appointment_candidates'][$index] ?? null;
            if (! is_int($appointmentId)) {
                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    'That selection is not valid. Reply with one of the appointment numbers shown.',
                    $state,
                );
            }

            return $this->selectLifecycleAppointment(
                $sessionId,
                $channel,
                $message,
                $hospital,
                $state,
                $appointmentId,
            );
        }

        if (preg_match('/^\s*(cancel|reschedule|futa|badilisha)\s*$/iu', $message) === 1) {
            $state['lifecycle_action'] = in_array(mb_strtolower(trim($message)), ['cancel', 'futa'], true)
                ? 'cancel'
                : 'reschedule';
            $state['state'] = self::STATE_LIFECYCLE_SELECT;
            $appointments = AppointmentRequest::query()
                ->where('hospital_id', $hospital->id)
                ->whereIn('id', $state['appointment_candidates'] ?? [])
                ->get();
            $eligible = $appointments->filter(function (AppointmentRequest $candidate) use ($state): bool {
                $decision = $this->appointmentPolicy->evaluate($candidate);

                return $state['lifecycle_action'] === 'cancel'
                    ? $decision->canCancel
                    : $decision->canReschedule;
            })->values();
            $state['appointment_candidates'] = $eligible->pluck('id')->map(fn ($candidateId): int => (int) $candidateId)->all();

            if ($eligible->count() === 1) {
                return $this->selectLifecycleAppointment(
                    $sessionId,
                    $channel,
                    $message,
                    $hospital,
                    $state,
                    (int) $eligible->first()->id,
                );
            }

            if ($eligible->isEmpty()) {
                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    'That appointment cannot be changed under the current hospital policy.',
                    $this->emptyState(),
                );
            }

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                'Reply with the number of the appointment you want to change.',
                $state,
            );
        }

        $appointmentId = $state['lifecycle_appointment_id'] ?? null;
        if (! is_int($appointmentId)
            || ! $this->chatAppointmentIsOwned($appointmentId, $sessionId, $hospital->id)
        ) {
            return $this->respond(
                $sessionId,
                $channel,
                $message,
                'I could not verify that appointment for this chat. Please sign in to manage it in your patient dashboard.',
                $this->emptyState(),
            );
        }

        $appointment = AppointmentRequest::query()
            ->where('hospital_id', $hospital->id)
            ->findOrFail($appointmentId);
        $intent = $this->extractIntent($message, $state['state']);
        $isSwahili = $this->isSwahiliDateMessage($message);

        if ($state['state'] === self::STATE_LIFECYCLE_CANCEL_CONFIRM) {
            if (preg_match('/^\s*(?:no|n|nope|hapana)\b/iu', $message) === 1) {
                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    $isSwahili ? 'Sawa, miadi yako haijaghairiwa.' : 'Okay, your appointment has not been cancelled.',
                    $this->emptyState(),
                );
            }
            if (preg_match('/^\s*(?:yes|y|yeah|yep|ok|confirm|book it|proceed|ndiyo|ndio|sawa)\b/iu', $message) !== 1) {
                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    $isSwahili ? 'Tafadhali jibu ndiyo au hapana.' : 'Please reply yes or no to confirm cancellation.',
                    $state,
                );
            }

            try {
                $result = $this->appointmentLifecycle->cancel(
                    $appointmentId,
                    (int) $hospital->id,
                    auth()->user() instanceof User ? auth()->user() : null,
                    reason: 'Patient requested cancellation in chat',
                );
            } catch (AppointmentLifecycleException $exception) {
                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    $exception->getMessage(),
                    $this->emptyState(),
                );
            }

            $response = $isSwahili
                ? 'Miadi yako imeghairiwa.'
                : 'Your appointment has been cancelled.';
            if ($result->forfeitedAmount > 0) {
                $response .= ' KSh '.number_format($result->forfeitedAmount, 2).' was retained under the hospital cancellation policy.';
            } elseif ($result->paymentOutcome === 'refund_pending') {
                $response .= ' Any refundable balance is pending manual payment review.';
            }

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                $response,
                $this->emptyState(),
                $appointmentId,
            );
        }

        if ($state['state'] === self::STATE_LIFECYCLE_RESCHEDULE_DATE) {
            $date = DateParser::parse($message);
            if ($date === null
                || $date->toDateString() < CarbonImmutable::today()->toDateString()
                || $date->greaterThan(CarbonImmutable::today()->addYear())
            ) {
                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    'Please choose a valid future date within the next 12 months, such as 15/10/2026.',
                    $state,
                );
            }

            $state['lifecycle_date'] = $date->toDateString();
            $state['state'] = self::STATE_LIFECYCLE_RESCHEDULE_TIME;

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                $isSwahili ? 'Ungependa miadi iwe saa ngapi?' : 'What time would you prefer? For example, 10:30 AM.',
                $state,
            );
        }

        if ($state['state'] === self::STATE_LIFECYCLE_RESCHEDULE_TIME) {
            $time = $this->extractTime($message);
            if ($time === null || $appointment->doctor_id === null) {
                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    'Please enter a valid time, such as 10:30 AM.',
                    $state,
                );
            }

            $availableSlots = $this->availability->getAvailableSlots(
                (int) $appointment->doctor_id,
                (string) $state['lifecycle_date'],
            );
            if (($availableSlots[$time] ?? false) !== true) {
                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    'That time is not available with your current doctor. Please choose another time or start again with a different date.',
                    $state,
                );
            }

            $state['lifecycle_time'] = $time;
            $state['state'] = self::STATE_LIFECYCLE_RESCHEDULE_CONFIRM;

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                sprintf(
                    'Please confirm moving appointment #%d to %s at %s. Reply yes or no.',
                    $appointment->id,
                    CarbonImmutable::parse((string) $state['lifecycle_date'])->format('d/m/Y'),
                    CarbonImmutable::parse($time)->format('g:i A'),
                ),
                $state,
                $appointmentId,
            );
        }

        if ($state['state'] === self::STATE_LIFECYCLE_RESCHEDULE_CONFIRM) {
            if (preg_match('/^\s*(?:no|n|nope|hapana)\b/iu', $message) === 1) {
                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    'Okay, your appointment has not been changed.',
                    $this->emptyState(),
                );
            }
            if (preg_match('/^\s*(?:yes|y|yeah|yep|ok|confirm|book it|proceed|ndiyo|ndio|sawa)\b/iu', $message) !== 1) {
                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    'Please reply yes or no to confirm the new appointment time.',
                    $state,
                );
            }

            try {
                $this->appointmentLifecycle->reschedule(
                    $appointmentId,
                    (int) $hospital->id,
                    (int) $appointment->doctor_id,
                    (string) $state['lifecycle_date'],
                    (string) $state['lifecycle_time'],
                    auth()->user() instanceof User ? auth()->user() : null,
                    reason: 'Patient requested rescheduling in chat',
                );
            } catch (AppointmentLifecycleException $exception) {
                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    $exception->getMessage(),
                    $this->emptyState(),
                );
            }

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                'Your appointment has been rescheduled.',
                $this->emptyState(),
                $appointmentId,
            );
        }

        return $this->respond(
            $sessionId,
            $channel,
            $message,
            'I could not continue that appointment request. Please start again.',
            $this->emptyState(),
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function selectLifecycleAppointment(
        string $sessionId,
        string $channel,
        string $message,
        Hospital $hospital,
        array $state,
        int $appointmentId,
    ): ChatResponse {
        $appointment = AppointmentRequest::query()
            ->where('hospital_id', $hospital->id)
            ->find($appointmentId);
        if (! $appointment || ! $this->chatAppointmentIsOwned($appointmentId, $sessionId, $hospital->id)) {
            return $this->respond(
                $sessionId,
                $channel,
                $message,
                'I could not verify that appointment for this chat.',
                $this->emptyState(),
            );
        }

        $state['lifecycle_appointment_id'] = $appointment->id;
        $state['appointment_candidates'] = [];
        if ($state['lifecycle_action'] === 'cancel') {
            $state['state'] = self::STATE_LIFECYCLE_CANCEL_CONFIRM;
            $response = sprintf(
                'Cancel appointment #%d on %s at %s? Reply yes or no.',
                $appointment->id,
                $appointment->preferred_date?->format('d/m/Y'),
                $appointment->slot_start_time ? CarbonImmutable::parse($appointment->slot_start_time)->format('g:i A') : 'the scheduled time',
            );
        } else {
            $state['state'] = self::STATE_LIFECYCLE_RESCHEDULE_DATE;
            $response = $this->isSwahiliDateMessage($message)
                ? 'Ni tarehe gani mpya ungependa? Tumia muundo wa DD/MM/YYYY.'
                : 'What new date would you prefer? Use DD/MM/YYYY (e.g. 15/10/2026), YYYY-MM-DD, or say "tomorrow".';
        }

        return $this->respond(
            $sessionId,
            $channel,
            $message,
            $response,
            $state,
            $appointment->id,
        );
    }

    private function chatAppointmentIsOwned(int $appointmentId, string $sessionId, int $hospitalId): bool
    {
        $user = auth()->user();

        return AppointmentRequest::query()
            ->where('hospital_id', $hospitalId)
            ->whereKey($appointmentId)
            ->where(function ($query) use ($sessionId, $user): void {
                $query->where('session_id', $sessionId);
                if ($user instanceof User && (int) $user->hospital_id === (int) hospital()->id) {
                    $query->orWhere('patient_id', $user->id);
                }
            })
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyState(string $status = self::STATE_IDLE): array
    {
        return [
            'state' => $status,
            'service_id' => null,
            'doctor_id' => null,
            'date' => null,
            'time' => null,
            'patient_name' => null,
            'patient_phone' => null,
            'patient_email' => null,
            'appointment_id' => null,
            'lifecycle_action' => null,
            'lifecycle_appointment_id' => null,
            'appointment_candidates' => [],
            'lifecycle_date' => null,
            'lifecycle_time' => null,
            'escalation_pending' => false,
            'pending_escalation_message' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function respond(
        string $sessionId,
        string $channel,
        string $userMessage,
        string $response,
        array $state,
        ?int $appointmentId = null,
        bool $paymentRequired = false,
        ?string $source = null,
        bool $escalated = false,
    ): ChatResponse {
        $this->persistState($sessionId, $channel, $userMessage, $response, $state, $appointmentId, $escalated);

        return new ChatResponse(
            $response,
            $state['state'],
            $appointmentId,
            $paymentRequired,
            $state,
            $source,
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function persistState(
        string $sessionId,
        string $channel,
        string $userMessage,
        string $response,
        array $state,
        ?int $appointmentId = null,
        bool $escalated = false,
    ): void {
        $state = $this->validatedState($state, $sessionId, $channel);

        Conversation::query()->create([
            'session_id' => $sessionId,
            'user_message' => $userMessage,
            'ai_response' => $response,
            'confidence_score' => 1.0,
            'channel' => $channel,
            'escalated' => $escalated,
            'chat_state' => $state,
            'chat_state_updated_at' => now(),
        ]);
    }

    private function delegateToAssistant(
        string $sessionId,
        string $channel,
        string $message,
        array $state,
    ): ChatResponse {
        $previousConversationId = Conversation::query()
            ->where('session_id', $sessionId)
            ->where('channel', $channel)
            ->latest('id')
            ->value('id') ?? 0;
        $patientPhone = filled($state['patient_phone'] ?? null)
            ? (string) $state['patient_phone']
            : null;
        $result = $this->assistant->processMessageWithPatientPhone($message, $sessionId, $channel, $patientPhone);
        $response = (string) ($result['response'] ?? 'I could not process that message. Please try again.');
        $conversation = Conversation::query()
            ->where('session_id', $sessionId)
            ->where('channel', $channel)
            ->where('user_message', $message)
            ->where('id', '>', $previousConversationId)
            ->latest('id')
            ->first();

        if ($conversation) {
            $conversation->forceFill([
                'chat_state' => $state,
                'chat_state_updated_at' => now(),
                'escalated' => (bool) ($result['escalated'] ?? false),
            ])->save();
        } else {
            $this->persistState(
                $sessionId,
                $channel,
                $message,
                $response,
                $state,
                escalated: (bool) ($result['escalated'] ?? false),
            );
        }

        return new ChatResponse(
            $response,
            $state['state'],
            $result['appointment_id'] ?? null,
            collectedFields: $state,
            source: isset($result['source']) ? (string) $result['source'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function promptForNextField(
        array $state,
        Hospital $hospital,
        string $message,
        string $sessionId,
        string $channel,
    ): string {
        $field = $this->nextMissingField($state);
        if ($field === 'service_id') {
            return $this->servicePrompt($hospital);
        }

        if ($field === 'date') {
            return $this->datePrompt($message, $sessionId, $channel);
        }

        return $field !== null
            ? self::FIELD_PROMPTS[$field]
            : 'Please provide the missing booking detail so I can continue.';
    }

    private function handleExplicitHumanRequest(
        string $sessionId,
        string $channel,
        string $message,
        array $state,
        Hospital $hospital,
    ): ChatResponse {
        $phone = $this->extractPhone($message) ?? ($state['patient_phone'] ?? null);
        $state['state'] ??= self::STATE_IDLE;

        if ($state['escalation_pending'] ?? false) {
            if ($phone !== null) {
                $pendingMessage = (string) ($state['pending_escalation_message'] ?? $message);
                app(EscalationService::class)->createEscalation(
                    $sessionId,
                    $pendingMessage,
                    "I've passed your request to the {$hospital->name} team. Someone will reach out shortly.",
                    $phone,
                );
                $state['escalation_pending'] = false;
                $state['pending_escalation_message'] = null;

                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    sprintf(
                        "I've passed your request to the %s team. Someone will reach out shortly.\n\nYour booking details are saved — say \"continue booking\" whenever you're ready to finish.",
                        $hospital->name,
                    ),
                    $state,
                    source: 'human_escalation',
                    escalated: true,
                );
            }

            $state['state'] = self::STATE_COLLECTING;

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                sprintf(
                    "I've noted your request. Please share your phone number — what's the best phone number for a member of the %s team to reach you on?",
                    $hospital->name,
                ),
                $state,
                source: 'human_escalation_contact_details',
            );
        }

        if ($phone !== null) {
            app(EscalationService::class)->createEscalation(
                $sessionId,
                $message,
                sprintf("I've noted your request. Please share your phone number — what's the best phone number for a member of the %s team to reach you on?", $hospital->name),
                $phone,
            );

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                sprintf(
                    "Thank you. Your request has been passed to the %s team. I'm connecting you to a health worker now; someone will reach out shortly.",
                    $hospital->name,
                ),
                $state,
                source: 'human_escalation',
                escalated: true,
            );
        }

        $state['state'] = self::STATE_COLLECTING;
        $state['escalation_pending'] = true;
        $state['pending_escalation_message'] = $message;

        return $this->respond(
            $sessionId,
            $channel,
            $message,
            sprintf(
                "I've noted your request. Please share your phone number — what's the best phone number for a member of the %s team to reach you on?",
                $hospital->name,
            ),
            $state,
            source: 'human_escalation_contact_details',
        );
    }

    private function servicePrompt(Hospital $hospital): string
    {
        $names = $hospital->services()->active()->orderBy('name')->pluck('name');
        if ($names->isEmpty()) {
            return 'There are no services available to book right now. Please contact the hospital.';
        }

        return sprintf(
            "Sure, I can help you book an appointment at %s.\n\nWhat service do you need?",
            $hospital->name,
        );
    }

    private function serviceUnavailablePrompt(Hospital $hospital): string
    {
        $names = $hospital->services()->active()->orderBy('name')->pluck('name');
        if ($names->isEmpty()) {
            return 'There are no services available to book right now. Please contact the hospital.';
        }

        return sprintf(
            'I could not match that service. Available services at %s: %s. What service do you need?',
            $hospital->name,
            $names->implode(', '),
        );
    }

    private function bookingSummary(array $state, Hospital $hospital): string
    {
        $service = $this->serviceById((int) $state['service_id'], $hospital);
        $doctor = $this->eligibleDoctors($hospital, $service)->firstWhere('id', (int) $state['doctor_id']);
        $date = CarbonImmutable::parse((string) $state['date'])->toFormattedDateString();

        return sprintf(
            'Please confirm: %s with %s on %s at %s for %s, phone %s.',
            $service->name,
            $doctor?->name ?? 'your selected doctor',
            $date,
            $state['time'],
            $state['patient_name'],
            $state['patient_phone'],
        );
    }

    private function serviceById(int $serviceId, Hospital $hospital): Service
    {
        return $hospital->services()->active()->findOrFail($serviceId);
    }

    /**
     * @return Collection<int, User>
     */
    private function eligibleDoctors(Hospital $hospital, ?Service $service): Collection
    {
        $specialty = $this->requiredSpecialty($service);
        if ($specialty !== null) {
            $specialtyDoctors = $this->specialtyDoctors($hospital, $specialty);
            if ($specialtyDoctors->isNotEmpty()) {
                return $specialtyDoctors;
            }
        }

        return $this->activeDoctors($hospital)
            ->orderBy('name')
            ->get();
    }

    private function specialtyDoctors(Hospital $hospital, string $specialty): Collection
    {
        $normalizedSpecialty = $this->normalizeSpecialty($specialty);

        return $this->activeDoctors($hospital)
            ->get()
            ->filter(fn (User $doctor): bool => $this->specialtiesMatch(
                $normalizedSpecialty,
                $this->normalizeSpecialty((string) $doctor->specialization),
            ))
            ->sortBy('name')
            ->values();
    }

    private function specialtiesMatch(string $requiredSpecialty, string $doctorSpecialty): bool
    {
        if ($requiredSpecialty === '' || $doctorSpecialty === '') {
            return false;
        }

        return $requiredSpecialty === $doctorSpecialty
            || str_contains($requiredSpecialty, $doctorSpecialty)
            || str_contains($doctorSpecialty, $requiredSpecialty)
            || substr($requiredSpecialty, 0, 4) === substr($doctorSpecialty, 0, 4);
    }

    private function normalizeSpecialty(string $specialty): string
    {
        return trim(mb_strtolower($specialty));
    }

    private function activeDoctors(Hospital $hospital): HasMany
    {
        return $hospital->users()
            ->where('is_doctor', true)
            ->where('is_active', true);
    }

    private function requiredSpecialty(?Service $service): ?string
    {
        return filled($service?->requires_specialty)
            ? trim((string) $service->requires_specialty)
            : null;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function offerBookingAlternatives(
        string $sessionId,
        string $channel,
        string $message,
        Hospital $hospital,
        array $state,
    ): ChatResponse {
        $doctor = $hospital->users()->findOrFail((int) $state['doctor_id']);
        $requestedDate = CarbonImmutable::parse((string) $state['date']);
        $availableTimes = collect($this->availability->getAvailableSlots(
            (int) $doctor->id,
            $requestedDate->toDateString(),
        ))
            ->filter(fn (bool $isAvailable): bool => $isAvailable)
            ->keys()
            ->take(5)
            ->all();

        if ($availableTimes !== []) {
            $state['state'] = self::STATE_COLLECTING;
            $state['time'] = null;
            $times = implode(', ', $availableTimes);

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                sprintf(
                    "That time isn't available. The nearest available times with %s on %s are: %s. Which works for you?",
                    $doctor->name,
                    $requestedDate->format('F j'),
                    $times,
                ),
                $state,
            );
        }

        $searchFrom = $requestedDate->greaterThan(CarbonImmutable::today())
            ? $requestedDate->addDay()
            : CarbonImmutable::tomorrow();
        $availableDates = [];
        for ($dayOffset = 0; $dayOffset < 90 && count($availableDates) < 3; $dayOffset++) {
            $date = $searchFrom->addDays($dayOffset);
            $hasAvailableSlot = collect($this->availability->getAvailableSlots(
                (int) $doctor->id,
                $date->toDateString(),
            ))->contains(true);

            if ($hasAvailableSlot) {
                $availableDates[] = $date;
            }
        }

        $state['state'] = self::STATE_COLLECTING;
        $state['date'] = null;
        $state['time'] = null;
        $previousMessage = $this->latestConversation($sessionId, $channel)?->user_message;
        $isSwahili = $this->isSwahiliDateMessage($message)
            || (is_string($previousMessage) && $this->isSwahiliDateMessage($previousMessage));

        if ($availableDates === []) {
            $response = $isSwahili
                ? sprintf(
                    '%s hapatikani tarehe %s, na hakuna nafasi nyingine zilizopatikana. Tafadhali chagua daktari mwingine au wasiliana na hospitali.',
                    $doctor->name,
                    $requestedDate->format('d/m/Y'),
                )
                : sprintf(
                    '%s is not available on %s, and no upcoming times were found. Please choose another doctor or contact the hospital.',
                    $doctor->name,
                    $requestedDate->format('F j'),
                );
        } else {
            if ($isSwahili) {
                $dateList = implode(', ', array_map(
                    fn (CarbonImmutable $date): string => $date->format('d/m/Y'),
                    $availableDates,
                ));
                $response = sprintf(
                    '%s hapatikani tarehe %s. Tarehe zinazofuata zenye nafasi ni %s. Tafadhali chagua tarehe moja.',
                    $doctor->name,
                    $requestedDate->format('d/m/Y'),
                    $dateList,
                );
            } else {
                $formattedDates = array_map(
                    fn (CarbonImmutable $date): string => $date->format('l F j').' ('.$date->format('d/m/Y').')',
                    $availableDates,
                );
                $lastDate = array_pop($formattedDates);
                $dateList = $formattedDates === []
                    ? $lastDate
                    : implode(', ', $formattedDates).' or '.$lastDate;
                $response = sprintf(
                    '%s is not available on %s. The next available days are %s. Which day works for you?',
                    $doctor->name,
                    $requestedDate->format('l F j'),
                    $dateList,
                );
            }
        }

        return $this->respond($sessionId, $channel, $message, $response, $state);
    }

    private function normalize(string $value): string
    {
        $normalized = mb_strtolower($value);

        return trim((string) preg_replace('/[^\pL\pN]+/u', ' ', $normalized));
    }

    private function isBroadServicesOverview(string $message): bool
    {
        return preg_match(
            '/\b(what services|which services|list (?:all )?services|all services|full service list|services overview|services are available|huduma zenu|huduma gani)\b/iu',
            $message,
        ) === 1;
    }

    /**
     * @return array<int, string>
     */
    private function meaningfulTokens(string $value): array
    {
        return array_values(array_filter(
            explode(' ', $this->normalize($value)),
            fn (string $token): bool => mb_strlen($token) >= 3,
        ));
    }

    private function nextMissingField(array $state): ?string
    {
        foreach (array_keys(self::FIELD_PROMPTS) as $field) {
            if (! filled($state[$field] ?? null)) {
                return $field;
            }
        }

        return null;
    }

    private function sessionId(string $channelId, string $channel): string
    {
        if ($channel === 'whatsapp') {
            $phone = $this->mpesa->normalisePhone($channelId);

            return 'whatsapp:'.$phone;
        }

        return $channelId;
    }

    private function latestConversation(string $channelId, string $channel): ?Conversation
    {
        return Conversation::query()
            ->where('session_id', $this->sessionId($channelId, $channel))
            ->where('channel', $channel)
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function persistedState(string $channelId, string $channel): array
    {
        $conversation = $this->latestConversation($channelId, $channel);

        if (! $conversation || ! is_array($conversation->chat_state)) {
            return $this->emptyState();
        }

        return $this->validatedState($conversation->chat_state, $channelId, $channel);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function validatedState(array $state, string $channelId, string $channel): array
    {
        $allowedKeys = [
            'state',
            'service_id',
            'doctor_id',
            'date',
            'time',
            'patient_name',
            'patient_phone',
            'patient_email',
            'appointment_id',
            'lifecycle_action',
            'lifecycle_appointment_id',
            'appointment_candidates',
            'lifecycle_date',
            'lifecycle_time',
            'escalation_pending',
            'pending_escalation_message',
            'summary',
        ];
        $validStates = [
            self::STATE_IDLE,
            self::STATE_IDENTIFYING,
            self::STATE_COLLECTING,
            self::STATE_CONFIRMING,
            self::STATE_PAYMENT_PENDING,
            self::STATE_COMPLETED,
            self::STATE_CANCELLED,
            self::STATE_LIFECYCLE_SELECT,
            self::STATE_LIFECYCLE_CANCEL_CONFIRM,
            self::STATE_LIFECYCLE_RESCHEDULE_DATE,
            self::STATE_LIFECYCLE_RESCHEDULE_TIME,
            self::STATE_LIFECYCLE_RESCHEDULE_CONFIRM,
        ];

        $date = $state['date'] ?? null;
        $parsedDate = is_string($date)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone(config('app.timezone')))
            : false;
        $idsAreValid = true;
        foreach (['service_id', 'doctor_id', 'appointment_id'] as $idKey) {
            if (array_key_exists($idKey, $state)
                && $state[$idKey] !== null
                && (! is_int($state[$idKey]) || $state[$idKey] < 1)
            ) {
                $idsAreValid = false;
                break;
            }
        }

        $isValid = array_diff(array_keys($state), $allowedKeys) === []
            && in_array($state['state'] ?? null, $validStates, true)
            && $idsAreValid
            && (! array_key_exists('lifecycle_action', $state)
                || $state['lifecycle_action'] === null
                || in_array($state['lifecycle_action'], ['cancel', 'reschedule'], true))
            && (! array_key_exists('lifecycle_appointment_id', $state)
                || $state['lifecycle_appointment_id'] === null
                || (is_int($state['lifecycle_appointment_id']) && $state['lifecycle_appointment_id'] > 0))
            && $this->appointmentCandidatesAreValid($state['appointment_candidates'] ?? [])
            && $this->lifecycleDateIsValid($state['lifecycle_date'] ?? null)
            && (! array_key_exists('lifecycle_time', $state)
                || $state['lifecycle_time'] === null
                || (is_string($state['lifecycle_time']) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $state['lifecycle_time']) === 1))
            && ($date === null || (
                $parsedDate instanceof DateTimeImmutable
                && $parsedDate->format('Y-m-d') === $date
                && $parsedDate > now()->startOfDay()->toDateTimeImmutable()
            ))
            && (! array_key_exists('time', $state) || $state['time'] === null
                || (is_string($state['time']) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $state['time']) === 1))
            && (! array_key_exists('escalation_pending', $state) || is_bool($state['escalation_pending']))
            && $this->nullableStringFieldIsValid($state, 'patient_name', 255)
            && $this->nullableStringFieldIsValid($state, 'patient_phone', 32)
            && $this->nullableStringFieldIsValid($state, 'patient_email', 254)
            && $this->nullableStringFieldIsValid($state, 'pending_escalation_message', 2000)
            && $this->nullableStringFieldIsValid($state, 'summary', 2000);

        if ($isValid) {
            return $state;
        }

        Log::error('Invalid booking chat state was reset.', [
            'channel' => $channel,
            'session_hash' => hash('sha256', $this->sessionId($channelId, $channel)),
            'unknown_keys' => array_values(array_diff(array_keys($state), $allowedKeys)),
        ]);

        return $this->emptyState();
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function nullableStringFieldIsValid(array $state, string $key, int $maxLength): bool
    {
        return ! array_key_exists($key, $state)
            || $state[$key] === null
            || (is_string($state[$key]) && mb_strlen($state[$key]) <= $maxLength);
    }

    private function appointmentCandidatesAreValid(mixed $candidateIds): bool
    {
        if (! is_array($candidateIds) || count($candidateIds) > 10) {
            return false;
        }

        foreach ($candidateIds as $candidateId) {
            if (! is_int($candidateId) || $candidateId < 1) {
                return false;
            }
        }

        return count($candidateIds) === count(array_unique($candidateIds));
    }

    private function lifecycleDateIsValid(mixed $date): bool
    {
        if ($date === null) {
            return true;
        }

        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function reconcilePaymentState(array $state): array
    {
        if (($state['state'] ?? null) !== self::STATE_PAYMENT_PENDING
            || ! isset($state['appointment_id'])
        ) {
            return $state;
        }

        $appointment = AppointmentRequest::query()->find((int) $state['appointment_id']);
        if (! $appointment) {
            return $state;
        }

        if ($appointment->payment_status === 'paid') {
            $state['state'] = self::STATE_COMPLETED;
        } elseif ($appointment->status === AppointmentRequest::STATUS_CANCELLED
            || $appointment->payment_status === 'failed'
        ) {
            $state['state'] = self::STATE_CANCELLED;
        }

        return $state;
    }
}
