<?php

namespace Tests\Feature;

use App\Models\Hospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_post_returns_429_with_retry_after_after_sixty_requests_per_session(): void
    {
        $hospital = Hospital::factory()->create([
            'slug' => 'chat-rate-limit',
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
            'is_active' => true,
        ]);
        $this->withSession([
            'tenant_rate_limit_session_'.hash('sha256', $hospital->slug) => 'chat-rate-limit-test',
        ]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.61']);

        for ($requestNumber = 1; $requestNumber <= 61; $requestNumber++) {
            $response = $this->postJson(route('tenant.chat.post', $hospital->slug), ['message' => '']);

            if ($requestNumber <= 60) {
                $response->assertOk();
            } else {
                $response->assertTooManyRequests()->assertHeader('Retry-After');
            }
        }

        $this->assertDatabaseCount('conversations', 60);
    }

    public function test_chat_reset_returns_429_with_retry_after_after_ten_requests_per_session(): void
    {
        $hospital = Hospital::factory()->create([
            'slug' => 'chat-reset-rate-limit',
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
            'is_active' => true,
        ]);
        $this->withSession([
            'tenant_rate_limit_session_'.hash('sha256', $hospital->slug) => 'chat-reset-rate-limit-test',
        ]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.62']);

        for ($requestNumber = 1; $requestNumber <= 11; $requestNumber++) {
            $response = $this->postJson(route('tenant.chat.reset', $hospital->slug));

            if ($requestNumber <= 10) {
                $response->assertOk();
            } else {
                $response->assertTooManyRequests()->assertHeader('Retry-After');
            }
        }

        $this->assertDatabaseCount('conversations', 10);
    }
}
