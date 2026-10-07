<?php

namespace Tests\Feature;

use App\Models\Escalation;
use App\Models\Hospital;
use App\Services\BookingChatService;
use App\Services\EscalationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BookingChatEscalationTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_keyword_wins_when_human_request_mentions_a_booking(): void
    {
        $this->createHospitalContext();
        Mail::fake();
        Http::preventStrayRequests();
        $sessionId = 'booking-human-escalation-session';
        $message = 'I need a human to call me about my booking. My phone is 254748249882.';

        $response = app(BookingChatService::class)->handle(
            $sessionId,
            $message,
        );

        $this->assertSame('COLLECTING', $response->state);
        $this->assertStringNotContainsString('health worker', mb_strtolower($response->message));
        $this->assertDatabaseMissing('escalations', ['session_id' => $sessionId]);
        $this->assertDatabaseHas('conversations', [
            'session_id' => $sessionId,
            'user_message' => $message,
            'escalated' => false,
        ]);
    }

    public function test_booking_with_phone_clears_pending_escalation_instead_of_completing_it(): void
    {
        $this->createHospitalContext();
        Mail::fake();
        Http::preventStrayRequests();
        $sessionId = 'booking-cancels-pending-escalation';
        $chat = app(BookingChatService::class);

        $chat->handle($sessionId, 'I need a human', 'web');
        $response = $chat->handle(
            $sessionId,
            'I want to book an appointment. My phone is 254748249882.',
            'web',
        );
        $state = $chat->getState($sessionId);

        $this->assertSame('COLLECTING', $response->state);
        $this->assertFalse($state['escalation_pending']);
        $this->assertNull($state['pending_escalation_message']);
        $this->assertSame('254748249882', $state['patient_phone']);
        $this->assertDatabaseMissing('escalations', ['session_id' => $sessionId]);
    }

    public function test_booking_chat_collects_a_phone_before_completing_a_human_handoff(): void
    {
        $hospital = $this->createHospitalContext();
        Mail::fake();
        Http::preventStrayRequests();
        $sessionId = 'booking-human-phone-session';

        $phonePrompt = app(BookingChatService::class)->handle(
            $sessionId,
            'I need a human to call me.',
        );

        $this->assertStringContainsString('share your phone number', $phonePrompt->message);
        $this->assertDatabaseMissing('escalations', ['session_id' => $sessionId]);

        $handoff = app(BookingChatService::class)->handle($sessionId, '254748249882');

        $this->assertSame('human_escalation', $handoff->source);
        $this->assertDatabaseHas('escalations', [
            'session_id' => $sessionId,
            'user_message' => 'I need a human to call me.',
            'user_phone' => '254748249882',
            'hospital_id' => $hospital->id,
            'status' => Escalation::STATUS_PENDING,
        ]);
    }

    public function test_human_handoff_preserves_booking_fields_and_uses_the_collected_phone(): void
    {
        $hospital = $this->createHospitalContext();
        Mail::fake();
        Http::preventStrayRequests();
        $sessionId = 'booking-human-preserved-state';
        $chat = app(BookingChatService::class);
        $chat->handle($sessionId, 'I want to book an appointment', 'web');
        $chat->handle($sessionId, 'My name is Test Patient, phone 254748249882', 'web');

        $response = $chat->handle($sessionId, 'I need a human.', 'web');
        $state = $chat->getState($sessionId);

        $this->assertSame('human_escalation', $response->source);
        $this->assertSame('COLLECTING', $response->state);
        $this->assertSame('Test Patient', $state['patient_name']);
        $this->assertSame('254748249882', $state['patient_phone']);
        $this->assertStringNotContainsString('share your phone number', $response->message);
        $this->assertDatabaseHas('escalations', [
            'session_id' => $sessionId,
            'hospital_id' => $hospital->id,
            'user_phone' => '254748249882',
            'status' => Escalation::STATUS_PENDING,
        ]);
    }

    public function test_messages_are_routed_to_a_pending_human_handoff(): void
    {
        $this->createHospitalContext();
        Mail::fake();
        Http::preventStrayRequests();
        $sessionId = 'pending-human-handoff-follow-up';
        app(EscalationService::class)->createEscalation(
            $sessionId,
            'Please connect me to a health worker.',
            'A health worker will follow up.',
            '254748249882',
        );

        $response = app(BookingChatService::class)->handle($sessionId, 'I have another question.', 'web');

        $this->assertSame('human_handoff', $response->source);
        $this->assertStringContainsString('in the queue', $response->message);
        $this->assertDatabaseHas('conversations', [
            'session_id' => $sessionId,
            'user_message' => 'I have another question.',
            'channel' => 'human_handoff',
            'escalated' => true,
        ]);
    }

    private function createHospitalContext(): Hospital
    {
        $hospital = Hospital::query()->create([
            'name' => 'Booking Escalation Hospital',
            'slug' => 'booking-escalation-'.fake()->unique()->slug(2),
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
