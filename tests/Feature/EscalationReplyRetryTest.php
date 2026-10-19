<?php

namespace Tests\Feature;

use App\Jobs\SendEscalationReply;
use App\Models\Escalation;
use App\Models\EscalationReply;
use App\Models\Hospital;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\WhatsAppService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class EscalationReplyRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_failed_reply_is_visible_and_admin_can_queue_a_tenant_scoped_retry(): void
    {
        [$hospital, $admin] = $this->createTenant('reply-owner');
        [$otherHospital] = $this->createTenant('reply-other');
        $escalation = $this->createEscalation($hospital);
        $reply = $this->createReply($hospital, $escalation);
        $otherEscalation = $this->createEscalation($otherHospital, 'other-reply-session');
        $otherReply = $this->createReply($otherHospital, $otherEscalation);

        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldNotReceive('sendSms');
        $whatsApp = Mockery::mock(WhatsAppService::class);
        $whatsApp->shouldReceive('sendMessage')->once()->andReturn(['success' => false, 'message' => 'not delivered']);

        $job = new SendEscalationReply($reply->id, $hospital->id);
        try {
            $job->handle($notifications, $whatsApp);
            $this->fail('Expected the failed delivery to remain retryable.');
        } catch (RuntimeException $exception) {
            $this->assertSame(EscalationReply::STATUS_PENDING, $reply->fresh()->status);
            $this->assertSame(1, $reply->fresh()->attempts);
            $this->assertSame($otherHospital->id, hospital()->id);
            $job->failed($exception);
        }

        $this->actingAs($admin)
            ->get(route('admin.escalations.show', $escalation->id))
            ->assertOk()
            ->assertSee('Delivery failed after automatic retries.')
            ->assertSee('Retry delivery');

        Queue::fake();
        $this->actingAs($admin)
            ->post(route('admin.escalation-replies.retry', $reply->id))
            ->assertRedirect(route('admin.escalations.show', $escalation->id))
            ->assertSessionHas('status', 'Failed reply queued for retry.');

        $this->assertSame(EscalationReply::STATUS_PENDING, $reply->fresh()->status);
        Queue::assertPushed(SendEscalationReply::class, fn (SendEscalationReply $queued): bool => $queued->replyId === $reply->id);

        $this->actingAs($admin)
            ->post(route('admin.escalation-replies.retry', $otherReply->id))
            ->assertNotFound();
    }

    public function test_reply_job_does_not_mutate_a_reply_from_another_hospital(): void
    {
        [$hospital] = $this->createTenant('reply-job-owner');
        [$otherHospital] = $this->createTenant('reply-job-other');
        $escalation = $this->createEscalation($otherHospital, 'reply-job-other-session');
        $reply = $this->createReply($otherHospital, $escalation);

        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldNotReceive('sendSms');
        $whatsApp = Mockery::mock(WhatsAppService::class);
        $whatsApp->shouldNotReceive('sendMessage');
        $job = new SendEscalationReply($reply->id, $hospital->id);
        $replyNotFound = false;

        try {
            $job->handle($notifications, $whatsApp);
        } catch (ModelNotFoundException) {
            $replyNotFound = true;
        }

        $this->assertTrue($replyNotFound);
        $this->assertSame(EscalationReply::STATUS_PENDING, $reply->fresh()->status);
        $this->assertSame(0, $reply->fresh()->attempts);
        $this->assertSame($otherHospital->id, hospital()->id);
    }

    /**
     * @return array{Hospital, User}
     */
    private function createTenant(string $slug): array
    {
        $hospital = Hospital::factory()->create([
            'slug' => $slug,
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
            'is_active' => true,
        ]);
        $admin = User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'hospital_admin',
        ]);

        return [$hospital, $admin];
    }

    private function createEscalation(Hospital $hospital, string $sessionId = 'reply-session'): Escalation
    {
        app()->instance('currentHospital', $hospital);

        return Escalation::withoutGlobalScopes()->create([
            'hospital_id' => $hospital->id,
            'session_id' => $sessionId,
            'user_message' => 'Please ask a health worker to reply.',
            'user_phone' => '0712345678',
            'status' => Escalation::STATUS_IN_PROGRESS,
        ]);
    }

    private function createReply(Hospital $hospital, Escalation $escalation): EscalationReply
    {
        app()->instance('currentHospital', $hospital);

        return EscalationReply::withoutGlobalScopes()->create([
            'hospital_id' => $hospital->id,
            'escalation_id' => $escalation->id,
            'channel' => 'whatsapp',
            'message' => 'Your reply is being sent.',
            'status' => EscalationReply::STATUS_PENDING,
        ]);
    }
}
