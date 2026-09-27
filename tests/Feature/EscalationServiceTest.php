<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use App\Jobs\SendEscalationNotification;
use App\Services\EscalationService;
use App\Models\AppointmentRequest;
use App\Models\Conversation;
use App\Models\Escalation;

class EscalationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_escalation_dispatches_job_and_sends_notifications()
    {
        // Prepare related data
        $sessionId = 'test-session-1';
        AppointmentRequest::create([
            'session_id' => $sessionId,
            'name' => 'John Doe',
            'phone' => '+254700000000',
            'preferred_date' => null,
            'reason' => 'Test reason',
            'raw_message' => 'I would like an appointment',
            'status' => 'pending',
        ]);

        Conversation::create([
            'session_id' => $sessionId,
            'user_message' => 'Hello',
            'confidence_score' => 0.5,
        ]);

        // Fake the bus for job dispatches and bind a mock NotificationService
        Bus::fake();

        // Ensure escalation email is configured so the service dispatches the job
        \Illuminate\Support\Facades\Config::set('pearlie.escalation_email', 'frontdesk@example.com');

        // Mock NotificationService to assert SMS and WhatsApp calls
        $mock = \Mockery::mock(\App\Services\NotificationService::class);
        $mock->shouldReceive('sendSms')->once()->withArgs(function ($to, $message) {
            // basic assertions
            return is_string($to) && str_contains($message, 'ESCALATION #');
        })->andReturn(true);
        $mock->shouldReceive('sendWhatsApp')->once()->withArgs(function ($to, $message) {
            return is_string($to) && str_contains($message, 'ESCALATION #');
        })->andReturn(true);

        $this->app->instance(\App\Services\NotificationService::class, $mock);

        $svc = $this->app->make(EscalationService::class);

        $esc = $svc->createEscalation($sessionId, 'I need help', 'AI response');

        // Assert escalation persisted
        $this->assertDatabaseHas('escalations', ['id' => $esc->id, 'user_message' => 'I need help']);
        Bus::assertDispatched(SendEscalationNotification::class, fn (SendEscalationNotification $job): bool => $job->recipient === 'frontdesk@example.com');

        // Clean up Mockery
        \Mockery::close();
    }
}
