<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('app:prune-payment-events {--days=365}')]
#[Description('Delete old payment events that are not linked to active payments or bookings')]
class PrunePaymentEvents extends Command
{
    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 3650) {
            $this->error('Days must be an integer between 1 and 3650.');

            return self::INVALID;
        }

        // Retention is global; pending-payment and appointment guards correlate on tenant ID.
        $deleted = DB::table('payment_events')
            ->where('created_at', '<', now()->subDays($days))
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('mpesa_payments')
                    ->whereColumn('mpesa_payments.id', 'payment_events.payment_id')
                    ->where(function ($payments): void {
                        $payments->whereIn('mpesa_payments.status', ['pending', 'initiated'])
                            ->orWhereExists(function ($appointments): void {
                                $appointments->selectRaw('1')
                                    ->from('appointment_requests')
                                    ->whereColumn('appointment_requests.id', 'mpesa_payments.appointment_request_id')
                                    ->whereColumn('appointment_requests.hospital_id', 'payment_events.hospital_id')
                                    ->whereIn('appointment_requests.status', ['pending', 'confirmed']);
                            });
                    });
            })
            ->delete();

        $this->info("Pruned {$deleted} old payment event(s).");

        return self::SUCCESS;
    }
}
