<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Hospital;
use App\Services\BookingChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ChatStateRaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_chat_turn_does_not_overwrite_a_newer_conversation_state(): void
    {
        $hospital = Hospital::factory()->create();
        app()->instance('currentHospital', $hospital);
        Conversation::query()->create([
            'session_id' => 'chat-state-version-race',
            'channel' => 'web',
            'user_message' => 'previous',
            'ai_response' => 'What is your name?',
            'chat_state' => [
                'state' => 'COLLECTING',
                'language' => 'en',
                'patient_name' => 'Original Patient',
                'state_version' => 0,
            ],
        ]);

        $insertConcurrentState = true;
        Event::listen('eloquent.retrieved: '.Conversation::class, function () use (&$insertConcurrentState): void {
            if (! $insertConcurrentState) {
                return;
            }

            $insertConcurrentState = false;
            Conversation::query()->create([
                'session_id' => 'chat-state-version-race',
                'channel' => 'web',
                'user_message' => 'concurrent patient update',
                'ai_response' => 'Thank you.',
                'chat_state' => [
                    'state' => 'COLLECTING',
                    'language' => 'en',
                    'patient_name' => 'Concurrent Patient',
                    'state_version' => 1,
                ],
            ]);
        });

        $response = app(BookingChatService::class)->handle(
            'chat-state-version-race',
            'thank you',
        );

        $latestConversation = Conversation::query()
            ->where('session_id', 'chat-state-version-race')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('retry', $response->source);
        $this->assertSame('COLLECTING', $latestConversation->chat_state['state']);
        $this->assertSame('Concurrent Patient', $latestConversation->chat_state['patient_name']);
        $this->assertSame(1, $latestConversation->chat_state['state_version']);
    }

    public function test_reset_does_not_overwrite_a_concurrent_state_change(): void
    {
        $hospital = Hospital::factory()->create();
        app()->instance('currentHospital', $hospital);
        Conversation::query()->create([
            'session_id' => 'chat-reset-version-race',
            'channel' => 'web',
            'user_message' => 'previous',
            'ai_response' => 'What is your name?',
            'chat_state' => [
                'state' => 'COLLECTING',
                'language' => 'en',
                'patient_name' => 'Original Patient',
                'state_version' => 0,
            ],
        ]);

        $insertConcurrentState = true;
        Event::listen('eloquent.retrieved: '.Conversation::class, function () use (&$insertConcurrentState): void {
            if (! $insertConcurrentState) {
                return;
            }

            $insertConcurrentState = false;
            Conversation::query()->create([
                'session_id' => 'chat-reset-version-race',
                'channel' => 'web',
                'user_message' => 'concurrent patient update',
                'ai_response' => 'Thank you.',
                'chat_state' => [
                    'state' => 'COLLECTING',
                    'language' => 'en',
                    'patient_name' => 'Concurrent Patient',
                    'state_version' => 1,
                ],
            ]);
        });

        $reset = app(BookingChatService::class)->reset('chat-reset-version-race');

        $latestConversation = Conversation::query()
            ->where('session_id', 'chat-reset-version-race')
            ->latest('id')
            ->firstOrFail();

        $this->assertFalse($reset);
        $this->assertSame('COLLECTING', $latestConversation->chat_state['state']);
        $this->assertSame('Concurrent Patient', $latestConversation->chat_state['patient_name']);
        $this->assertSame(1, $latestConversation->chat_state['state_version']);
    }
}
