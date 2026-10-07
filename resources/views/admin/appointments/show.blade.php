@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Appointment #{{ $appt->id }}</h1>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card mb-3">
        <div class="card-body">
            <p><strong>Name:</strong> {{ $appt->name }}</p>
            <p><strong>Phone:</strong> {{ $appt->phone }}</p>
            <p><strong>Email:</strong> {{ $appt->email ?: 'Not provided' }}</p>
            <p><strong>Preferred Date:</strong> {{ $appt->preferred_date }}</p>
            <p><strong>Reason:</strong> {{ $appt->reason }}</p>
            <p><strong>Status:</strong> {{ $appt->status }}</p>
            @if ($repeatNoShowCount > 0)
                <p class="my-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
                    This patient has {{ $repeatNoShowCount }} previous no-show{{ $repeatNoShowCount === 1 ? '' : 's' }}.
                </p>
            @endif
            @if ($appt->isNoShow())
                <section class="my-4 rounded-lg border border-rose-200 bg-rose-50 p-4">
                    <h2 class="font-semibold text-rose-900">No-show record</h2>
                    <p class="mt-2 text-sm text-rose-800">Marked {{ $appt->marked_no_show_at?->format('M j, Y, g:i a') ?: 'at an unknown time' }}.</p>
                    <p class="mt-1 text-sm text-rose-800">Reason: {{ $appt->no_show_reason ?: 'No reason provided.' }}</p>
                </section>
            @endif

            @php
                $mpesaPayment = $appt->mpesaPayment;
                $paymentStatus = $appt->payment_status ?: 'unpaid';
                $paymentAmount = $appt->payment_amount ?? $mpesaPayment?->amount ?? $appt->booking_fee;
                $paymentReceipt = $appt->mpesa_receipt ?? $mpesaPayment?->mpesa_receipt;
                $checkoutRequestId = $mpesaPayment?->checkout_request_id ?? $appt->mpesa_checkout_request_id;
            @endphp
            <section class="my-5 rounded-xl border border-slate-200 bg-slate-50 p-4" aria-labelledby="payment-heading">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="payment-heading" class="font-semibold text-slate-900">M-Pesa payment</h2>
                    <span @class([
                        'rounded-full px-3 py-1 text-xs font-semibold capitalize',
                        'bg-emerald-100 text-emerald-800' => $paymentStatus === 'paid',
                        'bg-amber-100 text-amber-800' => $paymentStatus === 'pending',
                        'bg-rose-100 text-rose-800' => in_array($paymentStatus, ['failed', 'unpaid'], true),
                        'bg-slate-200 text-slate-700' => ! in_array($paymentStatus, ['paid', 'pending', 'failed', 'unpaid', 'refunded'], true),
                        'bg-violet-100 text-violet-800' => $paymentStatus === 'refunded',
                    ])>{{ $paymentStatus }}</span>
                </div>
                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-slate-500">Amount</dt>
                        <dd class="mt-1 font-medium text-slate-900">{{ $paymentAmount !== null ? 'KSh '.number_format((float) $paymentAmount, 2) : 'Not set' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Paid at</dt>
                        <dd class="mt-1 font-medium text-slate-900">{{ $appt->paid_at?->format('M j, Y g:i a') ?? 'Not paid' }}</dd>
                    </div>
                    @if ($paymentReceipt)
                        <div class="sm:col-span-2">
                            <dt class="text-slate-500">M-Pesa receipt</dt>
                            <dd class="mt-1 font-mono font-semibold text-slate-900">{{ $paymentReceipt }}</dd>
                        </div>
                    @endif
                </dl>

                @if ($checkoutRequestId)
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <button
                            type="button"
                            id="verify-mpesa-payment"
                            data-status-url="{{ url('/api/mpesa/status/'.rawurlencode($checkoutRequestId)).'?hospital='.rawurlencode(hospital()?->slug ?? 'pearl') }}"
                            class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-cyan-800 disabled:cursor-wait disabled:opacity-60"
                        >Verify Payment</button>
                        <p id="mpesa-verification-result" role="status" aria-live="polite" class="text-sm text-slate-600"></p>
                    </div>
                @endif
            </section>

            <form method="POST" action="{{ route('admin.appointments.updateStatus', $appt->id) }}">
                @csrf
                <div class="form-group">
                    <label for="status">Update status</label>
                    <select name="status" id="status" class="form-control">
                        @foreach (array_diff(\App\Models\AppointmentRequest::statuses(), [\App\Models\AppointmentRequest::STATUS_NO_SHOW]) as $status)
                            <option value="{{ $status }}" @selected($appt->status === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn btn-primary mt-2">Update</button>
            </form>

            <form method="POST" action="{{ route('admin.appointments.confirm', $appt->id) }}" style="display:inline">
                @csrf
                <button type="submit" class="btn btn-success mt-2">Confirm Appointment & Notify</button>
            </form>
            @if ($appt->status === 'confirmed' && $paymentStatus === 'paid')
                <form method="POST" action="{{ route('admin.appointments.no-show', $appt->id) }}" class="mt-4 grid gap-2 sm:max-w-md">
                    @csrf
                    <label for="no-show-reason" class="text-sm font-medium text-slate-700">No-show reason (optional)</label>
                    <input id="no-show-reason" name="reason" maxlength="255" class="rounded-lg border-slate-300 text-sm" placeholder="e.g. Patient did not arrive">
                    <button type="submit" class="rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-800">Mark No-Show</button>
                </form>
            @endif
        </div>
    </div>

    <a href="{{ route('admin.appointments.index') }}" class="btn btn-link">Back to list</a>
</div>

@if ($checkoutRequestId)
    <script>
        document.getElementById('verify-mpesa-payment')?.addEventListener('click', async (event) => {
            const button = event.currentTarget;
            const result = document.getElementById('mpesa-verification-result');
            button.disabled = true;
            result.textContent = 'Checking payment status…';

            try {
                const response = await fetch(button.dataset.statusUrl, {
                    headers: { Accept: 'application/json' },
                });
                const payload = await response.json();

                if (!response.ok || payload.success !== true) {
                    throw new Error(payload.message || 'Payment status could not be verified.');
                }

                result.textContent = payload.data.ResultDesc || payload.data.ResponseDescription || 'Payment status received.';
            } catch (error) {
                result.textContent = error.message || 'Payment status could not be verified.';
            } finally {
                button.disabled = false;
            }
        });
    </script>
@endif
@endsection
