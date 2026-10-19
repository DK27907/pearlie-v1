<?php

namespace App\Services;

use App\Exceptions\AppointmentLifecycleException;
use App\Exceptions\MissingProviderCredentialsException;
use App\Models\AppointmentRequest;
use App\Models\Conversation;
use App\Models\Hospital;
use App\Models\Service;
use App\Models\User;
use App\Services\Chat\ChatResponse;
use App\Support\CacheLockRunner;
use App\Support\DateParser;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        'date' => 'What date would you prefer? Use DD/MM/YYYY (e.g. 15/10/2026), YYYY-MM-DD, or say "tomorrow".',
        'patient_name' => 'What is your full name?',
        'patient_phone' => 'What Kenyan mobile number should we use?',
        'doctor_id' => 'Which doctor would you prefer?',
        'time' => 'What time would you prefer?',
    ];

    private const SWAHILI_FIELD_PROMPTS = [
        'service_id' => 'Unahitaji huduma gani?',
        'date' => "Ungependa miadi tarehe gani? Tumia DD/MM/YYYY (mfano 15/10/2026), YYYY-MM-DD, au sema 'kesho'.",
        'patient_name' => 'Jina lako kamili ni nani?',
        'patient_phone' => 'Tutumie nambari gani ya simu ya Kenya?',
        'doctor_id' => 'Ungependa kumwona daktari gani?',
        'time' => 'Ungependa miadi iwe saa ngapi?',
    ];

    private const LANGUAGE_ENGLISH = 'en';

    private const LANGUAGE_SWAHILI = 'sw';

    public function __construct(
        private readonly DoctorAvailabilityService $availability,
        private readonly MpesaService $mpesa,
        private readonly PearlieServiceV2 $assistant,
        private readonly KnowledgeBaseService $knowledgeBase,
        private readonly AppointmentPolicyService $appointmentPolicy,
        private readonly AppointmentLifecycleService $appointmentLifecycle,
        private readonly ChatLanguageDetector $languageDetector,
    ) {}

    public function handle(string $channelId, string $message, string $channel = 'web'): ChatResponse
    {
        $hospital = hospital();
        if (! $hospital instanceof Hospital) {
            throw new RuntimeException('A hospital context is required to process a booking conversation.');
        }

        $sessionId = $this->sessionId($channelId, $channel);

        return CacheLockRunner::run(
            Cache::lock($this->sessionLockKey($hospital, $channel, $sessionId), 120),
            10,
            fn (): ChatResponse => $this->handleWithinSessionLock(
                $channelId,
                $sessionId,
                $message,
                $channel,
                $hospital,
            ),
        );
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
        $state['language'] = $this->resolveReplyLanguage($message, $state);

        if (($previousState['state'] ?? self::STATE_IDLE) === self::STATE_PAYMENT_PENDING
            && in_array($state['state'], [self::STATE_COMPLETED, self::STATE_CANCELLED], true)
        ) {
            $callbackResponse = $state['state'] === self::STATE_COMPLETED
                ? ($state['language'] === self::LANGUAGE_SWAHILI
                    ? 'Malipo yamepokelewa. Miadi yako imesasishwa.'
                    : 'Payment received. Your appointment has been updated.')
                : ($state['language'] === self::LANGUAGE_SWAHILI
                    ? 'Malipo hayakukamilika na miadi imeghairiwa.'
                    : 'The payment was not completed and the booking has been cancelled.');

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
            $state = $this->emptyState(
                language: $state['language'],
                stateVersion: $state['state_version'],
            );
        }

        if (trim($message) === '') {
            return $this->respond(
                $sessionId,
                $channel,
                $message,
                $state['language'] === self::LANGUAGE_SWAHILI
                    ? 'Tafadhali andika ujumbe ili niweze kukusaidia.'
                    : 'Please type a message so I can help.',
                $state,
            );
        }

        if (preg_match('/[\pL\pN]/u', $message) !== 1) {
            return $this->respond(
                $sessionId,
                $channel,
                $message,
                $state['language'] === self::LANGUAGE_SWAHILI
                    ? 'Tafadhali tuma swali kwa maneno ili niweze kukusaidia.'
                    : 'Please send a question using words so I can help.',
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
                    ?? ($state['language'] === self::LANGUAGE_SWAHILI
                        ? 'Kwa dharura, wasiliana na timu ya dharura ya hospitali mara moja.'
                        : 'For an emergency, contact the hospital emergency team immediately.'),
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
                $state['language'] === self::LANGUAGE_SWAHILI
                    ? 'Ombi lako la miadi linasubiri uthibitisho wa malipo ya M-Pesa.'
                    : 'Your booking is waiting for the M-Pesa payment callback.',
                $state,
                $state['appointment_id'],
                true,
            );
        }

        if ($intent === 'reset') {
            $state = $this->emptyState(
                language: $state['language'],
                stateVersion: $state['state_version'],
            );

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                $state['language'] === self::LANGUAGE_SWAHILI
                    ? 'Taarifa za miadi zimefutwa. Ungependa nikusaidie nini?'
                    : 'Your booking details have been cleared. What would you like help with?',
                $state,
                source: 'booking_reset',
            );
        }

        if ($intent === 'cancel') {
            $state = $this->emptyState(
                self::STATE_CANCELLED,
                $state['language'],
                $state['state_version'],
            );

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                $state['language'] === self::LANGUAGE_SWAHILI
                    ? 'Miadi yako imeghairiwa.'
                    : 'Your booking has been cancelled.',
                $state,
            );
        }

        if (in_array($state['state'], [self::STATE_COLLECTING, self::STATE_CONFIRMING], true)
            && (
                ($intent === 'book'
                    && (
                        $state['state'] === self::STATE_CONFIRMING
                        || filled($state['service_id'])
                        || filled($state['date'])
                    ))
                || preg_match('/^\s*miadi\s*[?!.]*\s*$/iu', $message) === 1
            )
        ) {
            $this->assistant->clearPendingEscalation($sessionId);
            $state = $this->emptyState(
                language: $state['language'],
                stateVersion: $state['state_version'],
            );
            $isSwahili = $state['language'] === self::LANGUAGE_SWAHILI;

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                $isSwahili
                    ? 'Tuanze upya. Unahitaji huduma gani?'
                    : "Let's start over. What service do you need?",
                $state,
                source: 'booking_reset',
            );
        }

        if ($intent === 'book') {
            $state['escalation_pending'] = false;
            $state['pending_escalation_message'] = null;
            $state['unrecognized_message_count'] = 0;
        }

        if (in_array($state['state'], [
            self::STATE_IDENTIFYING,
            self::STATE_COLLECTING,
            self::STATE_CONFIRMING,
        ], true)) {
            $this->assistant->clearPendingEscalation($sessionId);
        }

        if (($state['escalation_pending'] ?? false) && $this->extractPhone($message) !== null) {
            $escalationPhone = $this->extractPhone($message);
            $pendingMessage = (string) ($state['pending_escalation_message'] ?? $message);
            $escalationResponse = $state['language'] === self::LANGUAGE_SWAHILI
                ? sprintf('Asante. Ombi lako limetumwa kwa timu ya %s. Ninakuunganisha na mhudumu wa afya; mtu atawasiliana nawe hivi karibuni.', $hospital->name)
                : sprintf(
                    "Thank you. Your request has been passed to the %s team. I'm connecting you to a health worker now; someone will reach out shortly.",
                    $hospital->name,
                );
            $state['patient_phone'] ??= $escalationPhone;
            $state['escalation_pending'] = false;
            $state['pending_escalation_message'] = null;
            $state['unrecognized_message_count'] = 0;

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                $escalationResponse,
                $state,
                source: 'human_escalation',
                escalated: true,
                beforePersist: fn () => app(EscalationService::class)->createEscalation(
                    $sessionId,
                    $pendingMessage,
                    $escalationResponse,
                    $escalationPhone,
                ),
            );
        }

        if ($intent === 'escalation') {
            return $this->handleExplicitHumanRequest($sessionId, $channel, $message, $state, $hospital);
        }

        if ($intent === 'continue_booking' && ! empty($state['state']) && $state['state'] !== self::STATE_IDLE) {
            $state['escalation_pending'] = false;
            $state['pending_escalation_message'] = null;
            $state['unrecognized_message_count'] = 0;

            $dateWasRequested = $state['state'] === self::STATE_COLLECTING
                && $this->nextMissingField($state) === 'date';

            return $this->advanceState(
                $sessionId,
                $channel,
                $message,
                $hospital,
                $state,
                $dateWasRequested,
                $intent,
            );
        }

        // Handle yes/no clarification before delegating to assistant
        // This prevents "yes" or "no" in IDLE state from triggering escalation
        // But skip this check if in CONFIRMING state (handled specially below)
        if (in_array($intent, ['yes', 'no'], true) && $state['state'] !== self::STATE_CONFIRMING) {
            $response = $state['state'] === self::STATE_COLLECTING
                ? $this->promptForNextField($state, $hospital, $message, $sessionId, $channel)
                : ($state['language'] === self::LANGUAGE_SWAHILI
                    ? 'Hakuna miadi inayosubiri uthibitisho. Je, ungependa kuweka miadi?'
                    : 'There is no booking waiting for confirmation. Would you like to book an appointment?');

            return $this->respond($sessionId, $channel, $message, $response, $state, source: 'clarification');
        }

        if ($state['state'] === self::STATE_IDLE
            && $intent === 'other'
            && $this->isLowSignalInput($message)
        ) {
            $state['unrecognized_message_count'] = min(
                3,
                (int) ($state['unrecognized_message_count'] ?? 0) + 1,
            );

            if ($state['unrecognized_message_count'] >= 3) {
                $language = $state['language'];
                $state = $this->emptyState(
                    language: $language,
                    stateVersion: $state['state_version'],
                );

                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    $this->recoveryMenuPrompt($state),
                    $state,
                    source: 'booking_recovery',
                );
            }

            $response = $state['language'] === self::LANGUAGE_SWAHILI
                ? 'Samahani, sikuweza kuelewa. Tafadhali eleza unachohitaji.'
                : "Sorry, I didn't catch that. Could you tell me a little more about what you need?";

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                $response,
                $state,
                source: 'clarification',
            );
        }

        if ($intent === 'other'
            && $state['state'] === self::STATE_IDLE
            && $this->assistant->shouldEscalateMessage($message, $sessionId)
            && ! $this->containsBookingKeyword($message)
            && ! $this->containsEscalationPhrase($message)
            && $this->extractService($message, $hospital) === null
        ) {
            return $this->delegateToAssistant($sessionId, $channel, $message, $state);
        }

        if ($intent === 'pricing') {
            $response = $state['language'] === self::LANGUAGE_SWAHILI
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
            $isSwahiliGreeting = $state['language'] === self::LANGUAGE_SWAHILI;
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
            $response = $state['language'] === self::LANGUAGE_SWAHILI
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
                ? sprintf(
                    $state['language'] === self::LANGUAGE_SWAHILI
                        ? '%s haina huduma zilizoorodheshwa kwa sasa. Tafadhali wasiliana na hospitali kwa msaada.'
                        : '%s has no active services listed right now. Please contact the hospital for help.',
                    $hospital->name,
                )
                : sprintf(
                    $state['language'] === self::LANGUAGE_SWAHILI
                        ? 'Huduma zinazopatikana katika %s ni: %s.'
                        : 'Available services at %s: %s.',
                    $hospital->name,
                    $services->implode(', '),
                );

            return $this->respond($sessionId, $channel, $message, $response, $state, source: 'hospital_services');
        }

        if ($intent === 'doctors') {
            $normalizedMessage = mb_strtolower($message);
            $asksAvailability = preg_match(
                '/\b(?:who|which)\s+(?:is\s+)?available\s+(?:today|tomorrow|on\s+[\pL\d-]+)/iu',
                $message,
            ) === 1;

            if ($asksAvailability && ($requestedDate = DateParser::parse($message)) !== null) {
                $availableDoctors = collect($this->availability->getAllDoctorsWithSlots(
                    $hospital,
                    $requestedDate->toDateString(),
                ))
                    ->filter(fn (array $doctorAvailability): bool => collect($doctorAvailability['slots'])->contains(true))
                    ->values();

                $response = $availableDoctors->isEmpty()
                    ? sprintf(
                        'I do not see any appointment slots listed as available at %s on %s. Please contact the hospital to confirm current availability.',
                        $hospital->name,
                        $requestedDate->format('l, j F Y'),
                    )
                    : sprintf(
                        'Doctors with appointment slots at %s on %s: %s.',
                        $hospital->name,
                        $requestedDate->format('l, j F Y'),
                        $availableDoctors
                            ->map(fn (array $doctorAvailability): string => $doctorAvailability['doctor']->name
                                .' ('.collect($doctorAvailability['slots'])->filter()->keys()->implode(', ').')')
                            ->implode(', '),
                    );

                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    $response,
                    $state,
                    source: 'hospital_doctor_availability',
                );
            }

            $listedDoctors = $hospital->doctors()
                ->where('is_active', true)
                ->orderBy('name')
                ->get();
            $doctors = $listedDoctors;
            $specialtyTerms = match (true) {
                preg_match('/\b(dentist|dentists|dental|daktari wa meno|madaktari wa meno)\b/iu', $normalizedMessage) === 1 => ['dent', 'oral'],
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

            if ($doctors->isEmpty() && $specialtyTerms !== []) {
                if ($listedDoctors->isNotEmpty()) {
                    $doctorNames = $listedDoctors
                        ->map(fn (User $doctor): string => $doctor->name.(
                            filled($doctor->specialization) ? ' ('.$doctor->specialization.')' : ''
                        ))
                        ->implode(', ');
                    $response = $state['language'] === self::LANGUAGE_SWAHILI
                    ? sprintf(
                        'Sioni daktari wa taaluma hii aliyeorodheshwa mahususi katika %s. Madaktari walioorodheshwa sasa ni: %s. Tafadhali wasiliana na hospitali kuthibitisha huduma hii.',
                        $hospital->name,
                        $doctorNames,
                    )
                    : sprintf(
                        'I cannot confirm a doctor in this specialty from %s’s active staff listing. Currently listed doctors are: %s. Please contact the hospital to confirm this service.',
                        $hospital->name,
                        $doctorNames,
                    );

                    return $this->respond($sessionId, $channel, $message, $response, $state, source: 'hospital_doctors');
                }
            }

            $response = $doctors->isEmpty()
                ? sprintf(
                    $state['language'] === self::LANGUAGE_SWAHILI
                    ? 'Sikupata %s anayepatikana katika %s. Tafadhali wasiliana na hospitali kuthibitisha upatikanaji wa kliniki.'
                    : 'I could not find an active %s listed at %s. Please contact the hospital to confirm current clinic availability.',
                    $state['language'] === self::LANGUAGE_SWAHILI
                        ? ($specialtyTerms !== [] ? 'daktari wa taaluma hiyo' : 'daktari')
                        : ($specialtyTerms !== [] ? 'doctor in that specialty' : 'doctor'),
                    $hospital->name,
                )
                : sprintf(
                    $state['language'] === self::LANGUAGE_SWAHILI
                        ? 'Madaktari walioorodheshwa%s: %s. Nijulishe ikiwa ungependa nikague muda wa miadi.'
                        : 'Doctors currently listed%s: %s. Tell me if you would like me to check appointment times.',
                    $state['language'] === self::LANGUAGE_SWAHILI
                        ? ($specialtyTerms !== [] ? ' wa taaluma hii' : ' katika '.$hospital->name)
                        : ($specialtyTerms !== [] ? ' for this specialty' : ' at '.$hospital->name),
                    $doctors->map(fn (User $doctor): string => $doctor->name.(
                        filled($doctor->specialization) ? ' ('.$doctor->specialization.')' : ''
                    ))->implode(', '),
                );

            return $this->respond($sessionId, $channel, $message, $response, $state, source: 'hospital_doctors');
        }

        if (in_array($intent, ['hours', 'location', 'contact'], true)) {
            if ($intent === 'hours') {
                $businessHours = collect($hospital->business_hours ?? [])
                    ->map(function (mixed $hours, string $day) use ($state): ?string {
                        if (! is_array($hours)) {
                            return null;
                        }

                        if ($hours['closed'] ?? false) {
                            return ucfirst($day).': '.(
                                $state['language'] === self::LANGUAGE_SWAHILI ? 'imefungwa' : 'closed'
                            );
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
                    : ($hospital->hours_outpatient ?: (
                        $state['language'] === self::LANGUAGE_SWAHILI
                            ? 'Wasiliana na hospitali kuthibitisha'
                            : 'Please contact the hospital to confirm'
                    ));

                $response = sprintf(
                    $state['language'] === self::LANGUAGE_SWAHILI
                        ? 'Saa za huduma za wagonjwa wa nje katika %s: %s. Saa za dharura: %s.'
                        : '%s outpatient hours: %s. Emergency hours: %s.',
                    $hospital->name,
                    $outpatientHours,
                    $hospital->hours_emergency ?: (
                        $state['language'] === self::LANGUAGE_SWAHILI
                            ? 'Wasiliana na hospitali kuthibitisha'
                            : 'Please contact the hospital to confirm'
                    ),
                );
            } elseif ($intent === 'location') {
                $response = $hospital->address
                    ? sprintf(
                        $state['language'] === self::LANGUAGE_SWAHILI
                            ? '%s iko katika %s.'
                            : '%s is located at %s.',
                        $hospital->name,
                        $hospital->address,
                    )
                    : sprintf(
                        $state['language'] === self::LANGUAGE_SWAHILI
                            ? 'Tafadhali wasiliana na %s kwa taarifa za eneo ilipo.'
                            : 'Please contact %s for its current location.',
                        $hospital->name,
                    );
            } else {
                $contacts = collect([
                    $hospital->phone ? ($state['language'] === self::LANGUAGE_SWAHILI ? 'Simu: ' : 'Phone: ').$hospital->phone : null,
                    $hospital->email ? ($state['language'] === self::LANGUAGE_SWAHILI ? 'Barua pepe: ' : 'Email: ').$hospital->email : null,
                    $hospital->website ? ($state['language'] === self::LANGUAGE_SWAHILI ? 'Tovuti: ' : 'Website: ').$hospital->website : null,
                ])->filter();
                $response = $contacts->isNotEmpty()
                    ? $contacts->implode('. ')
                    : sprintf(
                        $state['language'] === self::LANGUAGE_SWAHILI
                            ? 'Tafadhali wasiliana na %s kupitia njia zake za kawaida.'
                            : 'Please contact %s through its usual channels.',
                        $hospital->name,
                    );
            }

            return $this->respond($sessionId, $channel, $message, $response, $state, source: 'hospital_information');
        }

        if ($intent === 'out_of_scope') {
            return $this->respond(
                $sessionId,
                $channel,
                $message,
                sprintf(
                    $state['language'] === self::LANGUAGE_SWAHILI
                        ? 'Naweza kukusaidia kuhusu huduma za %s, miadi na taarifa za hospitali. Nikusaidie nini?'
                        : 'I can help with %s services, appointments, and hospital information. What can I help you with?',
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
                    $state['language'] === self::LANGUAGE_SWAHILI
                        ? 'Ombi lako la mwisho la miadi lina hali ya %s. Hali ya malipo: %s.'
                        : 'Your latest appointment request is %s. Payment status: %s.',
                    str_replace('_', ' ', (string) $appointment->status),
                    str_replace('_', ' ', (string) $appointment->payment_status),
                )
                : ($state['language'] === self::LANGUAGE_SWAHILI
                    ? 'Sikupata ombi la miadi kwenye mazungumzo haya bado. Naweza kukusaidia kuweka miadi.'
                    : 'I could not find an appointment request in this chat yet. I can help you book one.');

            return $this->respond($sessionId, $channel, $message, $response, $state, source: 'appointment_status');
        }

        if ($intent === 'help') {
            return $this->respond(
                $sessionId,
                $channel,
                $message,
                sprintf(
                    $state['language'] === self::LANGUAGE_SWAHILI
                        ? 'Naweza kukusaidia kuhusu huduma za %s, taarifa za hospitali na kuweka miadi. Ili kuweka miadi, niambie huduma unayohitaji.'
                        : 'I can help with %s services, hospital information, and appointment bookings. To book, tell me the service you need.',
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
                $state['language'] === self::LANGUAGE_SWAHILI
                    ? 'Kuweka miadi mtandaoni hakujajumuishwa kwenye mpango wa hospitali hii. Tafadhali wasiliana na hospitali moja kwa moja kwa msaada.'
                    : 'Online appointment booking is not included in this hospital plan yet. Please contact the hospital directly for assistance.',
                $this->emptyState(
                    language: $state['language'],
                    stateVersion: $state['state_version'],
                ),
                source: 'subscription_feature_unavailable',
            );
        }

        if ($state['state'] === self::STATE_CONFIRMING) {
            if ($intent === 'yes') {
                return $this->confirmBooking($sessionId, $channel, $message, $hospital, $state);
            }

            if ($intent === 'no') {
                $state = $this->emptyState(
                    language: $state['language'],
                    stateVersion: $state['state_version'],
                );

                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    $state['language'] === self::LANGUAGE_SWAHILI
                        ? 'Sawa, nimeghairi ombi hili la miadi.'
                        : "OK, I've cancelled this booking request.",
                    $state,
                );
            }

            $state = $this->mergeFields($state, $message, $hospital);

            return $this->advanceState($sessionId, $channel, $message, $hospital, $state, intent: $intent);
        }

        if ($state['state'] === self::STATE_IDLE
            && ! in_array($intent, ['book'], true)
            && $this->extractService($message, $hospital) === null
            && ! $this->containsBookingKeyword($message)
        ) {
            return $this->delegateToAssistant($sessionId, $channel, $message, $state);
        }

        $this->assistant->clearPendingEscalation($sessionId);
        $previousState = $state;
        $wasCollecting = $state['state'] === self::STATE_COLLECTING;
        $dateWasRequested = $wasCollecting && $this->nextMissingField($previousState) === 'date';
        $state['state'] = self::STATE_IDENTIFYING;
        if ($channel === 'whatsapp' && ! filled($state['patient_phone'])) {
            $state['patient_phone'] = substr($sessionId, strlen('whatsapp:'));
        }
        $state = $this->mergeFields($state, $message, $hospital);

        if ($wasCollecting && $intent === 'other') {
            $bookingFields = [
                'service_id',
                'doctor_id',
                'date',
                'time',
                'patient_name',
                'patient_phone',
                'patient_email',
            ];
            $fieldWasCollected = false;

            foreach ($bookingFields as $field) {
                if (($previousState[$field] ?? null) !== ($state[$field] ?? null)) {
                    $fieldWasCollected = true;
                    break;
                }
            }

            $state['unrecognized_message_count'] = $fieldWasCollected
                ? 0
                : min(3, (int) ($previousState['unrecognized_message_count'] ?? 0) + 1);

            if (! $fieldWasCollected && $state['unrecognized_message_count'] >= 3) {
                $language = $state['language'];
                $state = $this->emptyState(
                    language: $language,
                    stateVersion: $state['state_version'],
                );

                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    $this->recoveryMenuPrompt($state),
                    $state,
                    source: 'booking_recovery',
                );
            }

            if (! $fieldWasCollected && $state['unrecognized_message_count'] >= 2) {
                $state['state'] = self::STATE_COLLECTING;

                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    $this->bookingRecoveryPrompt(
                        $state,
                        $hospital,
                        $message,
                        $sessionId,
                        $channel,
                    ),
                    $state,
                    source: 'booking_recovery',
                );
            }
        } else {
            $state['unrecognized_message_count'] = 0;
        }

        return $this->advanceState(
            $sessionId,
            $channel,
            $message,
            $hospital,
            $state,
            $dateWasRequested,
            $intent,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getState(string $channelId, string $channel = 'web'): array
    {
        $conversation = $this->latestStateConversation($this->sessionId($channelId, $channel), $channel);
        if (! $conversation || ! is_array($conversation->chat_state)) {
            return $this->emptyState();
        }

        return $this->reconcilePaymentState(
            $this->validatedState($conversation->chat_state, $channelId, $channel),
        );
    }

    public function reset(string $channelId, string $channel = 'web'): bool
    {
        $hospital = hospital();
        if (! $hospital instanceof Hospital) {
            throw new RuntimeException('A hospital context is required to reset a booking conversation.');
        }

        $sessionId = $this->sessionId($channelId, $channel);

        return CacheLockRunner::run(
            Cache::lock($this->sessionLockKey($hospital, $channel, $sessionId), 120),
            10,
            function () use ($channelId, $channel, $sessionId): bool {
                $previousState = $this->persistedState($channelId, $channel);
                $state = $this->emptyState(
                    language: $previousState['language'],
                    stateVersion: $previousState['state_version'],
                );

                return $this->persistState(
                    $sessionId,
                    $channel,
                    '[booking reset]',
                    '',
                    $state,
                );
            },
        );
    }

    private function advanceState(
        string $sessionId,
        string $channel,
        string $message,
        Hospital $hospital,
        array $state,
        bool $dateWasRequested = false,
        string $intent = 'other',
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
                ? $this->serviceUnavailablePrompt($hospital, $state['language'])
                : $this->servicePrompt($hospital, $state['language']);

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                $servicePrompt,
                $state,
            );
        }

        $automaticallySelectedDoctor = null;
        $service = $this->serviceById((int) $state['service_id'], $hospital);

        if (! $state['doctor_id']) {
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

            if ($requiredSpecialty !== null
                && $hasMatchingSpecialty
                && $doctors->count() > 1
                && $mentionedDoctor === null
            ) {
                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    $this->doctorPrompt($state, $hospital),
                    $state,
                );
            }

            if ($requiredSpecialty !== null
                && ! $hasMatchingSpecialty
                && $doctors->count() > 1
                && $doctors->isNotEmpty()
            ) {
                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    $state['language'] === self::LANGUAGE_SWAHILI
                        ? sprintf(
                            'Hakuna daktari bingwa wa %s anayepatikana kwa sasa, lakini madaktari hawa wanaweza kusaidia: %s. Ungependa kumwona nani?',
                            $requiredSpecialty,
                            $doctors->pluck('name')->implode(', '),
                        )
                        : sprintf(
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
                    $state['language'] === self::LANGUAGE_SWAHILI
                        ? 'Hakuna madaktari wanaopatikana kwa huduma hii. Tafadhali wasiliana na hospitali kwa msaada.'
                        : 'There are no available doctors for this service. Please contact the hospital for assistance.',
                    $state,
                );
            }
        }

        $state['state'] = self::STATE_COLLECTING;

        if ($this->nextMissingField($state) === 'date') {
            $requestedDate = DateParser::parse($message);
            $invalidDateError = null;
            if ($requestedDate === null && $dateWasRequested && ! $this->containsBookingKeyword($message)) {
                $invalidDateError = $this->invalidDateInputError($message)
                    ?? ($intent === 'other' ? 'unrecognized' : null);
            }
            $dateError = match (true) {
                $invalidDateError !== null => $invalidDateError,
                $requestedDate !== null && $requestedDate->lessThan(CarbonImmutable::today()) => 'past',
                $requestedDate !== null && $requestedDate->greaterThan(CarbonImmutable::today()->addYear()) => 'too_far',
                default => null,
            };

            if ($dateError !== null) {
                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    $this->datePrompt($message, $sessionId, $channel, $dateError, language: $state['language']),
                    $state,
                );
            }
        }

        if (filled($state['doctor_id'] ?? null)
            && filled($state['date'] ?? null)
            && filled($state['time'] ?? null)
            && $this->isTimeOutsideHospitalHours((string) $state['time'])
        ) {
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

            $response = $state['language'] === self::LANGUAGE_SWAHILI
                ? ($availableTimes !== []
                    ? sprintf(
                        '%s hapatikani saa %s. Saa zake za kazi ni 8:00 AM hadi 5:00 PM. Nafasi zinazofuata kwa %s tarehe %s ni: %s. Ni muda gani unaokufaa?',
                        $doctorName,
                        $this->formatTimeForResponse($requestedTime),
                        $doctorName,
                        $requestedDate->format('d/m/Y'),
                        implode(', ', $availableTimes),
                    )
                    : sprintf(
                        '%s hapatikani saa %s. Saa zake za kazi ni 8:00 AM hadi 5:00 PM. Ni muda gani unaokufaa?',
                        $doctorName,
                        $this->formatTimeForResponse($requestedTime),
                    ))
                : ($availableTimes !== []
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
                    ));

            return $this->respond($sessionId, $channel, $message, $response, $state);
        }

        foreach (self::FIELD_PROMPTS as $field => $prompt) {
            if ($field === 'doctor_id' && $state[$field]) {
                continue;
            }

            if (! filled($state[$field] ?? null)) {
                $promptMessage = $field === 'service_id'
                ? $this->servicePrompt($hospital, $state['language'])
                : $this->fieldPrompt($field, $state);

                if ($field === 'doctor_id') {
                    $promptMessage = $this->doctorPrompt($state, $hospital);
                } elseif ($field === 'date' && $automaticallySelectedDoctor !== null) {
                    $promptMessage = $this->datePrompt(
                        $message,
                        $sessionId,
                        $channel,
                        doctorName: $automaticallySelectedDoctor->name,
                        language: $state['language'],
                        serviceName: $service->name,
                    );
                } elseif ($field === 'date' && filled($state['doctor_id'] ?? null)) {
                    $selectedDoctor = $hospital->users()->find((int) $state['doctor_id']);
                    if ($selectedDoctor !== null) {
                        $promptMessage = $this->datePrompt(
                            $message,
                            $sessionId,
                            $channel,
                            doctorName: $selectedDoctor->name,
                            language: $state['language'],
                            serviceName: $service->name,
                        );
                    } else {
                        $promptMessage = $this->datePrompt(
                            $message,
                            $sessionId,
                            $channel,
                            language: $state['language'],
                            serviceName: $service->name,
                        );
                    }
                } elseif ($field === 'date') {
                    $promptMessage = $this->datePrompt(
                        $message,
                        $sessionId,
                        $channel,
                        language: $state['language'],
                        serviceName: $service->name,
                    );
                }

                if ($field === 'time' && $this->looksLikeTimeAttempt($message)) {
                    $promptMessage = $state['language'] === self::LANGUAGE_SWAHILI
                        ? 'Samahani, sikuweza kuelewa muda huo. Tafadhali tumia muundo kama 9am, 9:30am, 14:00, au 2pm. Ungependa miadi iwe saa ngapi?'
                        : "I couldn't understand the time you entered. Please use a format like:\n- 9am or 9:30am\n- 14:00 or 14:30\n- 2pm or 2:30pm\n\nWhat time works for you?";
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
        $confirmationPrompt = $state['language'] === self::LANGUAGE_SWAHILI
            ? ' Jibu NDIYO kuthibitisha au HAPANA kughairi.'
            : ' Reply YES to confirm or NO to cancel.';

        return $this->respond(
            $sessionId,
            $channel,
            $message,
            $state['summary'].$confirmationPrompt,
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
                $state['language'] === self::LANGUAGE_SWAHILI
                    ? 'Ombi lako la miadi limetumwa. Hospitali itawasiliana nawe kuthibitisha maelezo.'
                    : 'Your appointment request has been submitted. The hospital will contact you to confirm the details.',
                $state,
                $appointment->id,
            );
        }

        $state['state'] = self::STATE_PAYMENT_PENDING;
        $state['appointment_id'] = $appointment->id;
        if (! $this->persistState(
            $sessionId,
            $channel,
            $message,
            $state['language'] === self::LANGUAGE_SWAHILI
                ? 'Tunaanzisha ombi la malipo ya M-Pesa.'
                : 'Starting the M-Pesa payment request.',
            $state,
            $appointment->id,
        )) {
            return $this->retryResponse($sessionId, $channel, $state['language']);
        }
        $state['state_version']++;

        try {
            $payment = $this->mpesa->initiateStkPush($appointment);
        } catch (Throwable $exception) {
            Log::error('Unable to start the booking chat payment request.', [
                'appointment_id' => $appointment->id,
                'channel' => $channel,
                'exception' => $exception,
            ]);
            $state['state'] = self::STATE_CANCELLED;
            if ($exception instanceof MissingProviderCredentialsException) {
                $response = $state['language'] === self::LANGUAGE_SWAHILI
                    ? "M-Pesa haijasanidiwa kwa hospitali [{$exception->hospitalSlug}]. Msimamizi anahitaji kuweka taarifa za malipo kwenye mipangilio ya hospitali."
                    : $exception->getMessage();
            } else {
                $response = $state['language'] === self::LANGUAGE_SWAHILI
                    ? 'Ombi lako la miadi limehifadhiwa, lakini ombi la M-Pesa halikutumwa. Tafadhali wasiliana na hospitali kwa msaada.'
                    : 'Your appointment request was saved, but the M-Pesa prompt could not be sent. Please contact the hospital for help.';
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

        return $this->respond(
            $sessionId,
            $channel,
            $message,
            sprintf(
                $state['language'] === self::LANGUAGE_SWAHILI
                    ? 'Tumetuma ombi la M-Pesa kwa %s. Miadi yako itasasishwa baada ya malipo kuthibitishwa.'
                    : 'We sent an M-Pesa prompt to %s. Your appointment will be updated when payment is verified.',
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
        $wasWaitingForName = $this->nextMissingField($state) === 'patient_name';
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

        if (! $state['patient_name'] && $wasWaitingForName) {
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
                .'\b(?:(?:ni)?nataka|naomba)\s+(?:kuweka|kupata|kuomba)\s+miadi\b|\bweka\s+miadi\b/iu',
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
        return preg_match(
            '/\b(talk\s+to\s+a\s+human|speak\s+to\s+someone|i\s+need\s+a\s+human|i\s+need\s+someone|call\s+me|need\s+someone\s+to\s+call|real\s+person|health\s+worker|talk\s+to\s+someone|human\s+please|need\s+a\s+human|need\s+someone|speak\s+to\s+a\s+person|(?:nataka|naomba)\s+(?:kuongea|kuzungumza)\s+na\s+(?:mtu|mhudumu|daktari)|niunganishe\s+na\s+(?:mhudumu|daktari)|mhudumu\s+wa\s+afya)\b/iu',
            $message,
        ) === 1;
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
        ?string $language = null,
        ?string $serviceName = null,
    ): string {
        $isSwahili = $language === self::LANGUAGE_SWAHILI
            || ($language === null && $this->isSwahiliDateMessage($message));
        if (! $isSwahili && $language === null) {
            $previousMessage = $this->latestConversation($sessionId, $channel)?->user_message;
            $isSwahili = is_string($previousMessage) && $this->isSwahiliDateMessage($previousMessage);
        }

        if ($isSwahili) {
            $prompt = match ($error) {
                'past' => 'Tarehe hiyo imepita. Tafadhali chagua tarehe ijayo kwa muundo wa DD/MM/YYYY (mfano 15/10/2026), YYYY-MM-DD, au sema "kesho".',
                'too_far' => 'Tarehe hiyo iko mbali sana. Tafadhali chagua tarehe ndani ya miezi 12 kwa muundo wa DD/MM/YYYY (mfano 15/10/2026), YYYY-MM-DD, au sema "kesho".',
                'invalid' => "Samahani, sikuweza kuelewa tarehe. Tafadhali ingiza tarehe kama DD/MM/YYYY (mfano 15/10/2026), YYYY-MM-DD, au sema 'kesho'.",
                'ambiguous' => "Samahani, sijapata tarehe uliyomaanisha. Tafadhali tumia DD/MM/YYYY (mfano 15/10/2026), YYYY-MM-DD, au sema 'kesho'.",
                'unrecognized' => "Samahani, sikuweza kuelewa. Ungependa miadi tarehe gani? Tumia DD/MM/YYYY (mfano 15/10/2026), YYYY-MM-DD, au sema 'kesho'.",
                default => "Ungependa miadi tarehe gani? Tafadhali ingiza DD/MM/YYYY (mfano 15/10/2026), YYYY-MM-DD, au sema 'kesho'.",
            };

            if ($error === null && $serviceName !== null) {
                $serviceConfirmation = $doctorName !== null
                    ? $doctorName.' anapatikana kwa huduma ya '.$serviceName.'.'
                    : 'Naweza kukusaidia kuweka miadi ya '.$serviceName.'.';

                return $serviceConfirmation.' '.$prompt;
            }

            return $doctorName !== null && $error === null
                ? $doctorName.' ana huduma kwa miadi hiyo. '.$prompt
                : $prompt;
        }

        $prompt = match ($error) {
            'past' => 'That date is in the past. Please choose a future date as DD/MM/YYYY (e.g. 15/10/2026), YYYY-MM-DD, or say "tomorrow".',
            'too_far' => 'That date is too far ahead. Please choose a date within the next 12 months as DD/MM/YYYY (e.g. 15/10/2026), YYYY-MM-DD, or say "tomorrow".',
            'invalid' => sprintf(
                "I couldn't understand '%s'. Please enter a date as DD/MM/YYYY (e.g. 15/10/2026), YYYY-MM-DD, or say 'tomorrow'.",
                $message,
            ),
            'ambiguous' => "Sorry, I didn't catch that date. Please use DD/MM/YYYY (e.g. 15/10/2026), YYYY-MM-DD, or say 'tomorrow'.",
            'unrecognized' => "Sorry, I didn't catch that. What date would you prefer? Please use DD/MM/YYYY (e.g. 15/10/2026), YYYY-MM-DD, or say 'tomorrow'.",
            default => 'What date would you prefer? Use DD/MM/YYYY (e.g. 15/10/2026), YYYY-MM-DD, or say "tomorrow".',
        };

        if ($error === null && $serviceName !== null) {
            $serviceConfirmation = $doctorName !== null
                ? $doctorName.' is available for '.$serviceName.'.'
                : 'I can help you book '.$serviceName.'.';

            return $serviceConfirmation.' '.$prompt;
        }

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

    /**
     * @param  array<string, mixed>  $state
     */
    private function resolveReplyLanguage(string $message, array $state): string
    {
        $sessionLanguage = in_array($state['language'] ?? null, [
            self::LANGUAGE_ENGLISH,
            self::LANGUAGE_SWAHILI,
        ], true)
            ? $state['language']
            : self::LANGUAGE_ENGLISH;
        $normalizedMessage = mb_strtolower(trim($message));

        if (in_array($normalizedMessage, ['yes', 'no', 'ok', 'sawa', 'y', 'n'], true)) {
            return $sessionLanguage;
        }

        $messageLanguage = $this->languageDetector->detectLanguage($message);
        if ($messageLanguage === self::LANGUAGE_SWAHILI
            && ! $this->languageDetector->hasConfidentLanguageSignal($message)
        ) {
            return $messageLanguage;
        }

        if ($this->languageDetector->hasConfidentLanguageSignal($message)) {
            return $messageLanguage;
        }

        return $sessionLanguage;
    }

    private function extractTime(string $message): ?string
    {
        $normalized = $this->normalizeSwahiliTime(trim($message));
        if (preg_match('/^\d{1,2}[\/-]\d{1,2}\s*[?.!]*$/u', $normalized) === 1) {
            return null;
        }

        $normalized = preg_replace(
            '/\b(?:\d{4}-\d{1,2}-\d{1,2}|\d{1,2}[\/-]\d{1,2}[\/-]\d{4})\b/u',
            ' ',
            $normalized,
        ) ?? $normalized;
        $normalized = preg_replace(
            '/\b(?:\d{1,2}(?:st|nd|rd|th)?\s+'.DateParser::ENGLISH_MONTH_PATTERN.'\s+\d{4}|(?:(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)\s*,?\s*)?'.DateParser::ENGLISH_MONTH_PATTERN.'\s+\d{1,2}(?:st|nd|rd|th)?(?:,?\s+\d{4})?)\b/iu',
            ' ',
            $normalized,
        ) ?? $normalized;

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

    private function normalizeSwahiliTime(string $input): string
    {
        return preg_replace_callback(
            '/(?<![\pL\pN])(?:saa\s+)?(0?[1-9]|1[0-2])(?::([0-5]\d))?\s*(asubuhi|mchana|jioni|usiku)\b/iu',
            static function (array $matches): string {
                $hour = (int) $matches[1];
                $minute = (int) ($matches[2] ?? 0);

                $hour = match (mb_strtolower($matches[3])) {
                    'asubuhi' => $hour === 12 ? 0 : $hour,
                    'mchana' => $hour === 12 ? 12 : $hour + 12,
                    'jioni' => $hour === 12 ? 0 : $hour + 12,
                    'usiku' => $hour === 12 ? 0 : ($hour <= 4 ? $hour : $hour + 12),
                };

                return sprintf('%02d:%02d', $hour, $minute);
            },
            $input,
        ) ?? $input;
    }

    private function looksLikeTimeAttempt(string $message): bool
    {
        return preg_match('/\b(?:noon|midnight|quarter\s+(?:past|to)|half\s+past|[0-9]{1,2}\s*(?:[.:][0-9]{2})?\s*(?:am|pm)|[0-9]{1,2}\s*o\s*[\'’]?clock)\b/i', $message) === 1;
    }

    private function invalidDateInputError(string $message): ?string
    {
        if (preg_match('/^\s*\d{1,2}[\/-]\d{1,2}\s*[?.!]*\s*$/u', $message) === 1) {
            return 'ambiguous';
        }

        $monthPattern = DateParser::ENGLISH_MONTH_PATTERN;
        $hasDateStructure = preg_match(
            '/\b(?:\d{1,2}[\/-]\d{1,2}[\/-]\d{4}|\d{4}-\d{1,2}-\d{1,2}|'
                .$monthPattern.'\s+\d{1,2}(?:,?\s+\d{4})?|'
                .'\d{1,2}\s+'.$monthPattern.'(?:\s+\d{4})?)\b/iu',
            $message,
        ) === 1;

        return $hasDateStructure ? 'invalid' : null;
    }

    private function isLowSignalInput(string $message): bool
    {
        return preg_match('/^(?:blah|asdf|qwerty|zxcv|xyz)+[.!?]*$/iu', trim($message)) === 1;
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

        if (preg_match(
            '/\b(?:jina langu ni|ninaitwa)\s+([\pL][\pL\'-]*(?:\s+[\pL][\pL\'-]*){0,3}?)(?=\s+(?:na|simu|namba|kesho|leo)\b|[,.;]|$)/iu',
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
        if ($state !== self::STATE_IDLE && preg_match('/^\s*miadi\s*[?!.]*\s*$/iu', $normalized) === 1) {
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
        if (
            (
                preg_match('/\b(dentist|dentists|doctor|doctors|doc|daktari|madaktari|specialist|specialists|radiologist|physiotherapist|obstetrician|gynecologist|gynaecologist)\b/iu', $normalized)
                && (
                    preg_match('/\b(do you have|is there|are there|available|availability|which|who|can i see|mna|yupo|wapo|yupi|yuko|gimme|give me)\b/iu', $normalized)
                    || preg_match('/^\s*(?:a\s+)?(?:dentists?|doctors?|docs?|daktari|madaktari)\s*[?!.]*\s*$/iu', $normalized)
                )
            )
            || preg_match('/\b(?:who|which)\s+(?:is\s+)?available\s+(?:today|tomorrow|on\s+[\pL\d-]+)/iu', $message) === 1
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
            && preg_match('/^(?:hi|hello|hey|habari(?:\s+yako|\s+za\s+(?:asubuhi|mchana|jioni))?|hujambo|jambo|mambo(?:\s+vipi)?|vipi|niaje|sasa|shikamoo|marahaba|salama|poa|freshi|mzuri|nzuri|yo|sup|what\s+s\s+up|whats\s+up|good morning|good afternoon|good evening)[.!?,\s]*$/u', $normalized)
        ) {
            return 'greeting';
        }
        if (preg_match('/\b(help|support|what can you do|how does this work|nisaidie|msaada|saidia)\b/iu', $normalized)) {
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
                    $state['language'] === self::LANGUAGE_SWAHILI
                        ? 'Sikupata miadi ijayo ya kubadilisha. Maelezo ya miadi unayojaza hayajabadilika.'
                        : 'I could not find an upcoming appointment to change. Your in-progress booking details are unchanged.',
                    $state,
                );
            }

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                $state['language'] === self::LANGUAGE_SWAHILI
                    ? 'Sikupata miadi ijayo inayohusishwa na mazungumzo haya ambayo inaweza kubadilishwa. Tafadhali ingia kwenye dashibodi ya wagonjwa kusimamia miadi.'
                    : 'I could not find an upcoming appointment linked to this chat that can be changed. Please sign in to manage appointments in your patient dashboard.',
                $this->emptyState(
                    language: $state['language'],
                    stateVersion: $state['state_version'],
                ),
            );
        }

        $state = $this->emptyState(
            self::STATE_LIFECYCLE_SELECT,
            $state['language'],
            $state['state_version'],
        );
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

        $isSwahili = $state['language'] === self::LANGUAGE_SWAHILI;
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
                    $state['language'] === self::LANGUAGE_SWAHILI
                        ? 'Jibu kwa nambari iliyo karibu na miadi unayotaka kubadilisha.'
                        : 'Reply with the number beside the appointment you want to change.',
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
                    $state['language'] === self::LANGUAGE_SWAHILI
                        ? 'Chaguo hilo si sahihi. Jibu kwa mojawapo ya nambari za miadi zilizoonyeshwa.'
                        : 'That selection is not valid. Reply with one of the appointment numbers shown.',
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
                    $state['language'] === self::LANGUAGE_SWAHILI
                        ? 'Miadi hiyo haiwezi kubadilishwa kulingana na sera ya sasa ya hospitali.'
                        : 'That appointment cannot be changed under the current hospital policy.',
                    $this->emptyState(
                        language: $state['language'],
                        stateVersion: $state['state_version'],
                    ),
                );
            }

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                $state['language'] === self::LANGUAGE_SWAHILI
                    ? 'Jibu kwa nambari ya miadi unayotaka kubadilisha.'
                    : 'Reply with the number of the appointment you want to change.',
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
                $state['language'] === self::LANGUAGE_SWAHILI
                    ? 'Sikuweza kuthibitisha miadi hiyo kwa mazungumzo haya. Tafadhali ingia kwenye dashibodi yako ya wagonjwa kuisimamia.'
                    : 'I could not verify that appointment for this chat. Please sign in to manage it in your patient dashboard.',
                $this->emptyState(
                    language: $state['language'],
                    stateVersion: $state['state_version'],
                ),
            );
        }

        $appointment = AppointmentRequest::query()
            ->where('hospital_id', $hospital->id)
            ->findOrFail($appointmentId);
        $intent = $this->extractIntent($message, $state['state']);
        $isSwahili = $state['language'] === self::LANGUAGE_SWAHILI;

        if ($state['state'] === self::STATE_LIFECYCLE_CANCEL_CONFIRM) {
            if (preg_match('/^\s*(?:no|n|nope|hapana)\b/iu', $message) === 1) {
                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    $isSwahili ? 'Sawa, miadi yako haijaghairiwa.' : 'Okay, your appointment has not been cancelled.',
                    $this->emptyState(
                        language: $state['language'],
                        stateVersion: $state['state_version'],
                    ),
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
                    $this->emptyState(
                        language: $state['language'],
                        stateVersion: $state['state_version'],
                    ),
                );
            }

            $response = $isSwahili
                ? 'Miadi yako imeghairiwa.'
                : 'Your appointment has been cancelled.';
            if ($result->forfeitedAmount > 0) {
                $response .= $isSwahili
                    ? ' KSh '.number_format($result->forfeitedAmount, 2).' zimezuiliwa kulingana na sera ya kughairi ya hospitali.'
                    : ' KSh '.number_format($result->forfeitedAmount, 2).' was retained under the hospital cancellation policy.';
            } elseif ($result->paymentOutcome === 'refund_pending') {
                $response .= $isSwahili
                    ? ' Marejesho yoyote yanayosubiri yanahitaji ukaguzi wa malipo kwa mikono.'
                    : ' Any refundable balance is pending manual payment review.';
            }

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                $response,
                $this->emptyState(
                    language: $state['language'],
                    stateVersion: $state['state_version'],
                ),
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
                    $isSwahili
                        ? 'Tafadhali chagua tarehe halali ya baadaye ndani ya miezi 12 ijayo, kwa mfano 15/10/2026.'
                        : 'Please choose a valid future date within the next 12 months, such as 15/10/2026.',
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
                    $isSwahili
                        ? 'Tafadhali weka muda halali, kama 10:30 AM.'
                        : 'Please enter a valid time, such as 10:30 AM.',
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
                    $isSwahili
                        ? 'Muda huo haupatikani kwa daktari wako wa sasa. Tafadhali chagua muda mwingine au anza tena kwa tarehe tofauti.'
                        : 'That time is not available with your current doctor. Please choose another time or start again with a different date.',
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
                    $isSwahili
                        ? 'Tafadhali thibitisha kuhamisha miadi #%d hadi %s saa %s. Jibu ndiyo au hapana.'
                        : 'Please confirm moving appointment #%d to %s at %s. Reply yes or no.',
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
                    $isSwahili ? 'Sawa, miadi yako haijabadilishwa.' : 'Okay, your appointment has not been changed.',
                    $this->emptyState(
                        language: $state['language'],
                        stateVersion: $state['state_version'],
                    ),
                );
            }
            if (preg_match('/^\s*(?:yes|y|yeah|yep|ok|confirm|book it|proceed|ndiyo|ndio|sawa)\b/iu', $message) !== 1) {
                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    $isSwahili
                        ? 'Tafadhali jibu ndiyo au hapana kuthibitisha muda mpya wa miadi.'
                        : 'Please reply yes or no to confirm the new appointment time.',
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
                    $this->emptyState(
                        language: $state['language'],
                        stateVersion: $state['state_version'],
                    ),
                );
            }

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                $isSwahili ? 'Miadi yako imebadilishwa.' : 'Your appointment has been rescheduled.',
                $this->emptyState(
                    language: $state['language'],
                    stateVersion: $state['state_version'],
                ),
                $appointmentId,
            );
        }

        return $this->respond(
            $sessionId,
            $channel,
            $message,
            $isSwahili
                ? 'Sikuweza kuendelea na ombi hilo la miadi. Tafadhali anza tena.'
                : 'I could not continue that appointment request. Please start again.',
            $this->emptyState(
                language: $state['language'],
                stateVersion: $state['state_version'],
            ),
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
                $state['language'] === self::LANGUAGE_SWAHILI
                    ? 'Sikuweza kuthibitisha miadi hiyo kwa mazungumzo haya.'
                    : 'I could not verify that appointment for this chat.',
                $this->emptyState(
                    language: $state['language'],
                    stateVersion: $state['state_version'],
                ),
            );
        }

        $state['lifecycle_appointment_id'] = $appointment->id;
        $state['appointment_candidates'] = [];
        if ($state['lifecycle_action'] === 'cancel') {
            $state['state'] = self::STATE_LIFECYCLE_CANCEL_CONFIRM;
            $response = sprintf(
                $state['language'] === self::LANGUAGE_SWAHILI
                    ? 'Ungependa kughairi miadi #%d ya tarehe %s saa %s? Jibu ndiyo au hapana.'
                    : 'Cancel appointment #%d on %s at %s? Reply yes or no.',
                $appointment->id,
                $appointment->preferred_date?->format('d/m/Y'),
                $appointment->slot_start_time ? CarbonImmutable::parse($appointment->slot_start_time)->format('g:i A') : 'the scheduled time',
            );
        } else {
            $state['state'] = self::STATE_LIFECYCLE_RESCHEDULE_DATE;
            $response = $state['language'] === self::LANGUAGE_SWAHILI
                ? 'Ni tarehe gani mpya ungependa? Tumia DD/MM/YYYY (mfano 15/10/2026), YYYY-MM-DD, au sema "kesho".'
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
    private function emptyState(
        string $status = self::STATE_IDLE,
        string $language = self::LANGUAGE_ENGLISH,
        int $stateVersion = 0,
    ): array {
        return [
            'state' => $status,
            'language' => $language,
            'state_version' => $stateVersion,
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
            'unrecognized_message_count' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function bookingRecoveryPrompt(
        array $state,
        Hospital $hospital,
        string $message,
        string $sessionId,
        string $channel,
    ): string {
        $field = $this->nextMissingField($state);

        if ($field === 'service_id') {
            return ($state['language'] === self::LANGUAGE_SWAHILI
                ? 'Bado nahitaji kujua huduma unayotaka. '
                : 'I still need to know which service you want. ').$this->servicePrompt($hospital, $state['language']);
        }

        if ($field === 'date') {
            return ($state['language'] === self::LANGUAGE_SWAHILI
                ? 'Bado nahitaji tarehe ili kuendelea. '
                : 'I still need a date to continue. ').$this->datePrompt(
                    $message,
                    $sessionId,
                    $channel,
                    $this->invalidDateInputError($message) ?? 'unrecognized',
                    language: $state['language'],
                );
        }

        if ($field !== null) {
            return ($state['language'] === self::LANGUAGE_SWAHILI
                ? 'Bado nahitaji taarifa moja ya miadi. '
                : 'I still need one booking detail. ').$this->fieldPrompt($field, $state);
        }

        return 'I still need one booking detail to continue.';
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function recoveryMenuPrompt(array $state): string
    {
        return $state['language'] === self::LANGUAGE_SWAHILI
            ? 'Tuanze upya. Ungependa kufanya nini? Jibu "miadi" kuweka miadi, uliza kuhusu hospitali, au sema "binadamu" kuzungumza na mhudumu.'
            : 'Let me start over. What would you like to do? Reply book to make an appointment, ask about services or hospital information, or say human to speak with someone.';
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
        ?Closure $beforePersist = null,
    ): ChatResponse {
        $state['language'] = in_array($state['language'] ?? null, [
            self::LANGUAGE_ENGLISH,
            self::LANGUAGE_SWAHILI,
        ], true)
            ? $state['language']
            : self::LANGUAGE_ENGLISH;
        if (! $this->persistState(
            $sessionId,
            $channel,
            $userMessage,
            $response,
            $state,
            $appointmentId,
            $escalated,
            $beforePersist,
        )) {
            return $this->retryResponse($sessionId, $channel, $state['language']);
        }
        $state['state_version']++;

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
        ?Closure $beforePersist = null,
    ): bool {
        $state = $this->validatedState($state, $sessionId, $channel);
        $hospital = hospital();
        if (! $hospital instanceof Hospital) {
            throw new RuntimeException('A hospital context is required to persist booking conversation state.');
        }

        return DB::transaction(function () use (
            $hospital,
            $sessionId,
            $channel,
            $userMessage,
            $response,
            $state,
            $escalated,
            $beforePersist,
        ): bool {
            $latestConversation = $this->latestStateConversation($sessionId, $channel, true);
            if (! $latestConversation) {
                Hospital::query()->whereKey($hospital->id)->lockForUpdate()->firstOrFail();
                $latestConversation = $this->latestStateConversation($sessionId, $channel, true);
            }
            $latestVersion = (int) data_get($latestConversation?->chat_state, 'state_version', 0);

            if ($latestVersion !== (int) $state['state_version']) {
                return false;
            }

            $beforePersist?->__invoke();
            $state['state_version']++;
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

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function persistStateOnConversation(
        Conversation $conversation,
        array $state,
        bool $escalated,
    ): bool {
        $hospital = hospital();
        if (! $hospital instanceof Hospital) {
            throw new RuntimeException('A hospital context is required to persist booking conversation state.');
        }

        $sessionId = (string) $conversation->session_id;
        $channel = (string) $conversation->channel;
        $state = $this->validatedState($state, $sessionId, $channel);

        return DB::transaction(function () use (
            $conversation,
            $hospital,
            $sessionId,
            $channel,
            $state,
            $escalated,
        ): bool {
            $latestState = $this->latestStateConversation($sessionId, $channel, true);
            if (! $latestState) {
                Hospital::query()->whereKey($hospital->id)->lockForUpdate()->firstOrFail();
                $latestState = $this->latestStateConversation($sessionId, $channel, true);
            }

            $latestConversation = Conversation::query()
                ->where('session_id', $sessionId)
                ->where('channel', $channel)
                ->where(function ($query): void {
                    $query->whereNull('chat_state->system_event')
                        ->orWhere('chat_state->system_event', '!=', 'payment_receipt');
                })
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ((int) $latestConversation?->getKey() !== (int) $conversation->getKey()
                || (int) data_get($latestState?->chat_state, 'state_version', 0) !== (int) $state['state_version']
            ) {
                return false;
            }

            $state['state_version']++;
            $conversation->forceFill([
                'chat_state' => $state,
                'chat_state_updated_at' => now(),
                'escalated' => $escalated,
            ])->save();

            return true;
        });
    }

    private function retryResponse(string $sessionId, string $channel, string $language): ChatResponse
    {
        $conversation = $this->latestStateConversation($sessionId, $channel);
        $state = $conversation && is_array($conversation->chat_state)
            ? $this->validatedState($conversation->chat_state, $sessionId, $channel)
            : $this->emptyState();
        $language = in_array($language, [self::LANGUAGE_ENGLISH, self::LANGUAGE_SWAHILI], true)
            ? $language
            : self::LANGUAGE_ENGLISH;

        return new ChatResponse(
            $language === self::LANGUAGE_SWAHILI
                ? 'Mazungumzo yamesasishwa kwenye ombi jingine. Tafadhali jaribu tena.'
                : 'This chat was updated by another request. Please try again.',
            $state['state'],
            $state['appointment_id'] ?? null,
            collectedFields: $state,
            source: 'retry',
        );
    }

    private function sessionLockKey(Hospital $hospital, string $channel, string $sessionId): string
    {
        return 'booking-chat-state:'.hash('sha256', implode(':', [
            $hospital->id,
            $channel,
            $sessionId,
        ]));
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
        $result = $this->assistant->processMessageWithPatientPhone(
            $message,
            $sessionId,
            $channel,
            $patientPhone,
            $state['language'],
        );
        $response = (string) ($result['response'] ?? (
            $state['language'] === self::LANGUAGE_SWAHILI
                ? 'Sikuweza kuchakata ujumbe huo. Tafadhali jaribu tena.'
                : 'I could not process that message. Please try again.'
        ));
        $conversation = Conversation::query()
            ->where('session_id', $sessionId)
            ->where('channel', $channel)
            ->where('user_message', $message)
            ->where('id', '>', $previousConversationId)
            ->latest('id')
            ->first();

        if ($conversation) {
            if (! $this->persistStateOnConversation(
                $conversation,
                $state,
                (bool) ($result['escalated'] ?? false),
            )) {
                return $this->retryResponse($sessionId, $channel, $state['language']);
            }
        } else {
            if (! $this->persistState(
                $sessionId,
                $channel,
                $message,
                $response,
                $state,
                escalated: (bool) ($result['escalated'] ?? false),
            )) {
                return $this->retryResponse($sessionId, $channel, $state['language']);
            }
        }
        $state['state_version']++;

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
            return $this->servicePrompt($hospital, $state['language']);
        }

        if ($field === 'date') {
            $serviceName = $this->serviceById((int) $state['service_id'], $hospital)->name;

            return $this->datePrompt(
                $message,
                $sessionId,
                $channel,
                language: $state['language'],
                serviceName: $serviceName,
            );
        }

        if ($field === 'doctor_id') {
            return $this->doctorPrompt($state, $hospital);
        }

        return $field !== null
            ? $this->fieldPrompt($field, $state)
            : 'Please provide the missing booking detail so I can continue.';
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function fieldPrompt(string $field, array $state): string
    {
        $prompts = ($state['language'] ?? self::LANGUAGE_ENGLISH) === self::LANGUAGE_SWAHILI
            ? self::SWAHILI_FIELD_PROMPTS
            : self::FIELD_PROMPTS;

        return $prompts[$field] ?? 'Please provide the missing booking detail so I can continue.';
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function doctorPrompt(array $state, Hospital $hospital): string
    {
        $service = $this->serviceById((int) $state['service_id'], $hospital);
        $doctors = $this->eligibleDoctors($hospital, $service);

        return $state['language'] === self::LANGUAGE_SWAHILI
            ? 'Ungependa kumwona daktari gani? Madaktari wanaopatikana: '.$doctors->pluck('name')->implode(', ').'.'
            : 'Which doctor would you prefer? Available doctors: '.$doctors->pluck('name')->implode(', ').'.';
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
        $isSwahili = $state['language'] === self::LANGUAGE_SWAHILI;
        $phonePrompt = $isSwahili
            ? sprintf('Nimeandika ombi lako. Tafadhali shiriki namba yako ya simu — ni namba gani bora kwa timu ya %s kukufikia?', $hospital->name)
            : sprintf(
                "I've noted your request. Please share your phone number — what's the best phone number for a member of the %s team to reach you on?",
                $hospital->name,
            );
        $handoffConfirmation = $isSwahili
            ? sprintf(
                'Ombi lako limetumwa kwa timu ya %s. Mtu atawasiliana nawe hivi karibuni.',
                $hospital->name,
            )
            : sprintf("I've passed your request to the %s team. Someone will reach out shortly.", $hospital->name);

        if ($state['escalation_pending'] ?? false) {
            if ($phone !== null) {
                $pendingMessage = (string) ($state['pending_escalation_message'] ?? $message);
                $state['patient_phone'] ??= $phone;
                $state['escalation_pending'] = false;
                $state['pending_escalation_message'] = null;
                $state['unrecognized_message_count'] = 0;

                return $this->respond(
                    $sessionId,
                    $channel,
                    $message,
                    $isSwahili
                        ? $handoffConfirmation."\n\nTaarifa za miadi yako zimehifadhiwa — sema \"endelea na miadi\" ukiwa tayari kumalizia."
                        : $handoffConfirmation."\n\nYour booking details are saved — say \"continue booking\" whenever you're ready to finish.",
                    $state,
                    source: 'human_escalation',
                    escalated: true,
                    beforePersist: fn () => app(EscalationService::class)->createEscalation(
                        $sessionId,
                        $pendingMessage,
                        $handoffConfirmation,
                        $phone,
                    ),
                );
            }

            $state['state'] = self::STATE_COLLECTING;

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                $phonePrompt,
                $state,
                source: 'human_escalation_contact_details',
            );
        }

        if ($phone !== null) {
            $state['patient_phone'] ??= $phone;
            $state['unrecognized_message_count'] = 0;

            return $this->respond(
                $sessionId,
                $channel,
                $message,
                $isSwahili
                    ? 'Asante. Ombi lako limepelekwa kwa timu ya hospitali. Mhudumu wa afya atawasiliana nawe hivi karibuni.'
                    : sprintf(
                        "Thank you. Your request has been passed to the %s team. I'm connecting you to a health worker now; someone will reach out shortly.",
                        $hospital->name,
                    ),
                $state,
                source: 'human_escalation',
                escalated: true,
                beforePersist: fn () => app(EscalationService::class)->createEscalation(
                    $sessionId,
                    $message,
                    $phonePrompt,
                    $phone,
                ),
            );
        }

        $state['state'] = self::STATE_COLLECTING;
        $state['escalation_pending'] = true;
        $state['pending_escalation_message'] = $message;
        $state['unrecognized_message_count'] = 0;

        return $this->respond(
            $sessionId,
            $channel,
            $message,
            $phonePrompt,
            $state,
            source: 'human_escalation_contact_details',
        );
    }

    private function servicePrompt(Hospital $hospital, string $language = self::LANGUAGE_ENGLISH): string
    {
        $names = $hospital->services()->active()->orderBy('name')->pluck('name');
        if ($names->isEmpty()) {
            return $language === self::LANGUAGE_SWAHILI
                ? 'Hakuna huduma zinazopatikana kwa sasa. Tafadhali wasiliana na hospitali.'
                : 'There are no services available to book right now. Please contact the hospital.';
        }

        return $language === self::LANGUAGE_SWAHILI
            ? sprintf("Sawa, naweza kukusaidia kupanga miadi katika %s.\n\nUnahitaji huduma gani?", $hospital->name)
            : sprintf(
                "Sure, I can help you book an appointment at %s.\n\nWhat service do you need?",
                $hospital->name,
            );
    }

    private function serviceUnavailablePrompt(Hospital $hospital, string $language = self::LANGUAGE_ENGLISH): string
    {
        $names = $hospital->services()->active()->orderBy('name')->pluck('name');
        if ($names->isEmpty()) {
            return $language === self::LANGUAGE_SWAHILI
                ? 'Hakuna huduma zinazopatikana kwa sasa. Tafadhali wasiliana na hospitali.'
                : 'There are no services available to book right now. Please contact the hospital.';
        }

        return $language === self::LANGUAGE_SWAHILI
            ? sprintf(
                'Sikuweza kutambua huduma hiyo. Huduma zinazopatikana katika %s: %s. Unahitaji huduma gani?',
                $hospital->name,
                $names->implode(', '),
            )
            : sprintf(
                'I could not match that service. Available services at %s: %s. What service do you need?',
                $hospital->name,
                $names->implode(', '),
            );
    }

    private function bookingSummary(array $state, Hospital $hospital): string
    {
        $service = $this->serviceById((int) $state['service_id'], $hospital);
        $doctor = $this->eligibleDoctors($hospital, $service)->firstWhere('id', (int) $state['doctor_id']);
        $paymentAmount = (float) ($service->price ?? 0);
        $paymentAmountSummary = $hospital->hasFeature('mpesa') && $paymentAmount > 0
            ? sprintf(
                $state['language'] === self::LANGUAGE_SWAHILI
                    ? ' Kiasi cha M-Pesa ni KSh %s.'
                    : ' M-Pesa amount: KSh %s.',
                number_format($paymentAmount, 2, '.', ','),
            )
            : '';
        $date = $state['language'] === self::LANGUAGE_SWAHILI
            ? CarbonImmutable::parse((string) $state['date'])->format('d/m/Y')
            : CarbonImmutable::parse((string) $state['date'])->toFormattedDateString();

        return $state['language'] === self::LANGUAGE_SWAHILI
            ? sprintf(
                'Tafadhali thibitisha: %s kwa miadi na %s tarehe %s saa %s kwa %s, simu %s.',
                $service->name,
                $doctor?->name ?? 'daktari uliyemchagua',
                $date,
                $state['time'],
                $state['patient_name'],
                $state['patient_phone'],
            ).$paymentAmountSummary
            : sprintf(
                'Please confirm: %s with %s on %s at %s for %s, phone %s.',
                $service->name,
                $doctor?->name ?? 'your selected doctor',
                $date,
                $state['time'],
                $state['patient_name'],
                $state['patient_phone'],
            ).$paymentAmountSummary;
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
                    $state['language'] === self::LANGUAGE_SWAHILI
                        ? 'Muda huo haupatikani. Nafasi za karibu zaidi kwa %s tarehe %s ni: %s. Ni ipi inayokufaa?'
                        : "That time isn't available. The nearest available times with %s on %s are: %s. Which works for you?",
                    $doctor->name,
                    $state['language'] === self::LANGUAGE_SWAHILI
                        ? $requestedDate->format('d/m/Y')
                        : $requestedDate->format('F j'),
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
        $isSwahili = $state['language'] === self::LANGUAGE_SWAHILI;

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
            ->where(function ($query): void {
                $query->whereNull('chat_state->system_event')
                    ->orWhere('chat_state->system_event', '!=', 'payment_receipt');
            })
            ->latest('id')
            ->first();
    }

    private function latestStateConversation(
        string $sessionId,
        string $channel,
        bool $lockForUpdate = false,
    ): ?Conversation {
        $query = Conversation::query()
            ->where('session_id', $sessionId)
            ->where('channel', $channel)
            ->whereNotNull('chat_state')
            ->where(function ($query): void {
                $query->whereNull('chat_state->system_event')
                    ->orWhere('chat_state->system_event', '!=', 'payment_receipt');
            })
            ->latest('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function persistedState(string $channelId, string $channel): array
    {
        $sessionId = $this->sessionId($channelId, $channel);
        $conversation = $this->latestStateConversation($sessionId, $channel);

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
            'language',
            'state_version',
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
            'unrecognized_message_count',
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
            && (! array_key_exists('state_version', $state)
                || (is_int($state['state_version']) && $state['state_version'] >= 0))
            && in_array($state['language'] ?? self::LANGUAGE_ENGLISH, [
                self::LANGUAGE_ENGLISH,
                self::LANGUAGE_SWAHILI,
            ], true)
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
            && (! array_key_exists('unrecognized_message_count', $state)
                || (is_int($state['unrecognized_message_count'])
                    && $state['unrecognized_message_count'] >= 0
                    && $state['unrecognized_message_count'] <= 3))
            && $this->nullableStringFieldIsValid($state, 'patient_name', 255)
            && $this->nullableStringFieldIsValid($state, 'patient_phone', 32)
            && $this->nullableStringFieldIsValid($state, 'patient_email', 254)
            && $this->nullableStringFieldIsValid($state, 'pending_escalation_message', 2000)
            && $this->nullableStringFieldIsValid($state, 'summary', 2000);

        if ($isValid) {
            $state['language'] ??= self::LANGUAGE_ENGLISH;
            $state['state_version'] ??= 0;

            return $state;
        }

        Log::error('Invalid booking chat state was reset.', [
            'channel' => $channel,
            'session_hash' => hash('sha256', $this->sessionId($channelId, $channel)),
            'unknown_keys' => array_values(array_diff(array_keys($state), $allowedKeys)),
        ]);

        return $this->emptyState(
            stateVersion: is_int($state['state_version'] ?? null) && $state['state_version'] >= 0
                ? $state['state_version']
                : 0,
        );
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
