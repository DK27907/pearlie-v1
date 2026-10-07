<?php

namespace App\Console\Commands;

use App\Services\DoctorNotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('doctor:daily-summary')]
#[Description('Send daily appointment summaries to doctors')]
class SendDailyDoctorSummaries extends Command
{
    public function handle(DoctorNotificationService $notificationService): int
    {
        $notificationService->sendToAllDoctors();

        $this->info('Daily doctor summaries sent.');

        return self::SUCCESS;
    }
}
