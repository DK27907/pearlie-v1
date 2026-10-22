<?php

namespace App\Console\Commands;

use App\Models\Hospital;
use App\Services\AnnouncementService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('appointments:send-reminders')]
#[Description('Send hospital appointment reminders for tomorrow')]
class SendAppointmentReminders extends Command
{
    public function handle(AnnouncementService $announcements): int
    {
        $hospitals = Hospital::query()->where('reminder_enabled', true)->orderBy('id')->get();
        $failed = false;
        foreach ($hospitals as $hospital) {
            $hadCurrentHospital = app()->bound('currentHospital');
            $previousHospital = $hadCurrentHospital ? app('currentHospital') : null;
            app()->instance('currentHospital', $hospital);

            try {
                foreach ($announcements->scheduleReminderAnnouncements($hospital) as $announcement) {
                    $announcements->sendAnnouncement($announcement);
                    $announcement->refresh();
                    if ($announcement->status === 'failed') {
                        $failed = true;
                        $this->error("Hospital {$hospital->id}: reminder announcement {$announcement->id} failed.");
                    } else {
                        $this->info("Hospital {$hospital->id}: reminder announcement {$announcement->id} processed.");
                    }
                }
            } catch (Throwable $exception) {
                $failed = true;
                Log::error('Hospital appointment reminders could not be processed.', [
                    'hospital_id' => $hospital->id,
                    'exception' => $exception,
                ]);
                $this->error("Hospital {$hospital->id}: reminder processing failed.");
            } finally {
                if ($hadCurrentHospital) {
                    app()->instance('currentHospital', $previousHospital);
                } else {
                    app()->forgetInstance('currentHospital');
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
