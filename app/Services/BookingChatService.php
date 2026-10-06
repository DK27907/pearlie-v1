<?php

namespace App\Services;

use App\Models\AppointmentRequest;
use App\Models\Conversation;
use App\Models\Hospital;
use App\Models\Service;
use App\Models\User;
use App\Services\Chat\ChatResponse;
use App\Support\DateParser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /**
     * @var array<string, string>
     */
    private const FIELD_PROMPTS = [
        'service_id' => 'What service do you need?',
        'doctor_id' => 'Which doctor would you prefer?',
        'date' => 'What date would you prefer?',
        'time' => 'What time would you prefer?',
        'patient_name' => 'What is your full name?',
        'patient_phone' => 'What Kenyan mobile number should we use?',
    ];

    public function __construct(
        private readonly DoctorAvailabilityService $availability,
        private readonly MpesaService $mpesa,
        private readonly PearlieServiceV2 $assistant,
        private readonly KnowledgeBaseService $knowledgeBase,
    ) {}

    public function handle(string $channelId, string $message, string $channel = 'web'): ChatResponse
    {
        $hospital = hospital();
        if (! $hospital instanceof Hospital) {
            throw new RuntimeException('A hospital context is required to process a booking conversation.');
        }

        $sessionId = $this->sessionId($channelId, $channel);
        $previousState = $this->persistedState($channelId, $channel);
        $state = $this->reconcilePaymentState($previousState);
        $intent = $this->extractIntent($message);

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

        if ($intent === 'cancel' && $state['state'] !== self::STATE_PAYMENT_PENDING) {
            $state = $this->emptyState(self::STATE_CANCELLED);

            return $this->respond($sessionId, $channel, $message, 'Your booking has been cancelled.', $state);
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

        if ($this->containsBookingKeyword($message)
            && $this->containsEscalationPhrase($message)
            && ! $this->isHumanRequestAboutExistingBooking($message)
        ) {
            $intent = 'book';
        }

        if ($intent === 'escalation') {
            return $this->handleExplicitHumanRequest($sessionId, $channel, $message, $state, $hospital);
        }

        if ($intent === 'continue_booking' && ! empty($state['state']) && $state['state'] !== self::STATE_IDLE) {
            $state['escalation_pending'] = false;

            return $this->advanceState($sessionId, $channel, $message, $hospital, $state);
        }

        if ($this->assistant->shouldEscalateMessage($message, $sessionId)
            && ! $this->containsBookingKeyword($message)
            && ! $this->containsEscalationPhrase($message)
        ) {
            return $this->delegateToAssistant($sessionId, $channel, $message, $state);
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
            $greeting = $this->knowledgeBase->search($message);

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                $greeting ?? sprintf(
                    "Hello! I'm %s, %s's healthcare assistant. I can help with services, hospital information, or booking an appointment.",
                    $hospital->chatbotName(),
                    $hospital->name,
                ),
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

        if (in_array($intent, ['yes', 'no'], true)) {
            $response = $state['state'] === self::STATE_COLLECTING
                ? $this->promptForNextField($state, $hospital)
                : 'There is no booking waiting for confirmation. Tell me what service or hospital information you need.';

            return $this->respond($sessionId, $channel, $message, $response, $state, source: 'clarification');
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

        return $this->reconcilePaymentState($conversation->chat_state);
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

        foreach (self::FIELD_PROMPTS as $field => $prompt) {
            if ($field === 'doctor_id' && $state[$field]) {
                continue;
            }

            if (! filled($state[$field] ?? null)) {
                $promptMessage = $field === 'service_id'
                    ? $this->servicePrompt($hospital)
                    : $prompt;

                if ($field === 'time' && $this->looksLikeTimeAttempt($message)) {
                    $promptMessage = "I couldn't understand the time you entered. Please use a format like:\n- 9am or 9:30am\n- 14:00 or 14:30\n- 2pm or 2:30pm\n\nWhat time works for you?";
                }

                return $this->respond($sessionId, $channel, $message, $promptMessage, $state);
            }
        }

        $requestedTime = (string) $state['time'];
        if ($this->isTimeOutsideHospitalHours($requestedTime)) {
            $doctor = $hospital->users()->find((int) $state['doctor_id']);
            $doctorName = $doctor?->name ?? 'Dr. Amina';
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
        return preg_match('/\b(book|booking|appointment|schedule|reserve|see\s+(?:a\s+)?doctor|i want to book|need\s+(?:an?\s+)?appointment|need\s+to\s+book)\b/iu', $message) === 1;
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

    private function isHumanRequestAboutExistingBooking(string $message): bool
    {
        return $this->hasExplicitHumanPhrase($message)
            && preg_match('/\b(?:about|regarding|concerning)\s+(?:my|the)\s+(?:booking|appointment)\b/iu', $message) === 1;
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
            'dental cleaning' => 'Dental Cleaning',
            'dental checkup' => 'Dental Cleaning',
            'dental' => 'Dental Cleaning',
            'general consultation' => 'General Consultation',
            'general' => 'General Consultation',
            'consultation' => 'General Consultation',
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
        $aliasMatches = [];
        foreach ($this->serviceAliases() as $alias => $serviceName) {
            $normalizedAlias = $this->normalize($alias);
            if ($normalizedAlias === '' || ! preg_match('/(?<![\pL\pN])'.preg_quote($normalizedAlias, '/').'(?![\pL\pN])/u', $normalizedMessage)) {
                continue;
            }

            $candidateServices = $services->filter(function (Service $candidate) use ($normalizedAlias, $serviceName): bool {
                $normalizedCandidate = $this->normalize($candidate->name);
                $normalizedTarget = $this->normalize($serviceName);

                if ($normalizedCandidate === $normalizedTarget) {
                    return true;
                }

                $candidateTokens = $this->meaningfulTokens($normalizedCandidate);
                $aliasTokens = $this->meaningfulTokens($normalizedAlias);
                if ($candidateTokens === [] || $aliasTokens === []) {
                    return false;
                }

                return count(array_intersect($candidateTokens, $aliasTokens)) > 0
                    || str_contains($normalizedCandidate, $normalizedAlias)
                    || str_contains($normalizedAlias, $normalizedCandidate);
            });

            foreach ($candidateServices as $service) {
                $aliasMatches[$service->id] = $service;
            }
        }

        $aliasMatches = array_values($aliasMatches);
        if (count($aliasMatches) === 1) {
            return $aliasMatches[0];
        }

        if (count($aliasMatches) > 1) {
            return null;
        }

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

        if ($date === null || $date->lessThan(CarbonImmutable::today())) {
            return null;
        }

        return $date;
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

    private function extractIntent(string $message): string
    {
        $normalized = mb_strtolower(trim($message));

        if (preg_match('/\b(start over|start again|restart|reset|begin again|anza upya|tuanzie upya)\b/u', $normalized)) {
            return 'reset';
        }
        if (preg_match('/\b(cancel|never mind|nevermind|hapana|stop booking|forget it|don\'t|stop)\b/u', $normalized)) {
            return 'cancel';
        }
        if (preg_match('/^\s*(?:yes|y|yeah|yep|ok|confirm|book it|proceed|ndiyo|ndio|sawa)\b/u', $normalized)) {
            return 'yes';
        }
        if (preg_match('/^\s*(?:no|n|nope|cancel)\b/u', $normalized)) {
            return 'no';
        }
        if ($this->containsEscalationPhrase($message)
            || $this->isHumanRequestAboutExistingBooking($message)
        ) {
            return 'escalation';
        }
        if (preg_match('/\b(status|payment status|booking status)\b/u', $normalized)) {
            return 'status';
        }
        if (preg_match('/\b(continue booking|resume booking)\b/u', $normalized)) {
            return 'continue_booking';
        }
        if ($this->containsBookingKeyword($message)) {
            return 'book';
        }
        if (preg_match('/^(?:hi|hello|hey|habari|hujambo|jambo|mambo|vipi|niaje|sasa|shikamoo|marahaba|salama|poa|freshi|mzuri|nzuri|good morning|good afternoon|good evening)[.!?,\s]*$/u', $normalized)) {
            return 'greeting';
        }
        if (preg_match('/\b(thank you|thanks|thank you very much|asante|shukran|appreciate it)\b/iu', $normalized)) {
            return 'thanks';
        }
        if (preg_match('/\b(help|support|what can you do|how does this work)\b/u', $normalized)) {
            return 'help';
        }
        if (preg_match('/\b(price|prices|fee|fees|cost|costs|how much|charge|charges|bei|ada)\b/u', $normalized)) {
            return 'pricing';
        }
        if (preg_match('/\b(what services|which services|services do you offer|services are available|hospital services|huduma zenu|huduma gani)\b/iu', $normalized)) {
            return 'services';
        }
        if (preg_match('/\b(dentist|dentists|doctor|doctors|specialist|specialists|radiologist|physiotherapist|obstetrician|gynecologist|gynaecologist)\b/iu', $normalized)
            && preg_match('/\b(do you have|is there|are there|available|availability|which|who|can i see|mna|yupo|wapo)\b/iu', $normalized)
        ) {
            return 'doctors';
        }
        if (preg_match('/\b(opening hours|working hours|outpatient hours|emergency hours|what time|when do you open|when are you open|hours|saa ngapi|mnafunga)\b/iu', $normalized)) {
            return 'hours';
        }
        if (preg_match('/\b(where are you|where is|location|address|directions|mko wapi|iko wapi)\b/iu', $normalized)) {
            return 'location';
        }
        if (preg_match('/\b(contact(?: the hospital)?|phone number|hospital phone|telephone number|email address|hospital email|website|whatsapp number|call the hospital|what is your phone number)\b/iu', $normalized)) {
            return 'contact';
        }
        if ($this->containsBookingKeyword($message)) {
            return 'book';
        }
        if (preg_match('/\b(capital of|president of|population of|weather|stock price|who invented|who won|define|meaning of|translate)\b/u', $normalized)) {
            return 'out_of_scope';
        }

        return 'other';
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
        $patientPhone = filled($state['patient_phone'] ?? null)
            ? (string) $state['patient_phone']
            : null;
        $result = $this->assistant->processMessageWithPatientPhone($message, $sessionId, $channel, $patientPhone);
        $response = (string) ($result['response'] ?? 'I could not process that message. Please try again.');
        $this->persistState(
            $sessionId,
            $channel,
            $message,
            $response,
            $state,
            escalated: (bool) ($result['escalated'] ?? false),
        );

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
    private function promptForNextField(array $state, Hospital $hospital): string
    {
        $field = $this->nextMissingField($state);
        if ($field === 'service_id') {
            return $this->servicePrompt($hospital);
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
            'Sure, I can help you book an appointment at %s.\n\nWhat service do you need?',
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
        return $this->activeDoctors($hospital)
            ->whereRaw('LOWER(TRIM(specialization)) = ?', [mb_strtolower(trim($specialty))])
            ->orderBy('name')
            ->get();
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

        if ($availableDates === []) {
            $response = sprintf(
                '%s is not available on %s, and no upcoming times were found. Please choose another doctor or contact the hospital.',
                $doctor->name,
                $requestedDate->format('F j'),
            );
        } else {
            $formattedDates = array_map(
                fn (CarbonImmutable $date): string => $date->format('l F j'),
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

        return $conversation && is_array($conversation->chat_state)
            ? $conversation->chat_state
            : $this->emptyState();
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
