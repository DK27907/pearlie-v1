<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\View\View;

class ConversationController extends Controller
{
    public function index(): View
    {
        return view('admin.conversations.index', [
            'conversations' => Conversation::query()
                ->select(['id', 'session_id', 'user_message', 'ai_response', 'channel', 'escalated', 'created_at'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(25),
        ]);
    }

    public function show(Conversation $conversation): View
    {
        return view('admin.conversations.show', [
            'sessionId' => $conversation->session_id,
            'transcript' => Conversation::query()
                ->where('session_id', $conversation->session_id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(),
        ]);
    }
}
