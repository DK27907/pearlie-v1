<?php

namespace Tests\Feature;

use App\Models\Escalation;
use App\Models\Hospital;
use App\Services\EscalationNotificationService;
use App\Services\NotificationService;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EscalationNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_escalation_uses_its_contact_instead_of_global_defaults(): void
    {
        $hospital = Hospital::factory()->create([
            'phone' => '0711111111',
            'whatsapp_number' => null,
        ]);
        app()->instance('currentHospital', $hospital);
        config([
            'pearlie.escalation.notify_phone' => '0700000000',
            'pearlie.escalation.notify_whatsapp' => '254700000000',
            'pearlie.notify_emails' => [],
            'pearlie.escalation_email' => null,
        ]);

        $escalation = Escalation::query()->create([
            'session_id' => 'tenant-notification-session',
            'user_message' => 'Please connect me to a health worker.',
        ]);
        $notifications = $this->mock(NotificationService::class);
        $notifications->shouldReceive('sendSms')
            ->once()
            ->withArgs(fn (string $phone, string $message): bool => $phone === '0711111111')
            ->andReturn(true);
        $whatsApp = $this->mock(WhatsAppService::class);
        $whatsApp->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (string $phone, string $message): bool => $phone === '0711111111')
            ->andReturn(['success' => true, 'message' => 'accepted']);

        app(EscalationNotificationService::class)->notifyHealthWorkers($escalation);
    }
}
