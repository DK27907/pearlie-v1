<?php

namespace App\Console\Commands;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\SlotHold;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('app:cleanup-slot-holds')]
#[Description('Expire abandoned appointment slot holds')]
class CleanupSlotHolds extends Command
{
    public function handle(): int
    {
        $deleted = 0;
        $hadCurrentHospital = app()->bound('currentHospital');
        $previousHospital = $hadCurrentHospital ? app('currentHospital') : null;

        try {
            Hospital::withoutGlobalScopes()
                ->orderBy('id')
                ->chunkById(100, function ($hospitals) use (&$deleted): void {
                    foreach ($hospitals as $hospital) {
                        app()->instance('currentHospital', $hospital);

                        SlotHold::query()
                            ->where('expires_at', '<=', now())
                            ->orderBy('id')
                            ->chunkById(100, function ($holds) use (&$deleted): void {
                                foreach ($holds as $staleHold) {
                                    $deleted += DB::transaction(function () use ($staleHold): int {
                                        $hold = SlotHold::query()
                                            ->whereKey($staleHold->id)
                                            ->lockForUpdate()
                                            ->first();

                                        if (! $hold || $hold->expires_at->isFuture()) {
                                            return 0;
                                        }

                                        $appointment = $hold->appointment;
                                        if ($appointment?->status === AppointmentRequest::STATUS_PENDING) {
                                            $appointment->forceFill([
                                                'status' => AppointmentRequest::STATUS_EXPIRED,
                                                'status_updated_at' => now(),
                                            ])->save();
                                        }

                                        $hold->delete();

                                        return 1;
                                    });
                                }
                            });
                    }
                });

            $this->info("Expired {$deleted} slot hold(s).");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('Unable to clean up expired appointment slot holds.', [
                'exception' => $exception,
            ]);

            $this->error('Unable to clean up expired appointment slot holds.');

            return self::FAILURE;
        } finally {
            if ($hadCurrentHospital) {
                app()->instance('currentHospital', $previousHospital);
            } else {
                app()->forgetInstance('currentHospital');
            }
        }
    }
}
