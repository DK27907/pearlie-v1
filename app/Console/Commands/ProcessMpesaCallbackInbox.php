<?php

namespace App\Console\Commands;

use App\Services\MpesaCallbackInboxService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:process-mpesa-callback-inbox {--limit=100}')]
#[Description('Process and retry durably stored M-Pesa callbacks')]
class ProcessMpesaCallbackInbox extends Command
{
    public function handle(MpesaCallbackInboxService $callbackInbox): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('The limit must be an integer between 1 and 500.');

            return self::FAILURE;
        }

        $processed = $callbackInbox->processDue($limit);
        $this->info("Processed {$processed} M-Pesa callback(s).");

        return self::SUCCESS;
    }
}
