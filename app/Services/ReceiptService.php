<?php

namespace App\Services;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\MpesaPayment;
use App\Models\Receipt;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ReceiptService
{
    public function generateForAppointment(
        AppointmentRequest $appointment,
        ?MpesaPayment $mpesa = null,
        ?User $cashier = null,
    ): Receipt {
        $hospital = Hospital::query()->findOrFail($appointment->hospital_id);
        $receipt = DB::transaction(function () use ($appointment, $mpesa, $cashier, $hospital): Receipt {
            $lockedHospital = Hospital::query()->whereKey($hospital->id)->lockForUpdate()->firstOrFail();
            $existingReceipt = Receipt::query()
                ->where('hospital_id', $lockedHospital->id)
                ->where('appointment_request_id', $appointment->id)
                ->first();

            if ($existingReceipt) {
                return $existingReceipt;
            }

            $issuedAt = now();
            $prefix = preg_replace('/[^A-Za-z0-9_-]/', '', trim((string) $lockedHospital->receipt_prefix)) ?: 'RCPT';
            $sequence = Receipt::query()
                ->where('hospital_id', $lockedHospital->id)
                ->whereYear('issued_at', $issuedAt->year)
                ->count() + 1;
            $amount = $appointment->payment_collected_amount
                ?? $appointment->payment_amount
                ?? $appointment->booking_fee;

            return Receipt::query()->create([
                'hospital_id' => $lockedHospital->id,
                'appointment_request_id' => $appointment->id,
                'mpesa_payment_id' => $mpesa?->id,
                'receipt_number' => sprintf('%s-%d-%06d', $prefix, $issuedAt->year, $sequence),
                'amount' => $amount,
                'payment_method' => $appointment->payment_method ?? ($mpesa ? 'mpesa' : 'cash'),
                'issued_at' => $issuedAt,
                'issued_by_user_id' => $cashier?->id,
                'etims_status' => $lockedHospital->etims_enabled ? 'pending' : 'not_applicable',
            ]);
        });

        return $this->generatePdf($receipt->fresh(['hospital', 'appointment.doctor', 'appointment.service', 'mpesaPayment']));
    }

    public function generatePdf(Receipt $receipt): Receipt
    {
        if ($receipt->hasPdf()) {
            return $receipt;
        }

        $receipt->loadMissing(['hospital', 'appointment.doctor', 'appointment.service', 'mpesaPayment']);
        $hospital = $receipt->hospital;
        if (! $hospital) {
            throw new RuntimeException('A hospital is required to render a receipt.');
        }

        $pdf = Pdf::loadView('receipts.appointment', [
            'receipt' => $receipt,
            'hospital' => $hospital,
            'appointment' => $receipt->appointment,
            'mpesa' => $receipt->mpesaPayment,
            'letterheadDataUri' => $this->imageDataUri($hospital->receipt_letterhead_path),
            'footerDataUri' => $this->imageDataUri($hospital->receipt_footer_path),
            'signatureDataUri' => $this->imageDataUri($hospital->receipt_signature_path),
        ])->setPaper('a4');
        $path = sprintf('receipts/%d/%s.pdf', $hospital->id, $receipt->receipt_number);

        if (! Storage::put($path, $pdf->output())) {
            throw new RuntimeException('The receipt PDF could not be saved to storage.');
        }

        $receipt->forceFill(['pdf_path' => $path])->save();

        return $receipt->fresh();
    }

    private function imageDataUri(?string $path): ?string
    {
        if (blank($path) || ! Storage::exists($path)) {
            return null;
        }

        $mimeType = Storage::mimeType($path) ?: 'application/octet-stream';

        return 'data:'.$mimeType.';base64,'.base64_encode(Storage::get($path));
    }
}
