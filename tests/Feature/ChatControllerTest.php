<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Hospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_messages_endpoint_returns_only_the_current_hospital_and_chat_session(): void
    {
        $hospital = Hospital::factory()->create([
            'slug' => 'receipt-chat',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);
        $otherHospital = Hospital::factory()->create([
            'slug' => 'other-receipt-chat',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);
        $sessionId = 'receipt-chat-session';
        $sessionKey = 'tenant_chat_session_id_'.$hospital->id;
        Conversation::withoutGlobalScopes()->forceCreate([
            'hospital_id' => $hospital->id,
            'session_id' => $sessionId,
            'user_message' => 'my latest message',
            'ai_response' => 'my latest response',
            'channel' => 'web',
        ]);
        Conversation::withoutGlobalScopes()->forceCreate([
            'hospital_id' => $otherHospital->id,
            'session_id' => $sessionId,
            'user_message' => 'other tenant private message',
            'ai_response' => 'other tenant private response',
            'channel' => 'web',
        ]);
        Conversation::withoutGlobalScopes()->forceCreate([
            'hospital_id' => $hospital->id,
            'session_id' => 'another-session',
            'user_message' => 'other session private message',
            'ai_response' => 'other session private response',
            'channel' => 'web',
        ]);
        $this->withSession([$sessionKey => $sessionId]);

        $response = $this->getJson(route('tenant.chat.messages', [
            'slug' => $hospital->slug,
            'since_id' => 0,
        ]));

        $response->assertOk()
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.text', 'my latest message')
            ->assertJsonPath('messages.1.text', 'my latest response')
            ->assertJsonPath('payment_terminal', false)
            ->assertDontSee('other tenant private')
            ->assertDontSee('other session private');
    }

    public function test_messages_endpoint_rejects_a_negative_message_cursor(): void
    {
        $hospital = Hospital::factory()->create([
            'slug' => 'invalid-cursor-chat',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);
        $this->withSession(['tenant_chat_session_id_'.$hospital->id => 'cursor-session']);

        $this->getJson(route('tenant.chat.messages', [
            'slug' => $hospital->slug,
            'since_id' => -1,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('since_id');
    }
}
