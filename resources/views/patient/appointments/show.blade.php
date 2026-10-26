@extends('layouts.app')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <a href="{{ route('appointments.index') }}" class="text-sm font-semibold text-cyan-800 hover:text-cyan-600">← My appointments</a>
            <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Appointment details</h1>
            <p class="mt-1 text-sm text-slate-500">Request #{{ $appointment->id }}</p>
        </div>
        <span class="rounded-full bg-slate-100 px-3 py-1.5 text-sm font-semibold capitalize text-slate-700">{{ $appointment->status }}</span>
    </div>
@endsection

@section('content')
    @php
        $payment = $appointment->mpesaPayment;
        $amount = $appointment->payment_amount ?? $payment?->amount ?? $appointment->service?->price ?? $appointment->booking_fee ?? 0;
    @endphp
    <div class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        @if (session('status'))
            <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $errors->first() }}</div>
        @endif

        <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="font-bold text-slate-900">Appointment</h2>
            <dl class="mt-5 grid gap-x-8 gap-y-5 sm:grid-cols-2">
                <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Booking reference</dt><dd class="mt-1 font-mono font-medium text-slate-900">{{ $appointment->booking_reference ?: 'Not available' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Date and time</dt><dd class="mt-1 font-medium text-slate-900">{{ $appointment->preferred_date?->format('l, F j, Y') ?: 'Not set' }}{{ $appointment->slot_start_time ? ' · '.substr($appointment->slot_start_time, 0, 5) : '' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Status</dt><dd class="mt-1 font-medium capitalize text-slate-900">{{ $appointment->status }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Doctor</dt><dd class="mt-1 font-medium text-slate-900">{{ $appointment->doctor?->name ?: 'Not assigned' }}{{ $appointment->doctor?->specialization ? ' · '.$appointment->doctor->specialization : '' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Service</dt><dd class="mt-1 font-medium text-slate-900">{{ $appointment->service?->name ?: 'General appointment' }}</dd></div>
                @if ($appointment->doctor?->bio)
                    <div class="sm:col-span-2"><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Doctor profile</dt><dd class="mt-1 whitespace-pre-line text-slate-700">{{ $appointment->doctor->bio }}</dd></div>
                @endif
                <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Reason for visit</dt><dd class="mt-1 whitespace-pre-line text-slate-700">{{ $appointment->reason ?: 'Not provided' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Amount</dt><dd class="mt-1 font-medium text-slate-900">KSh {{ number_format((float) $amount, 2) }}</dd></div>
            </dl>
        </article>

        @if ($lifecyclePolicy->canCancel || $lifecyclePolicy->canReschedule)
            <article class="rounded-2xl border border-cyan-200 bg-cyan-50 p-6">
                <h2 class="font-bold text-slate-900">Change this appointment</h2>
                <p class="mt-2 text-sm text-slate-700">
                    Cancellation cutoff: {{ $lifecyclePolicy->cancellationCutoffAt->format('d/m/Y g:i a') }}.
                    Rescheduling is available until {{ $lifecyclePolicy->reschedulingCutoffAt->format('d/m/Y g:i a') }}.
                </p>
                @if ($lifecyclePolicy->isLateCancellation)
                    <p class="mt-2 text-sm text-amber-800">
                        The cancellation cutoff has passed. A fee of up to KSh {{ number_format($lifecyclePolicy->lateCancellationFee, 2) }} may be retained from a paid deposit.
                    </p>
                @endif
                <div class="mt-4 flex flex-wrap gap-3">
                    @if ($lifecyclePolicy->canReschedule)
                        <a href="{{ route('appointments.reschedule.create', $appointment->id) }}" class="rounded-lg bg-cyan-800 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-700">Reschedule</a>
                    @endif
                    @if ($lifecyclePolicy->canCancel)
                        <form method="POST" action="{{ route('appointments.cancel', $appointment->id) }}" class="flex flex-wrap items-center gap-2" data-confirm="Cancel this appointment?">
                            @csrf
                            <label class="sr-only" for="cancellation-reason">Cancellation reason</label>
                            <input id="cancellation-reason" name="reason" maxlength="1000" class="rounded-lg border-slate-300 text-sm" placeholder="Reason (optional)">
                            <button type="submit" class="rounded-lg border border-rose-300 bg-white px-4 py-2 text-sm font-semibold text-rose-800 hover:bg-rose-50">Cancel appointment</button>
                        </form>
                    @endif
                </div>
            </article>
        @endif

        <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="font-bold text-slate-900">M-Pesa payment</h2>
            <dl class="mt-4 grid gap-x-8 gap-y-4 text-sm sm:grid-cols-2">
                <div><dt class="text-slate-500">Payment status</dt><dd class="mt-1 font-medium capitalize text-slate-900">{{ $payment?->status ?: $appointment->payment_status }}</dd></div>
                @if ((float) $appointment->forfeited_amount > 0)
                    <div><dt class="text-slate-500">Deposit retained</dt><dd class="mt-1 font-medium text-slate-900">KSh {{ number_format((float) $appointment->forfeited_amount, 2) }}</dd></div>
                @endif
                <div><dt class="text-slate-500">Receipt</dt><dd class="mt-1 font-medium text-slate-900">{{ $payment?->mpesa_receipt ?: $appointment->mpesa_receipt ?: 'Not available' }}</dd></div>
                <div><dt class="text-slate-500">Phone</dt><dd class="mt-1 font-medium text-slate-900">{{ $payment?->phone ?: $appointment->mpesa_phone ?: 'Not available' }}</dd></div>
                <div><dt class="text-slate-500">Checkout request</dt><dd class="mt-1 break-all font-medium text-slate-900">{{ $payment?->checkout_request_id ?: $appointment->mpesa_checkout_request_id ?: 'Not available' }}</dd></div>
                <div><dt class="text-slate-500">Result</dt><dd class="mt-1 font-medium text-slate-900">{{ $payment?->result_description ?: $appointment->mpesa_result_description ?: 'Not available' }}</dd></div>
                <div><dt class="text-slate-500">Paid at</dt><dd class="mt-1 font-medium text-slate-900">{{ $payment?->processed_at?->format('M j, Y, g:i a') ?: $appointment->paid_at?->format('M j, Y, g:i a') ?: 'Not paid' }}</dd></div>
            </dl>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="font-bold text-slate-900">Appointment history</h2>
            @forelse ($appointment->histories as $history)
                <div class="mt-4 border-l-2 border-cyan-600 pl-4">
                    <p class="font-semibold capitalize text-slate-900">{{ str_replace('_', ' ', $history->action) }}</p>
                    <p class="mt-1 text-sm text-slate-700">
                        @if ($history->action === 'rescheduled')
                            {{ $history->old_date?->format('d/m/Y') }} at {{ $history->old_start_time ? substr((string) $history->old_start_time, 0, 5) : '—' }}
                            → {{ $history->new_date?->format('d/m/Y') }} at {{ $history->new_start_time ? substr((string) $history->new_start_time, 0, 5) : '—' }}
                        @else
                            {{ $history->reason ?: 'No reason provided.' }}
                        @endif
                    </p>
                    <p class="mt-1 text-xs text-slate-500">{{ $history->created_at?->format('M j, Y, g:i a') }}</p>
                </div>
            @empty
                <p class="mt-2 text-sm text-slate-500">No appointment changes have been recorded.</p>
            @endforelse
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="font-bold text-slate-900">Payment status history</h2>
            @forelse ($payment?->events ?? [] as $event)
                <div class="mt-4 border-l-2 border-cyan-600 pl-4">
                    <p class="font-semibold capitalize text-slate-900">{{ str_replace('_', ' ', $event->event) }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $event->created_at?->format('M j, Y, g:i a') }}</p>
                    @if ($event->payload)
                        <pre class="mt-2 overflow-x-auto whitespace-pre-wrap text-xs text-slate-600">{{ json_encode($event->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    @endif
                </div>
            @empty
                <p class="mt-2 text-sm text-slate-500">No payment events have been recorded.</p>
            @endforelse
        </article>
    </div>
@endsection
