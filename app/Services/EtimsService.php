<?php

namespace App\Services;

use App\Models\Hospital;
use App\Models\Receipt;

class EtimsService
{
    public function isEnabled(Hospital $hospital): bool
    {
        return $hospital->etims_enabled;
    }

    public function submitReceipt(Receipt $receipt): EtimsResult
    {
        $hospital = $receipt->hospital;
        if (! $hospital || ! $this->isEnabled($hospital)) {
            return EtimsResult::notConfigured();
        }

        $result = EtimsResult::pending();
        $receipt->forceFill([
            'etims_status' => $result->status,
            'etims_invoice_number' => $result->invoiceNumber,
            'etims_qr_data' => $result->qrData,
        ])->save();

        return $result;
    }
}
