<?php

namespace App\Console\Commands;

use App\Models\NotificationLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:prune-notification-logs {--days=30} {--status=failed}')]
#[Description('Prune old non-sent notification logs')]
class PruneNotificationLogs extends Command
{
    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        $status = (string) $this->option('status');
        $allowedStatuses = ['all', 'failed', 'not_configured', 'pending'];

        if ($days === false || $days < 1 || ! in_array($status, $allowedStatuses, true)) {
            $this->error('Days must be a positive integer and status must be all, failed, not_configured, or pending.');

            return self::INVALID;
        }

        $query = NotificationLog::query()
            ->where('created_at', '<', now()->subDays($days));

        if ($status === 'all') {
            $query->where('status', '!=', 'sent');
        } else {
            $query->where('status', $status);
        }

        $deleted = $query->delete();

        $this->info("Pruned {$deleted} notification log(s).");

        return self::SUCCESS;
    }
}
