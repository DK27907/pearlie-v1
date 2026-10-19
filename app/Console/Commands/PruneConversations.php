<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('app:prune-conversations {--days=180}')]
#[Description('Delete old conversations that are not linked to active bookings or escalations')]
class PruneConversations extends Command
{
    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 3650) {
            $this->error('Days must be an integer between 1 and 3650.');

            return self::INVALID;
        }

        // Retention is global; the correlated active-work checks include each row's hospital ID.
        $deleted = DB::table('conversations')
            ->where('created_at', '<', now()->subDays($days))
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('appointment_requests')
                    ->whereColumn('appointment_requests.session_id', 'conversations.session_id')
                    ->whereColumn('appointment_requests.hospital_id', 'conversations.hospital_id')
                    ->whereIn('appointment_requests.status', ['pending', 'confirmed']);
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('escalations')
                    ->whereColumn('escalations.session_id', 'conversations.session_id')
                    ->whereColumn('escalations.hospital_id', 'conversations.hospital_id')
                    ->whereIn('escalations.status', ['pending', 'in_progress']);
            })
            ->delete();

        $this->info("Pruned {$deleted} old conversation(s).");

        return self::SUCCESS;
    }
}
