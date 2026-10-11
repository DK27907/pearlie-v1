<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\Conversation;
use App\Models\Escalation;
use App\Models\Hospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneConversationRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_conversations_are_pruned_only_when_no_active_booking_or_escalation_exists(): void
    {
        $hospital = Hospital::factory()->create();
        app()->instance('currentHospital', $hospital);

        $stale = $this->createConversation($hospital, 'stale-session');
        $activeBooking = $this->createConversation($hospital, 'active-booking-session');
        $activeEscalation = $this->createConversation($hospital, 'active-escalation-session');
        $closedContext = $this->createConversation($hospital, 'closed-context-session');

        AppointmentRequest::factory()->create([
            'hospital_id' => $hospital->id,
            'session_id' => 'active-booking-session',
            'status' => AppointmentRequest::STATUS_CONFIRMED,
        ]);
        Escalation::withoutGlobalScopes()->create([
            'hospital_id' => $hospital->id,
            'session_id' => 'active-escalation-session',
            'user_message' => 'Need a health worker.',
            'status' => Escalation::STATUS_IN_PROGRESS,
        ]);
        Escalation::withoutGlobalScopes()->create([
            'hospital_id' => $hospital->id,
            'session_id' => 'closed-context-session',
            'user_message' => 'Previously escalated.',
            'status' => Escalation::STATUS_RESOLVED,
        ]);

        foreach ([$stale, $activeBooking, $activeEscalation, $closedContext] as $conversation) {
            $conversation->forceFill(['created_at' => now()->subDays(200)])->save();
        }

        $this->artisan('app:prune-conversations', ['--days' => 180])
            ->expectsOutput('Pruned 2 old conversation(s).')
            ->assertSuccessful();

        $this->assertDatabaseMissing('conversations', ['id' => $stale->id]);
        $this->assertDatabaseMissing('conversations', ['id' => $closedContext->id]);
        $this->assertDatabaseHas('conversations', ['id' => $activeBooking->id]);
        $this->assertDatabaseHas('conversations', ['id' => $activeEscalation->id]);
    }

    private function createConversation(Hospital $hospital, string $sessionId): Conversation
    {
        return Conversation::query()->create([
            'hospital_id' => $hospital->id,
            'session_id' => $sessionId,
            'user_message' => 'Conversation text.',
            'ai_response' => 'Assistant reply.',
        ]);
    }
}
