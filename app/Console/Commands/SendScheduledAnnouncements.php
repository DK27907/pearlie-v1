<?php

namespace App\Console\Commands;

use App\Services\AnnouncementService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:send-scheduled-announcements')]
#[Description('Dispatch due hospital announcements')]
class SendScheduledAnnouncements extends Command
{
    public function handle(AnnouncementService $announcements): int
    {
        $result = $announcements->sendDueAnnouncements();
        $this->info("Processed {$result['processed']} scheduled announcements.");
        if ($result['failed'] > 0) {
            $this->error("{$result['failed']} scheduled announcements had delivery failures.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
