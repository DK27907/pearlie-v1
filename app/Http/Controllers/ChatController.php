<?php

namespace App\Http\Controllers;

use App\Models\Hospital;
use App\Services\BookingChatService;
use App\Services\PearlieService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

        return view('tenant.chat', [
            'hospital' => $hospital,
            'aiName' => $hospital->chatbotName(),
            'chatSessionId' => $this->chatSessionId($request, $hospital),
            'services' => $hospital->services()->active()->orderBy('name')->get(),
            'selectedServiceId' => $request->integer('service_id') ?: null,
        ]);
    }

    public function chat(Request $request): JsonResponse
    {
        $hospital = hospital();
        abort_unless($hospital, 404);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'service_id' => [
                'nullable',
                'integer',
                Rule::exists('services', 'id')
                    ->where('hospital_id', $hospital->id)
                    ->where('is_active', true),
            ],
        ]);

        $service = isset($validated['service_id'])
            ? $hospital->services()->active()->findOrFail($validated['service_id'])
            : null;
        $message = $service
            ? $service->name.'. '.$validated['message']
            : $validated['message'];
        $result = $this->bookingChat->handle(
            $this->chatSessionId($request, $hospital),
            $message,
            'web',
        );

        return response()->json($result->toArray());
    }

    public function reset(Request $request): JsonResponse
    {
        $hospital = hospital();
        abort_unless($hospital, 404);

        $sessionId = $this->chatSessionId($request, $hospital);
        $this->bookingChat->reset($sessionId, 'web');
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
}
