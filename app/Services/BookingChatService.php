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
        'service_id' => 'Which service would you like to book?',
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

        if ($this->assistant->shouldEscalateMessage($message, $sessionId)) {
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
            return $this->respond(
                $sessionId,
                $channel,
                $message,
                $this->servicePrompt($hospital),
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

            if ($doctors->count() === 1) {
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
        if (preg_match('/\b([1-9]|1[0-2])(?::([0-5]\d))?\s*(a\.?m\.?|p\.?m\.?)\b/iu', $message, $matches)) {
            $hour = (int) $matches[1] % 12;
            if (str_starts_with(mb_strtolower($matches[3]), 'p')) {
                $hour += 12;
            }

            return sprintf('%02d:%02d', $hour, (int) ($matches[2] ?? 0));
        }

        if (preg_match('/\b([01]\d|2[0-3]):([0-5]\d)\b/', $message, $matches)) {
            return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
        }

        return null;
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
        if (preg_match('/\b(cancel|never mind|nevermind|hapana|stop booking)\b/u', $normalized)) {
            return 'cancel';
        }
        if (preg_match('/^\s*(?:yes|y|yeah|yep|confirm|ndiyo|ndio|sawa)\b/u', $normalized)) {
            return 'yes';
        }
        if (preg_match('/^\s*(?:no|n|nope)\b/u', $normalized)) {
            return 'no';
        }
        if (preg_match('/\b(status|payment status|booking status)\b/u', $normalized)) {
            return 'status';
        }
        if (preg_match('/^(?:hi|hello|hey|habari|hujambo|jambo|mambo|vipi|niaje|sasa|shikamoo|marahaba|salama|poa|freshi|mzuri|nzuri)[.!?,\s]*$/u', $normalized)) {
            return 'greeting';
        }
        if (preg_match('/\b(thank you|thanks|thank you very much|asante|shukran)\b/iu', $normalized)) {
            return 'thanks';
        }
        if (preg_match('/\b(help|support|what can you do)\b/u', $normalized)) {
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
        if (preg_match('/\b(contact(?: the hospital)?|phone number|hospital phone|telephone number|email address|hospital email|website|whatsapp number|call the hospital)\b/iu', $normalized)) {
            return 'contact';
        }
        if (preg_match('/\b(book|booking|appointment|schedule|miadi|need)\b/u', $normalized)) {
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
    ): ChatResponse {
        $this->persistState($sessionId, $channel, $userMessage, $response, $state, $appointmentId);

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

    private function servicePrompt(Hospital $hospital): string
    {
        $names = $hospital->services()->active()->orderBy('name')->pluck('name');
        if ($names->isEmpty()) {
            return 'There are no services available to book right now. Please contact the hospital.';
        }

        return 'Which service would you like to book? Available services: '.$names->implode(', ').'.';
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
