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
        <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="font-bold text-slate-900">Appointment</h2>
            <dl class="mt-5 grid gap-x-8 gap-y-5 sm:grid-cols-2">
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

        <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="font-bold text-slate-900">M-Pesa payment</h2>
            <dl class="mt-4 grid gap-x-8 gap-y-4 text-sm sm:grid-cols-2">
                <div><dt class="text-slate-500">Payment status</dt><dd class="mt-1 font-medium capitalize text-slate-900">{{ $payment?->status ?: $appointment->payment_status }}</dd></div>
                <div><dt class="text-slate-500">Receipt</dt><dd class="mt-1 font-medium text-slate-900">{{ $payment?->mpesa_receipt ?: $appointment->mpesa_receipt ?: 'Not available' }}</dd></div>
                <div><dt class="text-slate-500">Phone</dt><dd class="mt-1 font-medium text-slate-900">{{ $payment?->phone ?: $appointment->mpesa_phone ?: 'Not available' }}</dd></div>
                <div><dt class="text-slate-500">Checkout request</dt><dd class="mt-1 break-all font-medium text-slate-900">{{ $payment?->checkout_request_id ?: $appointment->mpesa_checkout_request_id ?: 'Not available' }}</dd></div>
                <div><dt class="text-slate-500">Result</dt><dd class="mt-1 font-medium text-slate-900">{{ $payment?->result_description ?: $appointment->mpesa_result_description ?: 'Not available' }}</dd></div>
                <div><dt class="text-slate-500">Paid at</dt><dd class="mt-1 font-medium text-slate-900">{{ $payment?->processed_at?->format('M j, Y, g:i a') ?: $appointment->paid_at?->format('M j, Y, g:i a') ?: 'Not paid' }}</dd></div>
            </dl>
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
