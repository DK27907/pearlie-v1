@extends('layouts.app')

@section('header')
    <div>
        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">Walk-in booking</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Appointment reserved</h1>
        <p class="mt-1 text-sm text-slate-500">The assigned doctor can see this appointment on their dashboard.</p>
    </div>
@endsection

@section('content')
    <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <section class="rounded-2xl border border-emerald-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold text-emerald-800">Booking reference</p>
                    <p id="booking-reference" class="mt-2 break-all font-mono text-2xl font-bold tracking-wide text-slate-950 sm:text-3xl">{{ $appointment->booking_reference }}</p>
                </div>
                <button id="copy-reference" type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Copy reference</button>
                <span id="copy-status" role="status" aria-live="polite" class="sr-only"></span>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="font-bold text-slate-900">Appointment details</h2>
            <dl class="mt-5 grid gap-x-8 gap-y-5 sm:grid-cols-2">
                <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Service</dt><dd class="mt-1 font-medium text-slate-900">{{ $appointment->service?->name ?: $appointment->reason }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Doctor</dt><dd class="mt-1 font-medium text-slate-900">{{ $appointment->doctor?->name ?: 'Not assigned' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Date</dt><dd class="mt-1 font-medium text-slate-900">{{ $appointment->preferred_date?->format('l, F j, Y') ?: 'Not set' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Time</dt><dd class="mt-1 font-medium text-slate-900">{{ $appointment->slot_start_time ? \Carbon\CarbonImmutable::parse($appointment->slot_start_time)->format('g:i A') : 'Not set' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Patient</dt><dd class="mt-1 font-medium text-slate-900">{{ $appointment->name }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Phone</dt><dd class="mt-1 font-medium text-slate-900">{{ $appointment->phone }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Payment status</dt><dd class="mt-1 font-medium capitalize text-slate-900">{{ str_replace('_', ' ', $appointment->payment_status) }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Payment method</dt><dd class="mt-1 font-medium capitalize text-slate-900">{{ $appointment->payment_method ?: 'Not required' }}</dd></div>
            </dl>
        </section>

        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('admin.appointments.show', $appointment->id) }}" class="rounded-lg bg-cyan-800 px-4 py-2.5 text-sm font-semibold text-white hover:bg-cyan-900">View appointment</a>
            @if ($appointment->payment_method === 'cash' && $appointment->payment_status === 'pending')
                <form method="POST" action="{{ route('admin.walk-in.mark-paid', $appointment) }}">
                    @csrf
                    <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">Mark paid on arrival</button>
                </form>
            @endif
            @if ($appointment->receipt)
                <a href="{{ $appointment->receipt->signedPrintUrl() }}" class="rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Print receipt</a>
            @endif
            <a href="{{ route('admin.walk-in.create') }}" class="rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Create another walk-in</a>
            <a href="{{ route('admin.appointments.index') }}" class="rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Back to appointments</a>
        </div>
    </div>
@endsection

@push('scripts')
    <script nonce="{{ Vite::cspNonce() }}">
        document.getElementById('copy-reference').addEventListener('click', async () => {
            const reference = document.getElementById('booking-reference').textContent.trim();
            const status = document.getElementById('copy-status');

            try {
                await navigator.clipboard.writeText(reference);
                status.textContent = 'Booking reference copied.';
            } catch (error) {
                status.textContent = 'Copy failed. Select the reference and copy it manually.';
            }
            status.classList.remove('sr-only');
        });
    </script>
@endpush
