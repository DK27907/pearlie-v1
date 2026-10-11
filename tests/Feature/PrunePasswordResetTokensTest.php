<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrunePasswordResetTokensTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_only_password_reset_tokens_older_than_the_cutoff(): void
    {
        DB::table('password_reset_tokens')->insert([
            [
                'email' => 'fresh@example.test',
                'token' => 'fresh-token',
                'created_at' => now()->subMinutes(30),
            ],
            [
                'email' => 'expired@example.test',
                'token' => 'expired-token',
                'created_at' => now()->subMinutes(61),
            ],
        ]);

        $this->artisan('app:prune-password-reset-tokens', ['--minutes' => 60])
            ->expectsOutput('Pruned 1 expired password reset token(s).')
            ->assertSuccessful();

        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'fresh@example.test']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'expired@example.test']);
    }

    public function test_rejects_a_non_positive_retention_period(): void
    {
        $this->artisan('app:prune-password-reset-tokens', ['--minutes' => 0])
            ->expectsOutput('Minutes must be a positive integer.')
            ->assertFailed();
    }
}
