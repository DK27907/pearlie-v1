<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Hospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConversationCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_prunes_stale_conversation_without_breaking_foreign_keys(): void
    {
        $hospital = Hospital::factory()->create();
        app()->instance('currentHospital', $hospital);

        $conversation = Conversation::query()->create([
            'hospital_id' => $hospital->id,
            'session_id' => 'stale-cleanup-session',
            'user_message' => 'Smoke-test conversation.',
            'ai_response' => 'Smoke-test response.',
        ]);
        $conversation->forceFill(['created_at' => now()->subDays(91)])->save();

        $this->artisan('app:prune-conversations', ['--days' => 90])
            ->expectsOutput('Pruned 1 old conversation(s).')
            ->assertSuccessful();

        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
        $this->assertSame(0, DB::table('conversations')->count());
        $this->assertSame(0, DB::table('appointment_requests')->where('session_id', $conversation->session_id)->count());
        $this->assertSame(0, DB::table('escalations')->where('session_id', $conversation->session_id)->count());
        $this->assertSame(1, DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
    }
}
