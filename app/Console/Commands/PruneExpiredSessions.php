<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('session:prune')]
#[Description('Delete expired database sessions')]
class PruneExpiredSessions extends Command
{
    public function handle(): int
    {
        $lifetime = (int) config('session.lifetime');
        $table = (string) config('session.table');

        if ($lifetime < 1 || $table === '') {
            $this->error('Session lifetime and table configuration must be valid.');

            return self::INVALID;
        }

        $cutoff = now()->subMinutes($lifetime)->getTimestamp();
        $deleted = DB::table($table)
            ->where('last_activity', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} expired session(s).");

        return self::SUCCESS;
    }
}
