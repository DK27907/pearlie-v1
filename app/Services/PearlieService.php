<?php

namespace App\Services;

use App\Models\Conversation;
use Illuminate\Support\Facades\Http;

class PearlieService
{
    protected KnowledgeBaseService $knowledgeBase;
    protected $apiKey;
    protected $model;
    protected $baseUrl;

    public function __construct(KnowledgeBaseService $knowledgeBase)
    {
        $this->knowledgeBase = $knowledgeBase;
        $this->apiKey = env('GROQ_API_KEY');
        $this->model = env('GROQ_MODEL', 'groq/compound');
        $this->baseUrl = 'https://api.groq.com/openai/v1';
    }

    public function processMessage(string $message, string $sessionId): array
    {
        // Step 1: Check knowledge base
        $localAnswer = $this->knowledgeBase->search($message);

        if ($localAnswer) {
            Conversation::create([
                'session_id' => $sessionId,
                'user_message' => $message,
                'ai_response' => $localAnswer,
                'confidence_score' => 0.95,
                'channel' => 'web',
            ]);

            return [
                'response' => $localAnswer,
                'confidence' => 0.95,
                'source' => 'knowledge_base',
            ];
        }

        // Step 2: Get conversation history
        $history = Conversation::where('session_id', $sessionId)
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get()
            ->reverse()
            ->map(function ($conv) {
                return [
                    ['role' => 'user', 'content' => $conv->user_message],
                    ['role' => 'assistant', 'content' => $conv->ai_response],
                ];
            })
            ->flatten(1)
            ->toArray();

        try {
            // Step 3: Build messages
            $messages = array_merge([
                [
                    'role' => 'system',
                    'content' => $this->getSystemPrompt()
                ],
            ], $history, [
                [
                    'role' => 'user',
                    'content' => $message
                ]
            ]);

            // Step 4: Call Groq API
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/chat/completions', [
                'model' => $this->model,
                'messages' => $messages,
                'temperature' => 0.7,
                'max_tokens' => 1024,
            ]);

            $responseData = $response->json();
            
            if (isset($responseData['error'])) {
                throw new \Exception($responseData['error']['message'] ?? 'Groq API error');
            }

            $reply = $responseData['choices'][0]['message']['content'] 
                ?? 'I am having trouble responding. Please try again.';

            Conversation::create([
                'session_id' => $sessionId,
                'user_message' => $message,
                'ai_response' => $reply,
                'confidence_score' => 0.9,
                'channel' => 'web',
            ]);

            return [
                'response' => $reply,
                'confidence' => 0.9,
                'source' => 'groq',
            ];

        } catch (\Exception $e) {
            \Log::error('Groq API Error: ' . $e->getMessage());

            $fallback = "I'm sorry, I'm having trouble connecting to my AI system. Please contact Pearl Hospital directly at 0700000000, or visit us at Vin Plaza, Nyahururu.";

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
            ];
        }
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
- Location: Vin Plaza, Nyahururu-Nyeri Road, Nyahururu, Kenya
- Phone: 0700000000
- Email: info@pearlhospital.co.ke
- Services: Oncology, Cardiology, Emergency Medicine, Radiology, Outpatient, Inpatient
- Hours: Emergency 24/7, Outpatient 8:00 AM - 6:00 PM Mon-Sat
- Payment: M-Pesa, Cash, NHIF, AAR, CIC, Jubilee

Rules:
1. Greet users warmly
2. Answer questions about Pearl Hospital using the information above
3. Never give medical diagnoses
4. If unsure, suggest calling 0700000000
5. Keep responses conversational and helpful

Always be caring and professional.";
    }
}