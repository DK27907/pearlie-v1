<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Services\BookingChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChatIntentTest extends TestCase
{
    use RefreshDatabase;

    public static function startOverMessages(): array
    {
        return [
            'start over' => ['start over'],
            'start again' => ['start again'],
            'restart' => ['restart'],
            'reset' => ['reset'],
            'begin again' => ['begin again'],
            'Swahili start over' => ['anza upya'],
            'Swahili start again' => ['tuanzie upya'],
        ];
    }

    #[DataProvider('startOverMessages')]
    public function test_start_over_phrases_clear_an_in_progress_booking(string $message): void
    {
        $this->createHospital();
        $chat = app(BookingChatService::class);
        $chat->handle('intent-reset-'.$message, 'I want to book an appointment', 'web');

        $response = $chat->handle('intent-reset-'.$message, $message, 'web');

        $this->assertSame('IDLE', $response->state);
        $this->assertSame('booking_reset', $response->source);
        $this->assertSame('IDLE', $chat->getState('intent-reset-'.$message)['state']);
    }

    public static function thanksMessages(): array
    {
        return [
            'thank you' => ['thank you', "You're welcome"],
            'thanks' => ['thanks', "You're welcome"],
            'asante' => ['asante', 'Karibu'],
            'shukran' => ['shukran', 'Karibu'],
        ];
    }

    #[DataProvider('thanksMessages')]
    public function test_thanks_is_acknowledged_without_changing_booking_state(
        string $message,
        string $expectedReply,
    ): void {
        $this->createHospital();
        $chat = app(BookingChatService::class);
        $sessionId = 'intent-thanks-'.str_replace(' ', '-', $message);
        $chat->handle($sessionId, 'I want to book an appointment', 'web');

        $response = $chat->handle($sessionId, $message, 'web');

        $this->assertStringContainsString($expectedReply, $response->message);
        $this->assertSame('COLLECTING', $response->state);
        $this->assertSame('COLLECTING', $chat->getState($sessionId)['state']);
    }

    public static function unconfirmedYesNoMessages(): array
    {
        return [
            'yes' => ['yes'],
            'no' => ['no'],
        ];
    }

    #[DataProvider('unconfirmedYesNoMessages')]
    public function test_yes_or_no_without_a_pending_confirmation_does_not_start_booking(string $message): void
    {
        $this->createHospital();

        $response = app(BookingChatService::class)->handle('intent-unconfirmed-'.$message, $message, 'web');

        $this->assertStringContainsString('no booking waiting for confirmation', mb_strtolower($response->message));
        $this->assertSame('IDLE', $response->state);
        $this->assertDatabaseCount('appointment_requests', 0);
    }

    private function createHospital(): Hospital
    {
        $hospital = Hospital::query()->create([
            'name' => 'Intent Test Hospital',
            'slug' => 'intent-test-'.fake()->unique()->slug(2),
            'subscription_plan' => 'professional',
            'subscription_status' => 'active',
            'is_active' => true,
            'deposit_amount' => 500,
            'slot_duration_minutes' => 30,
            'no_show_grace_minutes' => 30,
            'default_language' => 'en',
            'supported_languages' => ['en', 'sw'],
        ]);
        app()->instance('currentHospital', $hospital);

        return $hospital;
    }
}
