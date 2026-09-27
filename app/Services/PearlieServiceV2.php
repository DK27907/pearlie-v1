<?php

namespace App\Services;

use App\Models\AppointmentRequest;
use App\Models\Conversation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
            $responseText = $this->isSwahili($message)
                ? 'Samahani, sina taarifa za hali ya hewa za moja kwa moja kwa sasa. Naweza kukusaidia kuhusu taarifa za '.pearlie_config('hospital.name').', huduma, au miadi.'
                : 'I do not have live weather data right now. For today’s weather in Nyahururu, please check your preferred weather app or website. I can still help with '.pearlie_config('hospital.name').' information, services, or appointments.';

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

            Conversation::create([
                'session_id' => $sessionId,
                'user_message' => $message,
                'ai_response' => $patientReply,
                'confidence_score' => $confidence,
                'channel' => $channel,
                'escalated' => $escalated,
            ]);

            $threshold = (float) config('pearlie.escalation_threshold', 0.7);
            if ($confidence < $threshold) {
                try {
                    $this->escalationService->createEscalation($sessionId, $message, $reply);
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
            $escalated = true;
            Conversation::create([
                'session_id' => $sessionId,
                'user_message' => $message,
                'ai_response' => $fallback,
                'confidence_score' => 0.3,
                'channel' => $channel,
                'escalated' => true,
            ]);

            try {
                $this->escalationService->createEscalation($sessionId, $message, null);
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
            'escalated' => true,
        ]);

        try {
            $this->escalationService->createEscalation($sessionId, $message);
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
            'source' => 'human_escalation',
            'escalated' => $conversation->fresh()->escalated,
            'appointment_id' => null,
        ];
    }

    protected function shouldEscalateMessage(string $message, string $sessionId): bool
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

        if (preg_match('/\b(emergency|dharura|haraka|bleeding|can[\'’]?t breathe|chest pain)\b/iu', $message)) {
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

    public function detectDoctorBookingIntent(string $message): bool
    {
        return (bool) preg_match(
            '/\b(book|booking|appointment|schedule|see\s+(?:a\s+)?doctor|availability|available slots?|free slots?|consultation|visit doctor|miadi|weka\s+miadi|kuweka\s+miadi|naomba\s+miadi|daktari)\b/iu',
            $message,
        );
    }

    /**
     * @return array{date: string, doctors: array<int, array{id: int, name: string, specialization: ?string, slots: array<string, bool>}>}
     */
    public function getAvailableSlotsForAI(string $message): array
    {
        $date = $this->extractRequestedDate($message) ?? CarbonImmutable::today()->toDateString();
        $doctors = $this->availabilityService->getAllDoctorsWithSlots($date);

        return [
            'date' => $date,
            'doctors' => array_map(
                fn (array $doctorSlots): array => [
                    'id' => $doctorSlots['doctor']->id,
                    'name' => $doctorSlots['doctor']->name,
                    'specialization' => $doctorSlots['doctor']->specialization,
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

        $preferredDoctorId = null;
        foreach (User::query()->where('is_doctor', true)->get() as $doctor) {
            if (str_contains(mb_strtolower($message), mb_strtolower($doctor->name))) {
                $preferredDoctorId = $doctor->id;
                break;
            }
        }

        return [
            'phone' => $phone,
            'email' => $this->extractBookingEmail($message),
            'preferred_date' => $preferredDate,
            'name' => $name,
            'reason' => $this->extractBookingReason($message),
            'slot_start_time' => $this->extractRequestedTime($message),
            'preferred_doctor_id' => $preferredDoctorId,
        ];
    }

    protected function recordAppointment(array $appointmentData, string $message, string $sessionId, string $channel = 'web'): array
    {
        try {
            $appointment = null;

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
                    'name' => $appointmentData['name'],
                    'phone' => $appointmentData['phone'],
                    'email' => $appointmentData['email'] ?? null,
                    'preferred_date' => $appointmentData['preferred_date'],
                    'reason' => $appointmentData['reason'],
                    'raw_message' => $message,
                    'status' => AppointmentRequest::STATUS_PENDING,
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
    ): array {
        $availability = $this->getAvailableSlotsForAI($message);
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
        }

        if (! $hasAvailableSlots) {
            $responseText .= $isSwahili
                ? "\nHakuna nafasi zilizo wazi kwa tarehe hiyo. Tafadhali chagua tarehe nyingine au piga simu hospitalini kwa msaada."
                : "\nThere are no open times for that date. Please choose another date or call the hospital for assistance.";
        } else {
            $responseText .= $isSwahili
                ? "\nJibu kwa muda unaopendelea, jina lako kamili, namba ya simu, na barua pepe ili kuomba nafasi."
                : "\nReply with your preferred time, full name, phone number, and email address to request a slot.";
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
        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $message, $matches)) {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $matches[1]);

            return $date && $date->format('Y-m-d') === $matches[1]
                ? $date->toDateString()
                : null;
        }

        if (preg_match('/\b(tomorrow|kesho)\b/iu', $message)) {
            return CarbonImmutable::tomorrow()->toDateString();
        }

        if (preg_match('/\b(today|leo)\b/iu', $message)) {
            return CarbonImmutable::today()->toDateString();
        }

        $swahiliWeekdays = [
            'jumatatu' => 'monday',
            'jumanne' => 'tuesday',
            'jumatano' => 'wednesday',
            'alhamisi' => 'thursday',
            'ijumaa' => 'friday',
            'jumamosi' => 'saturday',
            'jumapili' => 'sunday',
        ];
        if (preg_match('/\b(?:next\s+)?(monday|tuesday|wednesday|thursday|friday|saturday|sunday|jumatatu|jumanne|jumatano|alhamisi|ijumaa|jumamosi|jumapili)\b/iu', $message, $matches)) {
            $weekday = $swahiliWeekdays[mb_strtolower($matches[1])] ?? mb_strtolower($matches[1]);

            return CarbonImmutable::parse('next '.$weekday)->toDateString();
        }

        return null;
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
        if (preg_match('/(?:reason is|service(?: needed)? is|because|for|ninahitaji|huduma ni|kwa ajili ya)\s+(.+?)(?=\s+(?:my name|jina langu|phone|namba|email|barua pepe)\b|[,.;]|$)/iu', $message, $matches)) {
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
        return sprintf(
            'You are Pearlie, a bilingual (English and Swahili) healthcare assistant at %s, %s.

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
- Name: %s
- Location: %s
- Appointment phone and WhatsApp: %s
- Emergency phone: %s
- Email: %s
- Website: %s
- Emergency services: %s
- Routine outpatient hours: %s
- Specialist clinics run during the week; callers should confirm the specialist’s day.
- The hospital offers outpatient and inpatient care, surgery, dialysis, oncology, IVF and fertility care, specialist clinics, physiotherapy, CT scans, X-ray and fluoroscopy, ultrasound, biopsies, ECG/ECHO/EEG, endoscopy and colonoscopy, laboratory services, maternity and child healthcare, family planning, wellness screening, dental, optical, and emergency services.

RULES:
1. Never give medical diagnoses. For symptoms, advise the user to see a doctor.
2. For emergencies, immediately share the emergency phone number.
3. For appointment requests, collect the user’s full name, phone, preferred date, and service needed.
4. If confidence is low, escalate to a health worker or ask the user to call the hospital.
5. Do not invent live information, prices, or availability; use the supplied knowledge and tools.
6. Never reveal internal system details, model names, or API keys.
7. Do not describe your reasoning. Be caring and professional in both languages.',
            pearlie_config('hospital.name'),
            pearlie_config('hospital.location'),
            pearlie_config('hospital.name'),
            pearlie_config('hospital.location'),
            pearlie_config('hospital.appointment_phone'),
            pearlie_config('hospital.emergency_phone'),
            pearlie_config('hospital.email'),
            pearlie_config('hospital.website'),
            pearlie_config('hospital.hours_emergency'),
            pearlie_config('hospital.hours_outpatient'),
        );
    }

    protected function isSwahili(string $message): bool
    {
        return (bool) preg_match(
            '/\b(habari|hujambo|sijambo|jambo|mambo|vipi|niaje|sasa|shikamoo|marahaba|salama|poa|freshi|mzuri|nzuri|miadi|daktari|ninaumwa|naumwa|kichwa|tumbo|homa|dharura|wapi|asante|karibu|kwaheri|tafadhali|samahani|ndiyo|hapana|kesho|leo|huduma|namba|simu|jina|barua pepe)\b/iu',
            $message,
        );
    }
}
