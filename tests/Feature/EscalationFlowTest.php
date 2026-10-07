<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\Conversation;
use App\Models\Escalation;
use App\Models\Hospital;
use App\Models\User;
use App\Services\EscalationService;
use App\Services\PearlieServiceV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EscalationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        app()->instance(
            'currentHospital',
            Hospital::query()->where('slug', 'pearl')->firstOrFail(),
        );
    }

    public function test_escalation_triggers_on_low_confidence(): void
    {
        Mail::fake();
        $this->disableNotificationProviders();
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'I am not sure about that answer.'],
                ]],
            ]),
        ]);
        Http::preventStrayRequests();

        $result = app(PearlieServiceV2::class)->processMessage(
            'Can you explain this symptom?',
            'low-confidence-session',
        );

        $this->assertSame(0.45, $result['confidence']);
        $this->assertSame(true, $result['escalated']);
        $this->assertSame(
            "I'm not 100% sure about that. I'm connecting you to a health worker now. You'll get a response shortly. You can keep asking me other questions while you wait.",
            $result['response'],
        );
        $this->assertDatabaseHas('escalations', [
            'session_id' => 'low-confidence-session',
            'user_message' => 'Can you explain this symptom?',
            'status' => Escalation::STATUS_PENDING,
        ]);
    }

    public function test_escalation_triggers_on_human_help_keyword_and_returns_handoff(): void
    {
        Mail::fake();
        $this->disableNotificationProviders();
        $worker = User::factory()->create(['is_doctor' => true]);

        $result = app(PearlieServiceV2::class)->processMessage(
            'Please connect me to a human',
            'human-keyword-session',
        );

        $this->assertSame('human_escalation', $result['source']);
        $this->assertSame(true, $result['escalated']);
        $this->assertStringContainsString('connecting you to a health worker', $result['response']);
        $this->assertDatabaseHas('conversations', [
            'session_id' => 'human-keyword-session',
            'channel' => 'web',
            'escalated' => 1,
        ]);
        $this->assertDatabaseHas('escalations', [
            'session_id' => 'human-keyword-session',
            'status' => Escalation::STATUS_PENDING,
        ]);
        $notification = $worker->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertSame(
            route('doctor.escalations.show', $notification->data['escalation_id']),
            $notification->data['url'],
        );
    }

    public function test_escalation_triggers_on_urgent_medical_keywords(): void
    {
        Mail::fake();
        $this->disableNotificationProviders();

        $result = app(PearlieServiceV2::class)->processMessage(
            'Dharura, I have chest pain',
            'urgent-session',
        );

        $this->assertSame('human_escalation', $result['source']);
        $this->assertSame(true, $result['escalated']);
        $this->assertDatabaseHas('escalations', [
            'session_id' => 'urgent-session',
            'user_message' => 'Dharura, I have chest pain',
        ]);
    }

    public function test_escalation_triggers_when_a_patient_repeats_a_message_within_two_minutes(): void
    {
        Mail::fake();
        $this->disableNotificationProviders();
        Conversation::query()->create([
            'session_id' => 'repeated-message-session',
            'user_message' => 'Can you repeat that?',
            'ai_response' => 'Certainly.',
            'confidence_score' => 0.9,
            'channel' => 'web',
        ]);

        $result = app(PearlieServiceV2::class)->processMessage(
            'can you repeat that!',
            'repeated-message-session',
        );

        $this->assertSame('human_escalation', $result['source']);
        $this->assertSame(true, $result['escalated']);
        $this->assertDatabaseHas('escalations', [
            'session_id' => 'repeated-message-session',
        ]);
    }

    public function test_sms_is_sent_to_health_worker_with_escalation_link(): void
    {
        Mail::fake();
        config([
            'services.africastalking.username' => 'test-user',
            'services.africastalking.api_key' => 'test-key',
            'services.africastalking.sender_id' => 'PearlHosp',
            'services.whatsapp.phone_number_id' => null,
            'services.whatsapp.access_token' => null,
            'pearlie.escalation.notify_phone' => '0700000000',
            'pearlie.escalation.notify_whatsapp' => null,
        ]);
        Http::fake([
            'api.africastalking.com/version1/messaging' => Http::response(['SMSMessageData' => []]),
        ]);
        Http::preventStrayRequests();

        $escalation = app(EscalationService::class)->createEscalation(
            'sms-session',
            'I need a health worker',
        );

        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'api.africastalking.com')
            && $request['to'] === '0700000000'
            && str_contains($request['message'], 'ESCALATION #'.$escalation->id)
            && str_contains($request['message'], route('admin.escalations.show', $escalation->id)));
    }

    public function test_whatsapp_is_sent_to_health_worker_with_escalation_link(): void
    {
        Mail::fake();
        config([
            'services.africastalking.username' => null,
            'services.africastalking.api_key' => null,
            'services.whatsapp.phone_number_id' => 'business-phone-id',
            'services.whatsapp.access_token' => 'test-token',
            'services.whatsapp.api_version' => 'v20.0',
            'pearlie.escalation.notify_phone' => null,
            'pearlie.escalation.notify_whatsapp' => '254700000000',
        ]);
        Http::fake([
            'graph.facebook.com/v20.0/business-phone-id/messages' => Http::response(['messages' => [['id' => 'test-message']]]),
        ]);
        Http::preventStrayRequests();

        $escalation = app(EscalationService::class)->createEscalation(
            'whatsapp-notification-session',
            'Please help me',
        );

        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'graph.facebook.com')
            && $request['to'] === '254700000000'
            && str_contains($request['text']['body'], 'ESCALATION #'.$escalation->id)
            && str_contains($request['text']['body'], route('admin.escalations.show', $escalation->id)));
    }

    public function test_health_worker_can_claim_escalation_and_patient_is_notified(): void
    {
        Mail::fake();
        $this->disableNotificationProviders();
        config([
            'services.whatsapp.phone_number_id' => 'business-phone-id',
            'services.whatsapp.access_token' => 'test-token',
            'services.whatsapp.api_version' => 'v20.0',
        ]);
        Http::fake([
            'graph.facebook.com/v20.0/business-phone-id/messages' => Http::response(['messages' => [['id' => 'patient-message']]]),
        ]);
        Http::preventStrayRequests();

        $worker = User::factory()->create(['is_doctor' => true, 'name' => 'Dr. Kamau']);
        $sessionId = 'whatsapp:254712345678';
        AppointmentRequest::query()->create([
            'session_id' => $sessionId,
            'name' => 'Amina Wanjiku',
            'phone' => '0712345678',
            'preferred_date' => today(),
            'reason' => 'Consultation',
            'raw_message' => 'Appointment request',
            'status' => 'pending',
        ]);
        $escalation = Escalation::query()->create([
            'session_id' => $sessionId,
            'user_message' => 'I need help',
            'status' => Escalation::STATUS_PENDING,
        ]);

        $this->actingAs($worker)
            ->post(route('doctor.escalations.claim', $escalation->id))
            ->assertRedirect(route('doctor.escalations.show', $escalation->id))
            ->assertSessionHas('status', 'Escalation claimed. The patient has been notified.');

        $this->assertDatabaseHas('escalations', [
            'id' => $escalation->id,
            'status' => Escalation::STATUS_IN_PROGRESS,
            'assigned_worker_id' => $worker->id,
        ]);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'graph.facebook.com')
            && $request['to'] === '254712345678'
            && str_contains($request['text']['body'], 'Hi Amina Wanjiku, this is Dr. Kamau'));
    }

    public function test_health_worker_can_resolve_their_claimed_escalation(): void
    {
        $worker = User::factory()->create(['is_doctor' => true]);
        $escalation = Escalation::query()->create([
            'session_id' => 'resolve-session',
            'user_message' => 'I need support',
            'status' => Escalation::STATUS_IN_PROGRESS,
            'assigned_worker_id' => $worker->id,
            'claimed_at' => now(),
        ]);

        $this->actingAs($worker)
            ->post(route('doctor.escalations.resolve', $escalation->id))
            ->assertRedirect(route('doctor.escalations.show', $escalation->id))
            ->assertSessionHas('status', 'Escalation resolved.');

        $this->assertDatabaseHas('escalations', [
            'id' => $escalation->id,
            'status' => Escalation::STATUS_RESOLVED,
            'resolved_by_id' => $worker->id,
        ]);
        $this->assertNotNull($escalation->fresh()->resolved_at);
    }

    public function test_assigned_health_worker_can_reply_to_patient_by_whatsapp(): void
    {
        Mail::fake();
        config([
            'services.whatsapp.phone_number_id' => 'business-phone-id',
            'services.whatsapp.access_token' => 'test-token',
            'services.whatsapp.api_version' => 'v20.0',
            'services.africastalking.username' => null,
            'services.africastalking.api_key' => null,
        ]);
        Http::fake([
            'graph.facebook.com/v20.0/business-phone-id/messages' => Http::response(['messages' => [['id' => 'reply-message']]]),
        ]);
        Http::preventStrayRequests();

        $worker = User::factory()->create(['is_doctor' => true]);
        AppointmentRequest::query()->create([
            'session_id' => 'reply-session',
            'name' => 'Amina Wanjiku',
            'phone' => '0712345678',
            'preferred_date' => today(),
            'reason' => 'Consultation',
            'raw_message' => 'Appointment request',
            'status' => 'pending',
        ]);
        $escalation = Escalation::query()->create([
            'session_id' => 'reply-session',
            'user_message' => 'I need help',
            'status' => Escalation::STATUS_IN_PROGRESS,
            'assigned_worker_id' => $worker->id,
        ]);

        $this->actingAs($worker)
            ->post(route('doctor.escalations.reply', $escalation->id), [
                'channel' => 'whatsapp',
                'message' => 'Please come to the clinic now.',
            ])
            ->assertRedirect(route('doctor.escalations.show', $escalation->id))
            ->assertSessionHas('status', 'Reply sent to the patient.');

        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'graph.facebook.com')
            && $request['to'] === '254712345678'
            && str_contains($request['text']['body'], 'Please come to the clinic now.'));
        $this->assertDatabaseHas('conversations', [
            'session_id' => 'reply-session',
            'channel' => 'human',
            'ai_response' => 'Pearlie escalation #'.$escalation->id.' — Please come to the clinic now.',
        ]);
    }

    public function test_health_worker_cannot_resolve_another_workers_escalation(): void
    {
        $worker = User::factory()->create(['is_doctor' => true]);
        $otherWorker = User::factory()->create(['is_doctor' => true]);
        $escalation = Escalation::query()->create([
            'session_id' => 'another-worker-session',
            'user_message' => 'I need support',
            'status' => Escalation::STATUS_IN_PROGRESS,
            'assigned_worker_id' => $otherWorker->id,
        ]);

        $this->actingAs($worker)
            ->post(route('doctor.escalations.resolve', $escalation->id))
            ->assertForbidden();

        $this->assertDatabaseHas('escalations', [
            'id' => $escalation->id,
            'status' => Escalation::STATUS_IN_PROGRESS,
            'assigned_worker_id' => $otherWorker->id,
        ]);
    }

    public function test_patient_message_during_handoff_is_shared_with_assigned_worker(): void
    {
        $worker = User::factory()->create(['is_doctor' => true]);
        $escalation = Escalation::query()->create([
            'session_id' => 'active-session',
            'user_message' => 'Please help',
            'status' => Escalation::STATUS_IN_PROGRESS,
            'assigned_worker_id' => $worker->id,
        ]);

        $result = app(PearlieServiceV2::class)->processMessage(
            'The pain is getting worse',
            'active-session',
        );

        $this->assertSame('human_handoff', $result['source']);
        $this->assertSame(true, $result['escalated']);
        $this->assertDatabaseHas('conversations', [
            'session_id' => 'active-session',
            'user_message' => 'The pain is getting worse',
            'channel' => 'human_handoff',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $worker->id,
            'type' => \App\Notifications\EscalationPatientMessage::class,
        ]);
        $this->assertModelExists($escalation);
    }

    public function test_escalation_queue_filters_to_pending_and_searches_patient_details(): void
    {
        $worker = User::factory()->create(['is_doctor' => true]);
        AppointmentRequest::query()->create([
            'session_id' => 'pending-patient-session',
            'name' => 'Amina Wanjiku',
            'phone' => '0712345678',
            'preferred_date' => today(),
            'reason' => 'Consultation',
            'raw_message' => 'Need a visit',
            'status' => 'pending',
        ]);
        $pending = Escalation::query()->create([
            'session_id' => 'pending-patient-session',
            'user_message' => 'I need help',
            'status' => Escalation::STATUS_PENDING,
        ]);
        $inProgress = Escalation::query()->create([
            'session_id' => 'in-progress-session',
            'user_message' => 'Another issue',
            'status' => Escalation::STATUS_IN_PROGRESS,
            'assigned_worker_id' => $worker->id,
        ]);

        $this->actingAs($worker)
            ->get(route('doctor.escalations.index', ['status' => 'pending', 'q' => 'Amina']))
            ->assertOk()
            ->assertSee('Escalation queue')
            ->assertSee('Amina Wanjiku')
            ->assertSee('#'.$pending->id)
            ->assertDontSee('#'.$inProgress->id);

        $this->get(route('doctor.escalations.index', [
            'status' => 'pending',
            'q' => 'Amina',
            '_partial' => 1,
        ]))
            ->assertOk()
            ->assertSee('data-escalation-queue-content')
            ->assertDontSee('Escalation queue');
    }

    public function test_admin_can_access_the_shared_escalation_queue(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $escalation = Escalation::query()->create([
            'session_id' => 'admin-queue-session',
            'user_message' => 'Please help',
            'status' => Escalation::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.escalations.index'))
            ->assertOk()
            ->assertSee('Escalation queue')
            ->assertSee('#'.$escalation->id);
    }

    public function test_non_doctor_cannot_access_the_doctor_escalation_queue(): void
    {
        $patient = User::factory()->create(['is_doctor' => false, 'is_admin' => false]);

        $this->actingAs($patient)
            ->get(route('doctor.escalations.index'))
            ->assertForbidden();
    }

    public function test_duplicate_patient_escalations_are_rate_limited_for_five_minutes(): void
    {
        Mail::fake();
        $this->disableNotificationProviders();
        $worker = User::factory()->create(['is_doctor' => true]);
        $first = app(EscalationService::class)->createEscalation(
            'rate-limited-session',
            'First request',
        );

        $second = app(EscalationService::class)->createEscalation(
            'rate-limited-session',
            'Repeated request',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('escalations', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame($first->id, $worker->notifications()->first()->data['escalation_id']);
    }

    private function disableNotificationProviders(): void
    {
        config([
            'services.africastalking.username' => null,
            'services.africastalking.api_key' => null,
            'services.twilio.account_sid' => null,
            'services.twilio.auth_token' => null,
            'services.twilio.api_key' => null,
            'services.twilio.api_secret' => null,
            'services.twilio.from_number' => null,
            'services.whatsapp.phone_number_id' => null,
            'services.whatsapp.access_token' => null,
            'pearlie.escalation.notify_phone' => null,
            'pearlie.escalation.notify_whatsapp' => null,
            'pearlie.escalation_email' => null,
            'pearlie.notify_emails' => [],
        ]);
    }
}
