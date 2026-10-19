<?php

namespace App\Services;

use App\Models\Hospital;
use App\Models\User;
use App\Notifications\DailyDoctorSummary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class DoctorNotificationService
{
    public function __construct(private readonly HospitalMailService $mail) {}

    public function sendDailySummary(User $doctor): void
    {
        try {
            $previousHospital = hospital();
            // The notification's doctor record supplies the tenant ID for this scheduled operation.
            $hospital = Hospital::withoutGlobalScopes()->find($doctor->hospital_id);
            if (! $hospital) {
                throw new \RuntimeException('The doctor is not assigned to a hospital.');
            }
            app()->instance('currentHospital', $hospital);

            $date = CarbonImmutable::today();
            $appointments = $doctor->doctorAppointments()
                ->whereDate('preferred_date', $date)
                ->orderBy('slot_start_time')
                ->orderBy('id')
                ->get();

            $this->mail->runForHospital(
                (int) $hospital->id,
                fn () => $doctor->notify(new DailyDoctorSummary($appointments, $date->toDateString())),
            );
        } catch (Throwable $exception) {
            Log::error('Unable to send daily doctor summary.', [
                'doctor_id' => $doctor->id,
                'exception' => $exception,
            ]);

            throw $exception;
        } finally {
            app()->instance('currentHospital', $previousHospital ?? null);
        }
    }

    public function sendToAllDoctors(): void
    {
        try {
            $firstFailure = null;

            // This scheduled fan-out is system-wide; each doctor is rebound to their hospital above.
            User::withoutGlobalScope('hospital')
                ->where('is_doctor', true)
                ->orderBy('id')
                ->chunkById(100, function (Collection $doctors) use (&$firstFailure): void {
                    foreach ($doctors as $doctor) {
                        try {
                            $this->sendDailySummary($doctor);
                        } catch (Throwable $exception) {
                            $firstFailure ??= $exception;
                        }
                    }
                });

            if ($firstFailure !== null) {
                throw $firstFailure;
            }
        } catch (Throwable $exception) {
            Log::error('Unable to send daily summaries to all doctors.', [
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }
}
