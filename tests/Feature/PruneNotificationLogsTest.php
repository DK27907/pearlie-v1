<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\NotificationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneNotificationLogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_only_old_failed_logs_and_preserves_sent_logs(): void
    {
        $hospital = Hospital::factory()->create();
        $oldFailed = NotificationLog::factory()->for($hospital)->create([
            'status' => 'failed',
            'created_at' => now()->subDays(31),
        ]);
        $freshFailed = NotificationLog::factory()->for($hospital)->create([
            'status' => 'failed',
            'created_at' => now()->subDays(29),
        ]);
        $oldSent = NotificationLog::factory()->for($hospital)->create([
            'status' => 'sent',
            'created_at' => now()->subDays(90),
        ]);

        $this->artisan('app:prune-notification-logs', ['--days' => 30, '--status' => 'failed'])
            ->expectsOutput('Pruned 1 notification log(s).')
            ->assertSuccessful();

        $this->assertDatabaseMissing('notification_logs', ['id' => $oldFailed->id]);
        $this->assertDatabaseHas('notification_logs', ['id' => $freshFailed->id]);
        $this->assertDatabaseHas('notification_logs', ['id' => $oldSent->id]);
    }

    public function test_pruning_all_old_non_sent_logs_preserves_sent_logs(): void
    {
        $hospital = Hospital::factory()->create();
        $oldFailed = NotificationLog::factory()->for($hospital)->create([
            'status' => 'failed',
            'created_at' => now()->subDays(91),
        ]);
        $oldNotConfigured = NotificationLog::factory()->for($hospital)->create([
            'status' => 'not_configured',
            'created_at' => now()->subDays(91),
        ]);
        $oldSent = NotificationLog::factory()->for($hospital)->create([
            'status' => 'sent',
            'created_at' => now()->subDays(91),
        ]);

        $this->artisan('app:prune-notification-logs', ['--days' => 90, '--status' => 'all'])
            ->expectsOutput('Pruned 2 notification log(s).')
            ->assertSuccessful();

        $this->assertDatabaseMissing('notification_logs', ['id' => $oldFailed->id]);
        $this->assertDatabaseMissing('notification_logs', ['id' => $oldNotConfigured->id]);
        $this->assertDatabaseHas('notification_logs', ['id' => $oldSent->id]);
    }

    public function test_rejects_invalid_pruning_parameters(): void
    {
        $this->artisan('app:prune-notification-logs', ['--days' => 0, '--status' => 'sent'])
            ->expectsOutput('Days must be a positive integer and status must be all, failed, not_configured, or pending.')
            ->assertFailed();
    }
}
