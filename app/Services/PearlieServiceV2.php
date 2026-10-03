<?php

namespace App\Services;

use App\Models\AppointmentRequest;
use App\Models\Conversation;
use App\Models\Service;
use App\Models\User;
use App\Support\DateParser;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PearlieServiceV2
{
    protected KnowledgeBaseService $knowledgeBase;

    protected EscalationService $escalationService;

    protected DoctorAvailabilityService $availabilityService;

    protected ?string $apiKey;

    protected string $model;

    protected string $baseUrl;

    public function __construct(
        KnowledgeBaseService $knowledgeBase,
        EscalationService $escalationService,
        DoctorAvailabilityService $availabilityService,
    ) {
        $this->knowledgeBase = $knowledgeBase;
        $this->escalationService = $escalationService;
        $this->availabilityService = $availabilityService;
        $this->apiKey = config('services.groq.api_key');
        $this->model = config('services.groq.model', 'groq/compound');
        $this->baseUrl = config('services.groq.base_url', 'https://api.groq.com/openai/v1');
    }

    public function processMessage(string $message, string $sessionId, string $channel = 'web'): array
    {
        $phoneCapture = $this->continueEscalationPhoneCapture($message, $sessionId, $channel);
        if ($phoneCapture !== null) {
            return $phoneCapture;
        }

        $handoff = $this->escalationService->handleActivePatientMessage($sessionId, $message, $channel);
        if ($handoff !== null) {
            return $handoff;
        }

        $handoff = $this->handleEscalationIntent($message, $sessionId, $channel);
        if ($handoff !== null) {
            return $handoff;
        }

        $appointmentData = $this->detectAppointment($message);
        if ($appointmentData !== false) {
            $isAvailabilityQuestion = preg_match('/\b(availability|available|free slots?)\b/i', $message)
                && ! preg_match('/\b(book|schedule|reserve)\b/i', $message);

            if ($isAvailabilityQuestion) {
                return $this->respondWithAvailableSlots($message, $sessionId, $channel);
            }

            return $this->recordAppointment($appointmentData, $message, $sessionId, $channel);
        }

        // 1) Knowledge base lookup
        try {
            $localAnswer = $this->knowledgeBase->search($message);
        } catch (Exception $e) {
            Log::error('KnowledgeBase search failed: '.$e->getMessage());
            $localAnswer = null;
        }

        if ($localAnswer) {
            $confidence = 0.95;

            Conversation::create([
                'session_id' => $sessionId,
                'user_message' => $message,
                'ai_response' => $localAnswer,
                'confidence_score' => $confidence,
                'channel' => $channel,
            ]);

            return [
                'response' => $localAnswer,
                'confidence' => $confidence,
                'source' => 'knowledge_base',
                'escalated' => false,
                'appointment_id' => null,
            ];
        }

        if ($this->isWeatherQuestion($message)) {
            $location = hospital()?->address;
            $responseText = $this->isSwahili($message)
                ? 'Samahani, sina taarifa za hali ya hewa za moja kwa moja kwa sasa. Naweza kukusaidia kuhusu taarifa za '.pearlie_config('hospital.name').', huduma, au miadi.'
                : 'I do not have live weather data right now.'
                    .($location ? ' For current weather near '.$location.', please check your preferred weather app or website.' : '')
                    .' I can still help with '.pearlie_config('hospital.name').' information, services, or appointments.';

            Conversation::create([
                'session_id' => $sessionId,
                'user_message' => $message,
                'ai_response' => $responseText,
                'confidence_score' => 0.9,
                'channel' => $channel,
            ]);

            return [
                'response' => $responseText,
                'confidence' => 0.9,
                'source' => 'service_notice',
                'escalated' => false,
                'appointment_id' => null,
            ];
        }

        // 2) Build conversation history
        $maxHistory = (int) config('pearlie.max_history', 10);
        $history = Conversation::where('session_id', $sessionId)
            ->orderBy('id', 'desc')
            ->limit($maxHistory)
            ->get()
            ->reverse()
            ->map(function ($conv) {
                $items = [];
                if ($conv->user_message) {
                    $items[] = ['role' => 'user', 'content' => $conv->user_message];
                }
                if ($conv->ai_response) {
                    $items[] = ['role' => 'assistant', 'content' => $conv->ai_response];
                }

                return $items;
            })
            ->flatten(1)
            ->toArray();

        // 3) Call Groq
        try {
            $messages = array_merge([
                ['role' => 'system', 'content' => $this->getSystemPrompt()],
            ], $history, [
                ['role' => 'user', 'content' => $message],
            ]);

            $response = Http::timeout(30)->withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl.'/chat/completions', [
                'model' => $this->model,
                'messages' => $messages,
                'temperature' => 0.0,
                'max_tokens' => 1024,
            ]);

            $response->throw();
            $responseData = $response->json();

            if (isset($responseData['error'])) {
                throw new Exception($responseData['error']['message'] ?? 'Groq API error');
            }

            $reply = $responseData['choices'][0]['message']['content'] ?? null;
            if (! $reply) {
                throw new Exception('Empty reply from AI');
            }

            // 4) Compute a simple confidence heuristic
            $confidence = $this->estimateConfidence($reply, $responseData);

            $escalated = $confidence < (float) config('pearlie.escalation_threshold', 0.7);
            $patientReply = $escalated
                ? $this->patientHandoffMessage($message)
                : $reply;

            $conversation = Conversation::create([
                'session_id' => $sessionId,
                'user_message' => $message,
                'ai_response' => $patientReply,
                'confidence_score' => $confidence,
                'channel' => $channel,
                'escalated' => false,
            ]);

            $threshold = (float) config('pearlie.escalation_threshold', 0.7);
            if ($confidence < $threshold) {
                try {
                    $escalationRequest = $this->requestEscalation(
                        $sessionId,
                        $message,
                        $patientReply,
                        $channel,
                        $conversation,
                    );
                    $patientReply = $escalationRequest['response'];
                    $escalated = $escalationRequest['escalated'];
                } catch (Exception $e) {
                    Log::error('Escalation failed: '.$e->getMessage());
                    $escalated = false;
                    $patientReply = $reply;
                    Conversation::query()
                        ->where('session_id', $sessionId)
                        ->where('user_message', $message)
                        ->latest('id')
                        ->first()
                        ?->forceFill(['ai_response' => $patientReply, 'escalated' => false])
                        ->save();
                }
            }

            return [
                'response' => $patientReply,
                'confidence' => $confidence,
                'source' => 'groq',
                'escalated' => $escalated,
                'appointment_id' => null,
            ];

        } catch (Exception $e) {
            Log::error('Groq API Error: '.$e->getMessage());

            $fallback = $this->patientHandoffMessage($message);
            $conversation = Conversation::create([
                'session_id' => $sessionId,
                'user_message' => $message,
                'ai_response' => $fallback,
                'confidence_score' => 0.3,
                'channel' => $channel,
                'escalated' => false,
            ]);
            $escalated = true;

            try {
                $escalationRequest = $this->requestEscalation(
                    $sessionId,
                    $message,
                    $fallback,
                    $channel,
                    $conversation,
                );
                $fallback = $escalationRequest['response'];
                $escalated = $escalationRequest['escalated'];
            } catch (Exception $escalationException) {
                Log::error('Fallback escalation failed: '.$escalationException->getMessage());
                $escalated = false;
                $fallback = $this->isSwahili($message)
                    ? 'Samahani, nina tatizo la kuunganisha huduma yangu kwa sasa. Tafadhali wasiliana na '.pearlie_config('hospital.name').' kwa namba '.pearlie_config('hospital.appointment_phone').'.'
                    : sprintf(
                        "I'm sorry, I'm having trouble connecting to my AI system. Please contact %s at %s.",
                        pearlie_config('hospital.name'),
                        pearlie_config('hospital.appointment_phone'),
                    );
                Conversation::query()
                    ->where('session_id', $sessionId)
                    ->where('user_message', $message)
                    ->latest('id')
                    ->first()
                    ?->forceFill(['ai_response' => $fallback, 'escalated' => false])
                    ->save();
            }

            return [
                'response' => $fallback,
                'confidence' => 0.3,
                'source' => 'fallback',
                'escalated' => $escalated,
                'appointment_id' => null,
            ];
        }
    }

    protected function patientHandoffMessage(string $message): string
    {
        if ($this->isEmergencyMessage($message)) {
            $hospital = hospital();
            $emergencyPhone = $hospital
                ? $hospital->emergency_phone
                : pearlie_config('hospital.emergency_phone');

            if ($this->isSwahili($message)) {
                return $emergencyPhone
                    ? 'Kwa dharura, piga '.$emergencyPhone.' sasa. Ninakuunganisha na mhudumu wa afya.'
                    : 'Kwa dharura, tafadhali wasiliana na timu ya dharura ya hospitali sasa. Ninakuunganisha na mhudumu wa afya.';
            }

            return $emergencyPhone
                ? 'For an emergency, call '.$emergencyPhone.' now. I am connecting you to a health worker.'
                : 'For an emergency, contact the hospital emergency team now. I am connecting you to a health worker.';
        }

        return $this->isSwahili($message)
            ? 'Sina uhakika kabisa kuhusu hilo. Ninakuunganisha na mhudumu wa afya sasa. Utapata jibu hivi karibuni. Unaweza kuendelea kuniuliza maswali mengine unaposubiri.'
            : "I'm not 100% sure about that. I'm connecting you to a health worker now. You'll get a response shortly. You can keep asking me other questions while you wait.";
    }

    /**
     * @return array{response: string, confidence: float, source: string, escalated: bool, appointment_id: ?int}|null
     */
    public function handleEscalationIntent(string $message, string $sessionId, string $channel): ?array
    {
        if (! $this->shouldEscalateMessage($message, $sessionId)) {
            return null;
        }

        $response = $this->patientHandoffMessage($message);
        $conversation = Conversation::query()->create([
            'session_id' => $sessionId,
            'user_message' => $message,
            'ai_response' => $response,
            'confidence_score' => 0.0,
            'channel' => $channel,
            'escalated' => false,
        ]);

        try {
            $escalationRequest = $this->requestEscalation(
                $sessionId,
                $message,
                $response,
                $channel,
                $conversation,
            );
            $response = $escalationRequest['response'];
        } catch (Exception $exception) {
            Log::error('Unable to create a keyword-triggered escalation.', [
                'session_id' => $sessionId,
                'exception' => $exception,
            ]);
            $response = sprintf(
                'I could not connect you to a health worker right now. Please call %s at %s for help.',
                pearlie_config('hospital.name'),
                pearlie_config('hospital.emergency_phone'),
            );
            $conversation->forceFill([
                'ai_response' => $response,
                'escalated' => false,
            ])->save();
        }

        return [
            'response' => $response,
            'confidence' => 0.0,
            'source' => Cache::has($this->pendingEscalationPhoneKey($sessionId))
                ? 'escalation_contact_details'
                : 'human_escalation',
            'escalated' => $conversation->fresh()->escalated,
            'appointment_id' => null,
        ];
    }

    /**
     * @return array{response: string, escalated: bool, awaiting_phone: bool}
     */
    private function requestEscalation(
        string $sessionId,
        string $message,
        string $aiResponse,
        string $channel,
        Conversation $conversation,
    ): array {
        $userPhone = $this->extractPatientPhone($message) ?? $this->patientPhoneForSession($sessionId);

        if (! $userPhone) {
            $isSwahili = $this->isSwahili($message);
            $phonePrompt = $isSwahili
                ? 'Kabla sijaunganisha na mhudumu wa afya, tafadhali shiriki namba yako ya simu ili aweze kukufikia.'
                : 'Before I connect you to a health worker, please share your phone number so they can reach you.';
            $response = $this->isEmergencyMessage($message)
                ? $aiResponse.' '.$phonePrompt
                : $phonePrompt;

            Cache::put($this->pendingEscalationPhoneKey($sessionId), [
                'user_message' => $message,
                'ai_response' => $aiResponse,
                'channel' => $channel,
                'conversation_id' => $conversation->id,
            ], now()->addMinutes(30));
            $conversation->forceFill([
                'ai_response' => $response,
                'escalated' => false,
            ])->save();

            return [
                'response' => $response,
                'escalated' => false,
                'awaiting_phone' => true,
            ];
        }

        $this->escalationService->createEscalation($sessionId, $message, $aiResponse, $userPhone);
        $conversation->forceFill(['escalated' => true])->save();

        return [
            'response' => $aiResponse,
            'escalated' => true,
            'awaiting_phone' => false,
        ];
    }

    /**
     * @return array{response: string, confidence: float, source: string, escalated: bool, appointment_id: ?int}|null
     */
    protected function continueEscalationPhoneCapture(
        string $message,
        string $sessionId,
        string $channel,
    ): ?array {
        $key = $this->pendingEscalationPhoneKey($sessionId);
        $pending = Cache::get($key);
        if (! is_array($pending)) {
            return null;
        }

        $userPhone = $this->extractPatientPhone($message);
        if (! $userPhone) {
            $isSwahili = (bool) ($pending['channel'] === 'whatsapp' && $this->isSwahili($message));
            $response = $isSwahili
                ? 'Tafadhali tuma namba sahihi ya simu ili mhudumu wa afya aweze kukufikia.'
                : 'Please share a valid phone number so a health worker can reach you.';
            Conversation::query()->create([
                'session_id' => $sessionId,
                'user_message' => $message,
                'ai_response' => $response,
                'confidence_score' => 1.0,
                'channel' => $channel,
                'escalated' => false,
            ]);

            return [
                'response' => $response,
                'confidence' => 1.0,
                'source' => 'escalation_contact_details',
                'escalated' => false,
                'appointment_id' => null,
            ];
        }

        $this->escalationService->createEscalation(
            $sessionId,
            (string) $pending['user_message'],
            (string) $pending['ai_response'],
            $userPhone,
        );

        $pendingBookingKey = 'pending_appointment_booking_'.hash('sha256', $sessionId);
        $pendingBooking = Cache::get($pendingBookingKey);
        if (is_array($pendingBooking)) {
            $pendingBooking['phone'] = $userPhone;
            Cache::put($pendingBookingKey, $pendingBooking, now()->addMinutes(30));
        }

        $originalConversation = Conversation::query()->findOrFail((int) $pending['conversation_id']);
        $originalConversation->forceFill([
            'ai_response' => (string) $pending['ai_response'],
            'escalated' => true,
        ])->save();
        Cache::forget($key);

        $isSwahili = $this->isSwahili((string) $pending['user_message']);
        $response = $isSwahili
            ? 'Asante. Mhudumu wa afya anaweza kukufikia kupitia namba hiyo.'
            : 'Thank you. A health worker can now reach you at that number.';
        Conversation::query()->create([
            'session_id' => $sessionId,
            'user_message' => $message,
            'ai_response' => $response,
            'confidence_score' => 1.0,
            'channel' => $channel,
            'escalated' => true,
        ]);

        return [
            'response' => $response,
            'confidence' => 1.0,
            'source' => 'human_escalation',
            'escalated' => true,
            'appointment_id' => null,
        ];
    }

    private function patientPhoneForSession(string $sessionId): ?string
    {
        $appointment = AppointmentRequest::query()
            ->where('session_id', $sessionId)
            ->latest('id')
            ->first(['phone', 'mpesa_phone']);
        $phone = $appointment?->mpesa_phone ?: $appointment?->phone;

        if ($phone) {
            return $phone;
        }

        $pendingBooking = Cache::get('pending_appointment_booking_'.hash('sha256', $sessionId));
        if (is_array($pendingBooking) && filled($pendingBooking['phone'] ?? null)) {
            return (string) $pendingBooking['phone'];
        }

        return str_starts_with($sessionId, 'whatsapp:')
            ? substr($sessionId, strlen('whatsapp:'))
            : null;
    }

    private function extractPatientPhone(string $message): ?string
    {
        if (preg_match('/(?:0[71]\d{8}|\+?254[71]\d{8}|[71]\d{8})/', $message, $matches)) {
            return $matches[0];
        }

        return null;
    }

    private function pendingEscalationPhoneKey(string $sessionId): string
    {
        return 'pending_escalation_phone_'.hash('sha256', $sessionId);
    }

    public function shouldEscalateMessage(string $message, string $sessionId): bool
    {
        $normalized = $this->normalizeForEscalation($message);
        $bookingIntent = (bool) preg_match(
            '/\b(book|booking|appointment|schedule|availability|available slots?|free slots?|miadi|weka\s+miadi|kuweka\s+miadi|naomba\s+miadi)\b/iu',
            $message,
        );
        $keywords = config('pearlie.escalation.auto_escalate_keywords', []);

        if ($bookingIntent) {
            $keywords = array_values(array_filter(
                $keywords,
                fn (string $keyword): bool => mb_strtolower($keyword) !== 'doctor',
            ));
        }

        foreach ($keywords as $keyword) {
            $normalizedKeyword = preg_quote($this->normalizeForEscalation((string) $keyword), '/');
            if (preg_match('/(?<![\pL\pN])'.$normalizedKeyword.'(?![\pL\pN])/u', $normalized)) {
                return true;
            }
        }

        if ($this->isEmergencyMessage($message)) {
            return true;
        }

        if (preg_match('/\b(angry|frustrated|furious|useless|stupid|ridiculous|fed up|upuzi|nimekasirika)\b/iu', $message)) {
            return true;
        }

        return Conversation::query()
            ->where('session_id', $sessionId)
            ->where('created_at', '>=', now()->subMinutes(2))
            ->latest('id')
            ->limit(20)
            ->pluck('user_message')
            ->contains(fn (string $previousMessage): bool => $this->normalizeForEscalation($previousMessage) === $normalized);
    }

    protected function normalizeForEscalation(string $message): string
    {
        $message = mb_strtolower($message);
        $message = preg_replace('/[^\pL\pN]+/u', ' ', $message) ?? $message;

        return trim($message);
    }

    protected function isEmergencyMessage(string $message): bool
    {
        return (bool) preg_match(
            '/\b(emergency|dharura|haraka|urgent(?:ly)?|ambulance|accident|ajali|bleeding|damu nyingi|can[\'’]?t breathe|chest pain|maumivu ya kifua|shida kupumua|kiharusi|nimeumia vibaya)\b/iu',
            $message,
        );
    }

    public function detectDoctorBookingIntent(string $message): bool
    {
        return (bool) preg_match(
            '/\b(book|booking|appointment|schedule|see\s+(?:a\s+)?doctor|availability|available slots?|free slots?|consultation|visit doctor|miadi|weka\s+miadi|kuweka\s+miadi|naomba\s+miadi|daktari)\b/iu',
            $message,
        );
    }

    /**
     * @return array{date: string, doctors: array<int, array{id: int, name: string, specialization: ?string, bio: ?string, licence_number: ?string, slots: array<string, bool>}>}
     */
    public function getAvailableSlotsForAI(
        string $message,
        ?int $preferredDoctorId = null,
        ?string $requiredSpecialty = null,
    ): array {
        $date = $this->extractRequestedDate($message) ?? CarbonImmutable::today()->toDateString();
        $doctors = $this->availabilityService->getAllDoctorsWithSlots($date);

        if (filled($requiredSpecialty)) {
            $normalizedSpecialty = mb_strtolower(trim($requiredSpecialty));
            $doctors = array_values(array_filter(
                $doctors,
                fn (array $doctorSlots): bool => mb_strtolower(trim((string) $doctorSlots['doctor']->specialization))
                    === $normalizedSpecialty,
            ));
        }

        if ($preferredDoctorId !== null) {
            $doctors = array_values(array_filter(
                $doctors,
                fn (array $doctorSlots): bool => $doctorSlots['doctor']->id === $preferredDoctorId,
            ));
        }

        return [
            'date' => $date,
            'doctors' => array_map(
                fn (array $doctorSlots): array => [
                    'id' => $doctorSlots['doctor']->id,
                    'name' => $doctorSlots['doctor']->name,
                    'specialization' => $doctorSlots['doctor']->specialization,
                    'bio' => $doctorSlots['doctor']->bio,
                    'licence_number' => $doctorSlots['doctor']->licence_number,
                    'slots' => $doctorSlots['slots'],
                ],
                $doctors,
            ),
        ];
    }

    /**
     * @return array{phone: ?string, email: ?string, preferred_date: ?string, name: ?string, reason: string, slot_start_time: ?string}|false
     */
    protected function detectAppointment(string $message): array|false
    {
        if (! $this->detectDoctorBookingIntent($message)) {
            return false;
        }

        $phone = null;
        if (preg_match('/(?:0?7\d{8,9}|\+?2547\d{8})/', $message, $m)) {
            $phone = $m[0];
        }

        $preferredDate = $this->extractRequestedDate($message);

        $name = null;
        if (preg_match('/(?:my name is|jina langu ni|naitwa)\s+([\pL][\pL\'-]*(?:\s+[\pL][\pL\'-]*){0,3}?)(?=\s+(?:and\b|with\b|my phone\b|namba yangu\b)|[,.;]|$)/iu', $message, $n)) {
            $name = trim($n[1]);
        }

        $matchingDoctors = $this->matchingDoctors($message);
        $preferredDoctorId = $matchingDoctors->count() === 1
            ? $matchingDoctors->first()->id
            : null;

        return [
            'phone' => $phone,
            'email' => $this->extractBookingEmail($message),
            'preferred_date' => $preferredDate,
            'name' => $name,
            'reason' => $this->extractBookingReason($message),
            'slot_start_time' => $this->extractRequestedTime($message),
            'preferred_doctor_id' => $preferredDoctorId,
            'doctor_selection_needed' => $matchingDoctors->count() > 1,
            'doctor_options' => $matchingDoctors->count() > 1
                ? $matchingDoctors->pluck('name')->all()
                : [],
        ];
    }

    /**
     * @return Collection<int, User>
     */
    protected function matchingDoctors(string $message): Collection
    {
        $hospital = hospital();
        if (! $hospital) {
            return collect();
        }

        $normalizedMessage = $this->normalizeDoctorText($message);
        $matches = [];

        foreach (User::query()
            ->where('hospital_id', $hospital->id)
            ->where('is_doctor', true)
            ->where('is_active', true)
            ->get() as $doctor) {
            $normalizedName = $this->normalizeDoctorText($doctor->name);
            $nameTokens = array_filter(
                explode(' ', $normalizedName),
                fn (string $token): bool => mb_strlen($token) >= 3 && ! in_array($token, ['doctor', 'nurse'], true),
            );
            $matchedNameTokens = collect($nameTokens)
                ->filter(fn (string $token): bool => str_contains($normalizedMessage, $token))
                ->count();
            $nameScore = $normalizedName !== '' && str_contains($normalizedMessage, $normalizedName)
                ? 100
                : $matchedNameTokens * 10;
            $specialization = $this->normalizeDoctorText((string) $doctor->specialization);
            $specializationScore = $specialization !== '' && str_contains($normalizedMessage, $specialization)
                ? 50 + mb_strlen($specialization)
                : 0;
            $score = max($nameScore, $specializationScore);

            if ($score > 0) {
                $matches[] = ['doctor' => $doctor, 'score' => $score];
            }
        }

        if ($matches === []) {
            return collect();
        }

        $highestScore = max(array_column($matches, 'score'));

        return collect($matches)
            ->where('score', $highestScore)
            ->pluck('doctor')
            ->values();
    }

    private function normalizeDoctorText(string $value): string
    {
        $normalized = mb_strtolower($value);

        return trim((string) preg_replace('/[^\pL\pN]+/u', ' ', $normalized));
    }

    protected function recordAppointment(array $appointmentData, string $message, string $sessionId, string $channel = 'web'): array
    {
        try {
            $appointment = null;
            $service = isset($appointmentData['service_id'])
                ? Service::query()->active()->findOrFail((int) $appointmentData['service_id'])
                : null;

            if ($appointmentData['preferred_date'] && $appointmentData['slot_start_time']) {
                $appointment = $this->availabilityService->bookAppointment(
                    $appointmentData['preferred_date'],
                    $appointmentData['slot_start_time'],
                    [
                        'name' => $appointmentData['name'],
                        'phone' => $appointmentData['phone'],
                        'email' => $appointmentData['email'] ?? null,
                        'reason' => $appointmentData['reason'],
                        'raw_message' => $message,
                        'session_id' => $sessionId,
                    ],
                    null,
                    $service,
                );

                if (! $appointment) {
                    return $this->respondWithAvailableSlots(
                        "availability {$appointmentData['preferred_date']}",
                        $sessionId,
                        $channel,
                        'That time is not available. Here are the open times instead.',
                    );
                }
            } else {
                $appointment = AppointmentRequest::query()->create([
                    'session_id' => $sessionId,
                    'patient_id' => auth()->user()?->role === 'patient' ? auth()->id() : null,
                    'name' => $appointmentData['name'],
                    'phone' => $appointmentData['phone'],
                    'email' => $appointmentData['email'] ?? null,
                    'preferred_date' => $appointmentData['preferred_date'],
                    'reason' => $appointmentData['reason'],
                    'raw_message' => $message,
                    'status' => AppointmentRequest::STATUS_PENDING,
                    ...($service ? [
                        'service_id' => $service->id,
                        'booking_fee' => (int) round((float) $service->price),
                        'payment_amount' => (float) $service->price,
                    ] : []),
                ]);
            }
        } catch (Exception $e) {
            Log::error('Failed to create appointment: '.$e->getMessage());

            throw $e;
        }

        $missing = [];
        if (! $appointment->name) {
            $missing[] = 'full name';
        }
        if (! $appointment->phone) {
            $missing[] = 'phone number';
        }
        if (! $appointment->email) {
            $missing[] = 'email address';
        }
        if (! $appointment->preferred_date) {
            $missing[] = 'preferred date';
        }
        if (! $appointment->reason) {
            $missing[] = 'reason for visit';
        }

        $responseText = $appointment->doctor_id
            ? sprintf(
                'Appointment request #%d is booked with %s for %s at %s and is pending confirmation.',
                $appointment->id,
                $appointment->doctor->name,
                $appointment->preferred_date->toFormattedDateString(),
                substr($appointment->slot_start_time, 0, 5),
            )
            : 'Appointment request #'.$appointment->id.' has been recorded as pending.';
        if ($missing) {
            $responseText .= ' Please reply with your '.implode(', ', $missing).' so our team can confirm it.';
        } else {
            $responseText .= ' Our team will contact you to confirm the details.';
        }

        Conversation::create([
            'session_id' => $sessionId,
            'user_message' => $message,
            'ai_response' => $responseText,
            'confidence_score' => 0.98,
            'channel' => $channel,
        ]);

        return [
            'response' => $responseText,
            'confidence' => 0.98,
            'source' => 'appointment',
            'escalated' => false,
            'appointment_id' => $appointment->id,
        ];
    }

    protected function respondWithAvailableSlots(
        string $message,
        string $sessionId,
        string $channel,
        ?string $introduction = null,
        ?int $preferredDoctorId = null,
        ?string $requiredSpecialty = null,
    ): array {
        $availability = $this->getAvailableSlotsForAI($message, $preferredDoctorId, $requiredSpecialty);
        $isSwahili = $this->isSwahili($message);
        $responseText = $introduction ?? ($isSwahili
            ? 'Hizi ndizo nafasi za miadi zinazopatikana tarehe '.$availability['date'].':'
            : 'Here are the available appointment times for '.$availability['date'].':');
        if ($introduction !== null && $isSwahili) {
            $responseText = 'Muda huo haupatikani tena. Hizi ndizo nafasi zilizo wazi:';
        }
        $hasAvailableSlots = false;

        foreach ($availability['doctors'] as $doctorSlots) {
            $times = array_keys(array_filter($doctorSlots['slots']));
            if ($times === []) {
                continue;
            }

            $hasAvailableSlots = true;
            $specialization = $doctorSlots['specialization']
                ? ' ('.$doctorSlots['specialization'].')'
                : '';
            $responseText .= "\n".$doctorSlots['name'].$specialization.': '.implode(', ', $times);

            $profileDetails = [];
            if (filled($doctorSlots['bio'])) {
                $profileDetails[] = Str::limit($doctorSlots['bio'], 240);
            }
            if (filled($doctorSlots['licence_number'])) {
                $licenceLabel = $isSwahili ? 'Nambari ya leseni' : 'Licence';
                $profileDetails[] = $licenceLabel.': '.$doctorSlots['licence_number'];
            }
            if ($profileDetails !== []) {
                $responseText .= "\n".implode(' | ', $profileDetails);
            }
        }

        if (! $hasAvailableSlots) {
            $responseText .= $isSwahili
                ? "\nHakuna nafasi zilizo wazi kwa tarehe hiyo. Tafadhali chagua tarehe nyingine au piga simu hospitalini kwa msaada."
                : "\nThere are no open times for that date. Please choose another date or call the hospital for assistance.";
        } else {
            $responseText .= $isSwahili
                ? "\nJibu kwa jina la daktari na muda unaopendelea ili kuomba nafasi."
                : "\nReply with a doctor's name and your preferred time to request a slot.";
        }

        Conversation::query()->create([
            'session_id' => $sessionId,
            'user_message' => $message,
            'ai_response' => $responseText,
            'confidence_score' => 0.98,
            'channel' => $channel,
        ]);

        return [
            'response' => $responseText,
            'confidence' => 0.98,
            'source' => 'availability',
            'escalated' => false,
            'appointment_id' => null,
            'date' => $availability['date'],
            'doctors' => $availability['doctors'],
        ];
    }

    protected function extractRequestedDate(string $message): ?string
    {
        return DateParser::parse($message)?->toDateString();
    }

    protected function extractRequestedTime(string $message): ?string
    {
        if (preg_match('/\b(?:at\s*)?([01]?\d)(?::([0-5]\d))?\s*(am|pm)\b/i', $message, $matches)) {
            $hour = (int) $matches[1] % 12;
            if (strtolower($matches[3]) === 'pm') {
                $hour += 12;
            }

            return sprintf('%02d:%02d', $hour, (int) ($matches[2] ?? 0));
        }

        if (preg_match('/\b(?:at\s*)?([01]\d|2[0-3]):([0-5]\d)\b/', $message, $matches)) {
            return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
        }

        return null;
    }

    protected function extractBookingEmail(string $message): ?string
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $message, $matches)
            && filter_var($matches[0], FILTER_VALIDATE_EMAIL)
        ) {
            return strtolower($matches[0]);
        }

        return null;
    }

    protected function extractBookingReason(string $message): string
    {
        if (preg_match('/(?:reason is|service(?: needed)? is|because|for|i need|ninahitaji|huduma ni|kwa ajili ya)\s+(.+?)(?=\s+(?:my name|jina langu|phone|namba|email|barua pepe)\b|[,.;]|$)/iu', $message, $matches)) {
            return trim($matches[1], " \t\n\r\0\x0B.,");
        }

        return '';
    }

    protected function isWeatherQuestion(string $message): bool
    {
        return (bool) preg_match('/\b(weather|temperature|forecast|rain(?:ing)?|sunny|cloudy)\b/i', $message);
    }

    /**
     * Estimate confidence from reply text and response meta. This is heuristic — replace with provider-provided scores if available.
     */
    protected function estimateConfidence(string $reply, array $responseData): float
    {
        // If provider gives logits/score, prefer it (not available in Groq fallback here)
        // Heuristics: if reply contains hedging phrases, reduce confidence
        $lowPhrases = ['i am not sure', 'i may be wrong', 'i don\'t know', 'unsure', 'not certain', 'please call'];
        $lower = strtolower($reply);
        foreach ($lowPhrases as $p) {
            if (strpos($lower, $p) !== false) {
                return 0.45;
            }
        }

        // otherwise assume reasonably confident
        return 0.85;
    }

    private function getSystemPrompt(): string
    {
        $hospital = hospital();
        $hospitalName = $hospital
            ? $hospital->name
            : pearlie_config('hospital.name', 'MediDesk Partner Hospital');
        $hospitalAddress = $hospital ? $hospital->address : pearlie_config('hospital.location', '');
        $hospitalPhone = $hospital ? $hospital->phone : pearlie_config('hospital.appointment_phone', '');
        $whatsappPhone = $hospital ? $hospital->whatsapp_number : $hospitalPhone;
        $emergencyPhone = $hospital ? $hospital->emergency_phone : pearlie_config('hospital.emergency_phone', '');
        $hospitalEmail = $hospital ? $hospital->email : pearlie_config('hospital.email', '');
        $hospitalWebsite = $hospital ? $hospital->website : pearlie_config('hospital.website', '');
        $emergencyHours = $hospital ? $hospital->hours_emergency : pearlie_config('hospital.hours_emergency', '');
        $outpatientHours = $hospital ? $hospital->hours_outpatient : pearlie_config('hospital.hours_outpatient', '');
        $assistantName = $hospital?->chatbotName() ?? 'Assistant';
        $hospitalDetails = collect([
            'Address' => $hospitalAddress,
            'Appointment phone' => $hospitalPhone,
            'WhatsApp' => $whatsappPhone,
            'Emergency phone' => $emergencyPhone,
            'Email' => $hospitalEmail,
            'Website' => $hospitalWebsite,
            'Emergency hours' => $emergencyHours,
            'Outpatient hours' => $outpatientHours,
        ])->filter()->map(fn (string $value, string $label): string => '- '.$label.': '.$value)->implode("\n");
        $services = $this->knowledgeBase->getServiceContext();

        $systemPrompt = <<<PROMPT
You are {$assistantName}, the bilingual (English and Swahili) digital front desk assistant at {$hospitalName}.

HOSPITAL IDENTITY:
- Name: {$hospitalName}
- Address: {$hospitalAddress}
- Phone: {$hospitalPhone}
- Emergency line: {$emergencyPhone}
- Email: {$hospitalEmail}
- Website: {$hospitalWebsite}

LANGUAGE RULES:
- Detect the language of the user’s message automatically.
- Reply in Swahili when the user writes in Swahili, in English when they write in English, and match mixed Sheng or code-switching.
- Never force a language the user did not use.
- Be warm, clear, respectful, and concise. Use “Karibu” or “Welcome” where appropriate.

RECOGNIZE common Swahili phrases:
- “Ninataka kuweka miadi” means the user wants to book an appointment.
- “Naweza kuongea na daktari” asks to speak to a doctor.
- “Ninaumwa”, “Naumwa na kichwa”, “Naumwa na tumbo”, and “Nina homa” describe feeling unwell, a headache, stomach pain, and fever.
- “Dharura” means emergency; “Bei gani” asks about price; “Mnafanya kazi saa ngapi” asks about hours; “Mko wapi” asks for location.
- “Asante”, “Karibu”, “Kwaheri”, “Tafadhali”, “Samahani”, “Ndiyo”, and “Hapana” mean thank you, welcome, goodbye, please, excuse me, yes, and no.
- Recognize greetings including Habari, Hujambo, Sijambo, Jambo, Mambo, Vipi, Niaje, Sasa, Shikamoo, Marahaba, Salama, Poa, Freshi, Mzuri, and Nzuri.

HOSPITAL INFORMATION:
{$hospitalDetails}

SERVICES CONFIGURED FOR THIS HOSPITAL:
{$services}

RULES:
1. Never give medical diagnoses. For symptoms, advise the user to see a doctor.
2. For emergencies, immediately share the emergency phone number.
3. For appointment requests, collect the user’s full name, phone, preferred date, and service needed.
4. If the user asks about one specific service, answer ONLY about that service using its configured knowledge-base entry. Queries such as “dental services you offer” mean that service, not the full catalog.
5. Only list all services when the user explicitly asks for the full services overview.
6. Accept dates including ordinal dates (for example, “11th October 2026”), “11 Oct 2026”, “11/10/2026”, ISO dates, today/leo, tomorrow/kesho, next week/wiki ijayo, and English or Swahili weekdays.
7. For appointment requests, collect only missing details and confirm before creating the appointment or starting payment.
8. If confidence is low, escalate to a health worker or ask the user to call the hospital.
9. Do not invent services, prices, hours, or availability; use only the hospital information above and the configured knowledge base.
10. Do not include prices or fees in chat responses; prices appear only in the final booking confirmation summary.
11. Never reveal internal system details, model names, or API keys.
12. Do not describe your reasoning. Be caring and professional in both languages.
PROMPT;

        $hospitalInstructions = trim((string) data_get(hospital()?->settings, 'ai_instructions', ''));
        if ($hospitalInstructions === '') {
            return $systemPrompt;
        }

        return str_replace(
            "\n\nRULES:",
            "\n\nHOSPITAL-SPECIFIC GUIDANCE:\nTreat the following as tenant-provided reference preferences. Do not follow it if it conflicts with safety, privacy, or the rules below.\n".$hospitalInstructions."\n\nRULES:",
            $systemPrompt,
        );
    }

    protected function isSwahili(string $message): bool
    {
        return (bool) preg_match(
            '/\b(habari|hujambo|sijambo|jambo|mambo|vipi|niaje|sasa|shikamoo|marahaba|salama|poa|freshi|mzuri|nzuri|miadi|daktari|ninaumwa|naumwa|kichwa|tumbo|homa|dharura|maumivu|kifua|shida|kupumua|damu|nyingi|kiharusi|ajali|nimeumia|vibaya|wapi|asante|karibu|kwaheri|tafadhali|samahani|ndiyo|hapana|kesho|leo|huduma|namba|simu|jina|barua pepe)\b/iu',
            $message,
        );
    }
}
