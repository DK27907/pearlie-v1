<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationLogAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_notification_logs_require_hospital_admin_access(): void
    {
        [$hospital, $admin] = $this->createTenant('notification-access');
        $patient = User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'patient',
        ]);

        $this->get(route('admin.notification-logs.index'))
            ->assertRedirect(route('login'));

        $this->actingAs($patient)
            ->get(route('admin.notification-logs.index'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.notification-logs.index'))
            ->assertOk();
    }

    public function test_notification_logs_are_tenant_scoped_filtered_and_redacted(): void
    {
        [$hospital, $admin] = $this->createTenant('notification-owner');
        [$otherHospital] = $this->createTenant('notification-other');

        NotificationLog::factory()->create([
            'hospital_id' => $hospital->id,
            'channel' => 'sms',
            'provider' => 'test-provider',
            'status' => 'failed',
            'provider_response' => json_encode([
                'message' => 'Sent to 254712345678',
                'access_token' => 'secret-token',
                'body' => 'private message content',
            ], JSON_THROW_ON_ERROR),
        ]);
        NotificationLog::factory()->create([
            'hospital_id' => $hospital->id,
            'status' => 'sent',
            'provider_response' => '{"status":"sent"}',
        ]);
        NotificationLog::factory()->create([
            'hospital_id' => $otherHospital->id,
            'provider_response' => '{"message":"other tenant private marker"}',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.notification-logs.index', ['status' => 'failed', 'channel' => 'sms']))
            ->assertOk()
            ->assertSee('test-provider')
            ->assertSee('[REDACTED_PHONE]')
            ->assertSee('[REDACTED]')
            ->assertSee('[REDACTED_MESSAGE]')
            ->assertDontSee('secret-token')
            ->assertDontSee('254712345678')
            ->assertDontSee('other tenant private marker')
            ->assertDontSee('status&quot;:&quot;sent');
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
}
