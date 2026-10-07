<?php

namespace App\Console\Commands;

use App\Services\PaymentReconciliationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:reconcile-mpesa-payments')]
#[Description('Reconcile stale M-Pesa payments with Daraja')]
class ReconcileMpesaPayments extends Command
{
    public function handle(PaymentReconciliationService $reconciliation): int
    {
        $processed = $reconciliation->reconcile();
        $this->info("Reconciled {$processed} M-Pesa payment(s).");

        return self::SUCCESS;
    }
}
