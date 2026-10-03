<?php

namespace Tests\Feature;

use App\Models\Escalation;
use App\Models\Hospital;
use App\Services\BookingChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BookingChatEscalationTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_intent_with_human_request_creates_an_escalation(): void
    {
        $hospital = $this->createHospitalContext();
        Mail::fake();
        Http::preventStrayRequests();
        $sessionId = 'booking-human-escalation-session';

        $response = app(BookingChatService::class)->handle(
            $sessionId,
            'I need a human to call me about my booking. My phone is 254748249882.',
        );

        $this->assertSame('human_escalation', $response->source);
        $this->assertStringContainsString('connecting you to a health worker', $response->message);
        $this->assertDatabaseHas('escalations', [
            'session_id' => $sessionId,
            'user_message' => 'I need a human to call me about my booking. My phone is 254748249882.',
            'user_phone' => '254748249882',
            'hospital_id' => $hospital->id,
            'status' => Escalation::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('conversations', [
            'session_id' => $sessionId,
            'user_message' => 'I need a human to call me about my booking. My phone is 254748249882.',
            'escalated' => true,
        ]);
    }

    public function test_booking_chat_collects_a_phone_before_completing_a_human_handoff(): void
    {
        $hospital = $this->createHospitalContext();
        Mail::fake();
        Http::preventStrayRequests();
        $sessionId = 'booking-human-phone-session';

        $phonePrompt = app(BookingChatService::class)->handle(
            $sessionId,
            'I need a human to call me about my booking.',
        );

        $this->assertStringContainsString('share your phone number', $phonePrompt->message);
        $this->assertDatabaseMissing('escalations', ['session_id' => $sessionId]);

        $handoff = app(BookingChatService::class)->handle($sessionId, '254748249882');

        $this->assertSame('human_escalation', $handoff->source);
        $this->assertDatabaseHas('escalations', [
            'session_id' => $sessionId,
            'user_message' => 'I need a human to call me about my booking.',
            'user_phone' => '254748249882',
            'hospital_id' => $hospital->id,
            'status' => Escalation::STATUS_PENDING,
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
