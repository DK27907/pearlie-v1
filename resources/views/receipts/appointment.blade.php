<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        body { color: #1e293b; font-family: DejaVu Sans, sans-serif; font-size: 12px; line-height: 1.5; }
        h1 { color: #0c4a6e; font-size: 22px; margin: 20px 0 12px; }
        h2 { color: #0f172a; font-size: 14px; margin: 20px 0 8px; }
        .header, .footer { text-align: center; }
        .header img { max-height: 130px; max-width: 100%; }
        .footer img { max-height: 90px; max-width: 100%; }
        .receipt-meta, .details { border-collapse: collapse; width: 100%; }
        .receipt-meta td, .details td { border-bottom: 1px solid #e2e8f0; padding: 7px 4px; vertical-align: top; }
        .label { color: #64748b; width: 35%; }
        .notice { border: 1px solid #cbd5e1; margin-top: 20px; padding: 10px; }
        .footer { border-top: 1px solid #cbd5e1; margin-top: 32px; padding-top: 12px; }
    </style>
</head>
<body>
    <div class="header">
        @if ($letterheadDataUri)
            <img src="{{ $letterheadDataUri }}" alt="Hospital letterhead">
        @else
            <h1>{{ $hospital->name }}</h1>
            @if ($hospital->phone)<div>{{ $hospital->phone }}</div>@endif
            @if ($hospital->address)<div>{{ $hospital->address }}</div>@endif
        @endif
    </div>

    <h1>{{ $hospital->receipt_title ?: 'Payment Receipt' }}</h1>
    <table class="receipt-meta">
        <tr><td class="label">Receipt number</td><td>{{ $receipt->receipt_number }}</td></tr>
        <tr><td class="label">Issued</td><td>{{ $receipt->issued_at->format('d M Y, g:i A') }}</td></tr>
    </table>

    <h2>Patient and appointment</h2>
    <table class="details">
        <tr><td class="label">Booking reference</td><td>{{ $appointment->booking_reference ?: 'Not available' }}</td></tr>
        <tr><td class="label">Patient</td><td>{{ $appointment->name }}</td></tr>
        <tr><td class="label">Phone</td><td>{{ $appointment->phone }}</td></tr>
        <tr><td class="label">Service</td><td>{{ $appointment->service?->name ?? $appointment->reason }}</td></tr>
        <tr><td class="label">Doctor</td><td>{{ $appointment->doctor?->name ?? 'Not assigned' }}</td></tr>
        <tr><td class="label">Appointment</td><td>{{ $appointment->preferred_date?->format('d M Y') ?? 'Not provided' }} {{ $appointment->slot_start_time ? \Carbon\Carbon::parse($appointment->slot_start_time)->format('g:i A') : '' }}</td></tr>
    </table>

    <h2>Payment</h2>
    <table class="details">
        <tr><td class="label">Amount paid</td><td>KES {{ number_format((float) $receipt->amount, 2) }}</td></tr>
        @if ($mpesa)
            <tr><td class="label">Payment Method</td><td>M-Pesa</td></tr>
            <tr><td class="label">M-Pesa Receipt No.</td><td>{{ $mpesa->mpesa_receipt ?? 'Pending' }}</td></tr>
            <tr><td class="label">Checkout ID</td><td>{{ $mpesa->checkout_request_id }}</td></tr>
            <tr><td class="label">Phone</td><td>{{ $mpesa->phone }}</td></tr>
            <tr><td class="label">Transaction Date</td><td>{{ ($mpesa->processed_at ?? $mpesa->updated_at)->format('d M Y, g:i A') }}</td></tr>
        @else
            <tr><td class="label">Payment Method</td><td>Cash at hospital</td></tr>
            <tr><td class="label">Marked Paid By</td><td>{{ $receipt->issuedBy?->name ?? 'Front desk' }}</td></tr>
            <tr><td class="label">Payment Date</td><td>{{ $receipt->issued_at->format('d M Y, g:i A') }}</td></tr>
        @endif
        @if ($hospital->receipt_show_kra_pin && $hospital->receipt_kra_pin)
            <tr><td class="label">KRA PIN</td><td>{{ $hospital->receipt_kra_pin }}</td></tr>
        @endif
    </table>

    @if ($receipt->etims_status !== 'not_applicable')
        <section class="notice">
            <h2>eTIMS invoice</h2>
            <div>Status: {{ ucfirst(str_replace('_', ' ', $receipt->etims_status)) }}</div>
            @if ($receipt->etims_invoice_number)
                <div>KRA invoice number: {{ $receipt->etims_invoice_number }}</div>
            @endif
            @if ($receipt->etims_qr_data)
                <div>QR code data: {{ $receipt->etims_qr_data }}</div>
            @else
                <div>QR code: Pending eTIMS submission</div>
            @endif
            @if ($receipt->etims_submitted_at)
                <div>Submitted: {{ $receipt->etims_submitted_at->format('d M Y, g:i A') }}</div>
            @endif
        </section>
    @endif

    @if ($signatureDataUri)
        <div class="footer"><img src="{{ $signatureDataUri }}" alt="Authorized signature"></div>
    @endif
    <div class="footer">
        @if ($footerDataUri)
            <img src="{{ $footerDataUri }}" alt="Hospital receipt footer">
        @elseif ($hospital->receipt_footer_text)
            {{ $hospital->receipt_footer_text }}
        @endif
    </div>
</body>
</html>
