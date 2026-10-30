<?php

namespace App\Http\Controllers;

use App\Models\AppointmentRequest;
use App\Models\Conversation;
use App\Models\Hospital;
use App\Services\BookingChatService;
use App\Services\PearlieService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ChatController extends Controller
{
    public function __construct(
        private readonly PearlieService $pearlie,
        private readonly BookingChatService $bookingChat,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $hospital = hospital();

        if (! $hospital) {
            return redirect()->route('home');
        }

        $sessionId = $this->chatSessionId($request, $hospital);
        if ($this->bookingChat->hasSessionExpired($sessionId, 'web')) {
            $sessionId = $this->chatSessionId($request, $hospital, true);
        }

        return view('tenant.chat', [
            'hospital' => $hospital,
            'aiName' => $hospital->chatbotName(),
            'chatSessionId' => $sessionId,
            'lastMessageId' => $this->latestConversationId($hospital, $sessionId),
            'services' => $hospital->services()->active()->orderBy('name')->get(),
        ]);
    }

    public function chat(Request $request): JsonResponse
    {
        $hospital = hospital();
        abort_unless($hospital, 404);

        $validated = $request->validate([
            'message' => ['present', 'nullable', 'string', 'max:5000'],
        ]);

        $sessionId = $this->chatSessionId($request, $hospital);
        $result = $this->bookingChat->handle(
            $sessionId,
            $validated['message'] ?? '',
            'web',
        );

        return response()->json([
            ...$result->toArray(),
            'last_message_id' => $this->latestConversationId($hospital, $sessionId),
        ]);
    }

    public function messages(Request $request): JsonResponse
    {
        $hospital = hospital();
        abort_unless($hospital, 404);

        $validated = $request->validate([
            'since_id' => ['sometimes', 'integer', 'min:0'],
        ]);
        $sinceId = (int) ($validated['since_id'] ?? 0);
        $sessionId = $this->chatSessionId($request, $hospital);
        // These reads bypass ambient scopes only because every query binds both resolved tenant and session.
        $latestAppointment = AppointmentRequest::withoutGlobalScopes()
            ->where('hospital_id', $hospital->id)
            ->where('session_id', $sessionId)
            ->latest('id')
            ->first(['id', 'status', 'payment_status']);
        $conversations = Conversation::withoutGlobalScopes()
            ->where('hospital_id', $hospital->id)
            ->where('session_id', $sessionId)
            ->where('channel', 'web')
            ->where('id', '>', $sinceId)
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'user_message', 'ai_response']);
        if ($latestAppointment?->payment_status === 'paid') {
            $receiptMessages = Conversation::withoutGlobalScopes()
                ->where('hospital_id', $hospital->id)
                ->where('session_id', $sessionId)
                ->where('channel', 'web')
                ->whereIn('chat_state->system_event', ['payment_receipt', 'receipt_ready'])
                ->where('chat_state->appointment_id', $latestAppointment->id)
                ->orderBy('id')
                ->get(['id', 'user_message', 'ai_response']);

            foreach ($receiptMessages as $receiptMessage) {
                if (! $conversations->contains('id', $receiptMessage->id)) {
                    $conversations->push($receiptMessage);
                }
            }
        }
        $conversations = $conversations->sortBy('id')->values();
        $messages = $conversations->flatMap(static function (Conversation $conversation): array {
            $messages = [];

            if (is_string($conversation->user_message)
                && $conversation->user_message !== ''
                && data_get($conversation->chat_state, 'system_event') !== 'payment_receipt'
            ) {
                $messages[] = ['id' => $conversation->id, 'role' => 'user', 'text' => $conversation->user_message];
            }
            if (is_string($conversation->ai_response) && $conversation->ai_response !== '') {
                $messages[] = ['id' => $conversation->id, 'role' => 'assistant', 'text' => $conversation->ai_response];
            }

            return $messages;
        })->values();
        $paymentTerminal = $latestAppointment !== null
            && ($latestAppointment->payment_status === 'paid'
                || in_array($latestAppointment->status, [
                    AppointmentRequest::STATUS_CANCELLED,
                    AppointmentRequest::STATUS_EXPIRED,
                ], true));

        return response()->json([
            'messages' => $messages,
            'last_message_id' => max($sinceId, (int) ($conversations->last()?->id ?? 0)),
            'payment_terminal' => $paymentTerminal,
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $hospital = hospital();
        abort_unless($hospital, 404);

        $sessionId = $this->chatSessionId($request, $hospital);
        if (! $this->bookingChat->reset($sessionId, 'web')) {
            return response()->json([
                'reset' => false,
                'retry' => true,
            ], 409);
        }
        $this->chatSessionId($request, $hospital, true);

        return response()->json(['reset' => true]);
    }

    private function chatSessionId(Request $request, Hospital $hospital, bool $new = false): string
    {
        $key = 'tenant_chat_session_id_'.$hospital->id;
        $sessionId = $new ? null : $request->session()->get($key);

        if (! is_string($sessionId) || $sessionId === '') {
            $sessionId = (string) Str::uuid();
            $request->session()->put($key, $sessionId);
        }

        return $sessionId;
    }

    private function latestConversationId(Hospital $hospital, string $sessionId): int
    {
        return (int) Conversation::withoutGlobalScopes()
            ->where('hospital_id', $hospital->id)
            ->where('session_id', $sessionId)
            ->where('channel', 'web')
            ->max('id');
    }
}
