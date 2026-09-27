<?php

namespace App\Console\Commands;

use App\Services\NoShowService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('appointments:mark-no-shows')]
#[Description('Mark overdue paid appointments as no-shows and notify patients')]
class MarkNoShowAppointments extends Command
{
    public function handle(NoShowService $noShowService): int
    {
        $count = $noShowService->markOverdueAppointments();

        $this->info("Marked {$count} overdue appointment(s) as no-shows.");

        return self::SUCCESS;
    }
}
