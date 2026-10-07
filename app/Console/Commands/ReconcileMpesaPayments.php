<?php

namespace App\Console\Commands;

use App\Services\PaymentReconciliationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('app:reconcile-mpesa-payments')]
#[Description('Reconcile stale M-Pesa payments with Daraja')]
class ReconcileMpesaPayments extends Command
{
    public const HEARTBEAT_CACHE_KEY = 'mpesa:reconciliation:heartbeat';

    public function handle(PaymentReconciliationService $reconciliation): int
    {
        $processed = $reconciliation->reconcile();
        Cache::put(
            self::HEARTBEAT_CACHE_KEY,
            now()->timestamp,
            now()->addMinutes(15),
        );

        $this->info("Reconciled {$processed} M-Pesa payment(s).");

        return self::SUCCESS;
    }
}
