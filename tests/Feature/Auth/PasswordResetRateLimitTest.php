<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_link_returns_429_with_retry_after_after_ten_requests(): void
    {
        Notification::fake();

        for ($requestNumber = 1; $requestNumber <= 11; $requestNumber++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.63'])
                ->post(route('password.email'), ['email' => 'unknown@example.test']);

            if ($requestNumber <= 10) {
                $response->assertRedirect();
            } else {
                $response->assertTooManyRequests()->assertHeader('Retry-After');
            }
        }

        $this->assertDatabaseCount('password_reset_tokens', 0);
    }
}
