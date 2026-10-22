<?php

namespace App\Http\Controllers;

use App\Models\Receipt;
use App\Services\ReceiptService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class ReceiptController extends Controller
{
    public function download(Receipt $receipt, ReceiptService $receipts): Response
    {
        if (! $receipt->hasPdf()) {
            $receipt = $receipts->generatePdf($receipt);
        }

        return response(Storage::get($receipt->pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$receipt->receipt_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
