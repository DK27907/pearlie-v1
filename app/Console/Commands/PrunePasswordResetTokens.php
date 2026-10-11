<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('app:prune-password-reset-tokens {--minutes=60}')]
#[Description('Delete expired password reset tokens')]
class PrunePasswordResetTokens extends Command
{
    public function handle(): int
    {
        $minutes = filter_var($this->option('minutes'), FILTER_VALIDATE_INT);

        if ($minutes === false || $minutes < 1) {
            $this->error('Minutes must be a positive integer.');

            return self::INVALID;
        }

        $deleted = DB::table('password_reset_tokens')
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->delete();

        $this->info("Pruned {$deleted} expired password reset token(s).");

        return self::SUCCESS;
    }
}
