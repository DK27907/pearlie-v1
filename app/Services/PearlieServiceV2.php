<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\AppointmentRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class PearlieServiceV2
{
    protected KnowledgeBaseService $knowledgeBase;
    protected EscalationService $escalationService;
    protected ?string $apiKey;
    protected string $model;
    protected string $baseUrl;

    public function __construct(KnowledgeBaseService $knowledgeBase, EscalationService $escalationService)
    {
        $this->knowledgeBase = $knowledgeBase;
        $this->escalationService = $escalationService;
        $this->apiKey = env('GROQ_API_KEY');
        $this->model = env('GROQ_MODEL', 'groq/compound');
        $this->baseUrl = 'https://api.groq.com/openai/v1';
    }

    public function processMessage(string $message, string $sessionId, string $channel = 'web'): array
    {
        // Capture booking requests before knowledge-base matching so they always
        // create an actionable appointment record.
        $appointmentData = $this->detectAppointment($message);
        if ($appointmentData !== false) {
            return $this->recordAppointment($appointmentData, $message, $sessionId, $channel);
        }

        // 1) Knowledge base lookup
        try {
            $localAnswer = $this->knowledgeBase->search($message);
        } catch (Exception $e) {
            Log::error('KnowledgeBase search failed: ' . $e->getMessage());
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
            $responseText = 'I do not have live weather data right now. For today’s weather in Nyahururu, please check your preferred weather app or website. I can still help with Pearl Hospital information, services, or appointments.';

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
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/chat/completions', [
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
            if (!$reply) {
                throw new Exception('Empty reply from AI');
            }

            // 4) Compute a simple confidence heuristic
            $confidence = $this->estimateConfidence($reply, $responseData);

            $escalated = false;
            $threshold = (float) config('pearlie.escalation_threshold', 0.7);
            if ($confidence < $threshold) {
                try {
                    $this->escalationService->createEscalation($sessionId, $message, $reply);
                    $escalated = true;
                    $reply .= "\n\nI’ve sent this to our care team for follow-up. You can also call " . config('pearlie.hospital.phone') . ".";
                } catch (Exception $e) {
                    Log::error('Escalation failed: ' . $e->getMessage());
                }
            }

            Conversation::create([
                'session_id' => $sessionId,
                'user_message' => $message,
                'ai_response' => $reply,
                'confidence_score' => $confidence,
                'channel' => $channel,
                'escalated' => $escalated,
            ]);

            return [
                'response' => $reply,
                'confidence' => $confidence,
                'source' => 'groq',
                'escalated' => $escalated,
                'appointment_id' => null,
            ];

        } catch (Exception $e) {
            Log::error('Groq API Error: ' . $e->getMessage());

            $fallback = sprintf("I'm sorry, I'm having trouble connecting to my AI system. Please contact %s at %s.", config('pearlie.hospital.name'), config('pearlie.hospital.phone'));

            $escalated = false;
            try {
                $this->escalationService->createEscalation($sessionId, $message, $fallback);
                $escalated = true;
            } catch (Exception $escalationException) {
                Log::error('Fallback escalation failed: ' . $escalationException->getMessage());
            }

            Conversation::create([
                'session_id' => $sessionId,
                'user_message' => $message,
                'ai_response' => $fallback,
                'confidence_score' => 0.3,
                'channel' => $channel,
                'escalated' => $escalated,
            ]);

            return [
                'response' => $fallback,
                'confidence' => 0.3,
                'source' => 'fallback',
                'escalated' => $escalated,
                'appointment_id' => null,
            ];
        }
    }

    /**
     * Very small heuristic-based appointment detection. Returns parsed array or false.
     */
    protected function detectAppointment(string $message)
    {
        $lower = strtolower($message);
        $appointmentKeywords = ['appointment', 'book', 'schedule', 'see a doctor', 'consultation', 'book appointment', 'schedule appointment', 'visit doctor'];
        $found = false;
        foreach ($appointmentKeywords as $k) {
            if (strpos($lower, $k) !== false) {
                $found = true;
                break;
            }

        }

        if (!$found) {
            return false;
        }

        // Parse basic pieces: phone, date, name, reason
        $phone = null;
        if (preg_match('/0?7\\d{8,9}|\\+?2547\\d{8}/', $message, $m)) {
            $phone = $m[0];
        }

        $preferred_date = null;
        if (preg_match('/(\\d{4}-\\d{2}-\\d{2})/', $message, $d)) {
            $preferred_date = $d[1];
        } elseif (strpos($lower, 'tomorrow') !== false) {
            $preferred_date = now()->addDay()->toDateString();
        } elseif (strpos($lower, 'today') !== false) {
            $preferred_date = now()->toDateString();
        }

        $name = null;
        if (preg_match('/my name is ([A-Za-z \\ -]+)/i', $message, $n)) {
            $name = trim($n[1]);
        }

        // Use the whole message as reason fallback
        $reason = $message;

        return [
            'phone' => $phone,
            'preferred_date' => $preferred_date,
            'name' => $name,
            'reason' => $reason,
        ];
    }

    protected function recordAppointment(array $appointmentData, string $message, string $sessionId, string $channel = 'web'): array
    {
        try {
            $appointment = AppointmentRequest::create([
                'session_id' => $sessionId,
                'name' => $appointmentData['name'],
                'phone' => $appointmentData['phone'],
                'preferred_date' => $appointmentData['preferred_date'],
                'reason' => $appointmentData['reason'],
                'raw_message' => $message,
                'status' => AppointmentRequest::STATUS_PENDING,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to create appointment: ' . $e->getMessage());

            throw $e;
        }

        $missing = [];
        if (! $appointment->name) $missing[] = 'full name';
        if (! $appointment->phone) $missing[] = 'phone number';
        if (! $appointment->preferred_date) $missing[] = 'preferred date';
        if (! $appointment->reason) $missing[] = 'reason for visit';

        $responseText = 'Appointment request #' . $appointment->id . ' has been recorded as pending.';
        if ($missing) {
            $responseText .= ' Please reply with your ' . implode(', ', $missing) . ' so our team can confirm it.';
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
        return "You are Pearlie, a friendly and professional healthcare assistant at Pearl Hospital in Nyahururu, Kenya.

Your personality:
- Warm, empathetic, and professional
- You speak in clear, simple English
- You are helpful and patient
- You never give medical diagnoses — always advise patients to see a doctor
- Keep answers concise: normally 2-5 short sentences.
- Do not describe your reasoning or how you arrived at an answer.
- Do not invent live information such as current weather, traffic, prices, or opening status. Say clearly when live data is unavailable.
- Use simple Markdown only when it improves readability; do not over-format short answers.

About Pearl Hospital:
- Location: " . config('pearlie.hospital.address') . "\n- Phone: " . config('pearlie.hospital.phone') . "\n- Email: " . config('pearlie.hospital.email') . "\n- Services: Oncology, Cardiology, Emergency Medicine, Radiology, Outpatient, Inpatient\n- Hours: Emergency 24/7, Outpatient 8:00 AM - 6:00 PM Mon-Sat\n- Payment: M-Pesa, Cash, NHIF, AAR, CIC, Jubilee\n
Rules:\n1. Greet users warmly\n2. Answer questions about Pearl Hospital using the information above\n3. Never give medical diagnoses\n4. If unsure, suggest calling " . config('pearlie.hospital.phone') . "\n5. Keep responses conversational, direct, and helpful\n
Always be caring and professional.";
    }
}
