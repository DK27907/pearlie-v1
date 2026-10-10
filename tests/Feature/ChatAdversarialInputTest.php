<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Hospital;
use App\Models\KnowledgeBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChatAdversarialInputTest extends TestCase
{
    use RefreshDatabase;

    public static function adversarialMessages(): array
    {
        return [
            'empty' => ['', 0],
            'whitespace' => [" \t  ", 0],
            'maximum length' => [str_repeat('x', 5000), 1],
            'emoji only' => ['🤒😷💊🩺', 0],
            'sql injection text' => ["'; DROP TABLE users; --", 1],
            'script text' => ['<script>alert(1)</script>', 1],
            'international phone' => ['+254748249882', 1],
            'spaced phone' => ['254 748 249 882', 1],
            'short phone' => ['123', 1],
            'alphabetic phone' => ['abc', 1],
            'past date' => ['I want an appointment on 2020-01-01', 0],
            'far future date' => ['I want an appointment on 3026-01-01', 0],
        ];
    }

    #[DataProvider('adversarialMessages')]
    public function test_adversarial_messages_return_a_safe_reply_and_store_one_conversation(
        string $message,
        int $expectedHttpRequests,
    ): void {
        $hospital = $this->createHospital();
        $this->fakeGroqResponse();
        $conversationCount = Conversation::query()->count();

        $response = $this->postJson(route('tenant.chat.post', $hospital->slug), [
            'message' => $message,
        ]);

        $response->assertOk();
        $this->assertNotSame('', trim((string) $response->json('response')));
        $this->assertSame($conversationCount + 1, Conversation::query()->count());
        $this->assertDatabaseCount('appointment_requests', 0);
        $this->assertDatabaseCount('escalations', 0);
        Http::assertSentCount($expectedHttpRequests);
    }

    public function test_switching_hospital_slugs_uses_separate_chat_sessions(): void
    {
        $pearl = $this->createHospital('switch-pearl');
        $demo = $this->createHospital('switch-demo');
        foreach ([$pearl, $demo] as $hospital) {
            app()->instance('currentHospital', $hospital);
            KnowledgeBase::query()->create([
                'category' => 'greeting',
                'subcategory' => 'hello',
                'keywords' => ['hello'],
                'question' => 'Greeting',
                'answer' => 'Hello from '.$hospital->name,
                'source' => 'Feature test',
                'last_updated' => now(),
            ]);
        }
        app()->forgetInstance('currentHospital');

        $this->postJson(route('tenant.chat.post', $pearl->slug), ['message' => 'hello'])->assertOk();
        $this->postJson(route('tenant.chat.post', $demo->slug), ['message' => 'hello'])->assertOk();

        $conversations = Conversation::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertCount(2, $conversations);
        $this->assertSame([$pearl->id, $demo->id], $conversations->pluck('hospital_id')->all());
        $this->assertNotSame($conversations[0]->session_id, $conversations[1]->session_id);
    }

    public function test_reset_mid_booking_starts_a_clean_state_when_booking_resumes(): void
    {
        $hospital = $this->createHospital();

        $this->postJson(route('tenant.chat.post', $hospital->slug), ['message' => 'I want to book an appointment'])
            ->assertOk()
            ->assertJsonPath('state', 'COLLECTING');
        $this->postJson(route('tenant.chat.reset', $hospital->slug))->assertOk()->assertJsonPath('reset', true);
        $this->postJson(route('tenant.chat.post', $hospital->slug), ['message' => 'I want to book an appointment'])
            ->assertOk()
            ->assertJsonPath('state', 'COLLECTING')
            ->assertJsonPath('collected_fields.service_id', null);

        $this->assertSame(3, Conversation::query()->count());
    }

    public function test_swahili_location_question_returns_the_current_hospital_address(): void
    {
        $hospital = $this->createHospital();
        $hospital->forceFill(['address' => 'Vin Plaza, Nyahururu'])->save();
        Http::preventStrayRequests();

        $this->postJson(route('tenant.chat.post', $hospital->slug), ['message' => 'mnapatikana wapi'])
            ->assertOk()
            ->assertJsonPath('response', $hospital->name.' is located at '.$hospital->address.'.')
            ->assertJsonPath('state', 'IDLE');
    }

    private function createHospital(?string $slug = null): Hospital
    {
        return Hospital::factory()->create([
            'slug' => $slug ?? fake()->unique()->slug(),
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
            'is_active' => true,
        ]);
    }

    private function fakeGroqResponse(): void
    {
        config([
            'services.groq.api_key' => 'test-groq-key',
            'services.groq.base_url' => 'https://api.groq.test/v1',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.groq.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'I can help with hospital information and appointments.'],
                ]],
            ]),
        ]);
    }
}
