<?php

namespace App\Console\Commands;

use App\Models\AppointmentRequest;
use App\Services\AppointmentFollowUpService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('appointments:send-follow-ups')]
#[Description('Send feedback requests for completed appointments')]
class SendAppointmentFollowUps extends Command
{
    public function handle(AppointmentFollowUpService $followUps): int
    {
        $sent = 0;
        $failed = 0;

        AppointmentRequest::withoutGlobalScopes()
            ->select(['id', 'hospital_id'])
            ->where('status', AppointmentRequest::STATUS_COMPLETED)
            ->where('updated_at', '<=', now()->subHours(24))
            ->whereNull('feedback_requested_at')
            ->orderBy('id')
            ->chunkById(100, function ($appointments) use ($followUps, &$sent, &$failed): void {
                foreach ($appointments as $appointment) {
                    try {
                        $result = $followUps->sendIfDue(
                            (int) $appointment->id,
                            (int) $appointment->hospital_id,
                        );

                        if ($result === true) {
                            $sent++;
                        } elseif ($result === false) {
                            $failed++;
                            $this->error("Appointment {$appointment->id}: follow-up delivery failed.");
                        }
                    } catch (Throwable $exception) {
                        $failed++;
                        Log::error('Appointment follow-up could not be sent.', [
                            'appointment_id' => $appointment->id,
                            'hospital_id' => $appointment->hospital_id,
                            'exception' => $exception,
                        ]);
                        $this->error("Appointment {$appointment->id}: follow-up processing failed.");
                    }
                }
            });

        $this->info("Follow-ups sent: {$sent}; failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
