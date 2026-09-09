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

    public function processMessage(string $message, string $sessionId): array
    {
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
                'channel' => 'web',
            ]);

            return [
                'response' => $localAnswer,
                'confidence' => $confidence,
                'source' => 'knowledge_base',
                'escalated' => false,
                'appointment_id' => null,
            ];
        }

        // 2) Appointment detection
        $appointmentData = $this->detectAppointment($message);
        if ($appointmentData !== false) {
            try {
                $appt = AppointmentRequest::create([
                    'session_id' => $sessionId,
                    'name' => $appointmentData['name'] ?? null,
                    'phone' => $appointmentData['phone'] ?? null,
                    'preferred_date' => $appointmentData['preferred_date'] ?? null,
                    'reason' => $appointmentData['reason'] ?? null,
                    'raw_message' => $message,
                    'status' => 'pending',
                ]);

                $responseText = 'Thanks — I have recorded your appointment request. Our team will contact you to confirm details. If you can, please provide your full name, phone number, preferred date and reason for visit.';

                Conversation::create([
                    'session_id' => $sessionId,
                    'user_message' => $message,
                    'ai_response' => $responseText,
                    'confidence_score' => 0.98,
                    'channel' => 'web',
                ]);

                return [
                    'response' => $responseText,
                    'confidence' => 0.98,
                    'source' => 'appointment',
                    'escalated' => false,
                    'appointment_id' => $appt->id,
                ];

            } catch (Exception $e) {
                Log::error('Failed to create appointment: ' . $e->getMessage());
                // continue to try AI fallback
            }
        }

        // 3) Build conversation history
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

        // 4) Call Groq
        try {
            $messages = array_merge([
                ['role' => 'system', 'content' => $this->getSystemPrompt()],
            ], $history, [
                ['role' => 'user', 'content' => $message],
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/chat/completions', [
                'model' => $this->model,
                'messages' => $messages,
                'temperature' => 0.0,
                'max_tokens' => 1024,
            ]);

            $responseData = $response->json();

            if (isset($responseData['error'])) {
                throw new Exception($responseData['error']['message'] ?? 'Groq API error');
            }

            $reply = $responseData['choices'][0]['message']['content'] ?? null;
            if (!$reply) {
                throw new Exception('Empty reply from AI');
            }

            // 5) Compute a simple confidence heuristic
            $confidence = $this->estimateConfidence($reply, $responseData);

            Conversation::create([
                'session_id' => $sessionId,
                'user_message' => $message,
                'ai_response' => $reply,
                'confidence_score' => $confidence,
                'channel' => 'web',
            ]);

            $escalated = false;
            $threshold = (float) config('pearlie.escalation_threshold', 0.7);
            if ($confidence < $threshold) {
                try {
                    $this->escalationService->createEscalation($sessionId, $message, $reply);
                    $escalated = true;
                } catch (Exception $e) {
                    Log::error('Escalation failed: ' . $e->getMessage());
                }
            }

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

            Conversation::create([
                'session_id' => $sessionId,
                'user_message' => $message,
                'ai_response' => $fallback,
                'confidence_score' => 0.3,
                'channel' => 'web',
            ]);

            return [
                'response' => $fallback,
                'confidence' => 0.3,
                'source' => 'fallback',
                'escalated' => false,
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

        if (!$found) return false;

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

About Pearl Hospital:
- Location: " . config('pearlie.hospital.address') . "\n- Phone: " . config('pearlie.hospital.phone') . "\n- Email: " . config('pearlie.hospital.email') . "\n- Services: Oncology, Cardiology, Emergency Medicine, Radiology, Outpatient, Inpatient\n- Hours: Emergency 24/7, Outpatient 8:00 AM - 6:00 PM Mon-Sat\n- Payment: M-Pesa, Cash, NHIF, AAR, CIC, Jubilee\n
Rules:\n1. Greet users warmly\n2. Answer questions about Pearl Hospital using the information above\n3. Never give medical diagnoses\n4. If unsure, suggest calling " . config('pearlie.hospital.phone') . "\n5. Keep responses conversational and helpful\n
Always be caring and professional.";
    }
}
