@extends('layouts.app')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <a href="{{ route('doctor.appointments') }}" class="text-sm font-semibold text-cyan-800 hover:text-cyan-600">← All appointments</a>
            <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Appointment details</h1>
            <p class="mt-1 text-sm text-slate-500">Request #{{ $appointment->id }}</p>
        </div>
        <span @class([
            'rounded-full px-3 py-1.5 text-sm font-semibold capitalize',
            'bg-amber-50 text-amber-800' => $appointment->status === 'pending',
            'bg-cyan-50 text-cyan-800' => $appointment->status === 'confirmed',
            'bg-emerald-50 text-emerald-800' => $appointment->status === 'completed',
            'bg-rose-50 text-rose-800' => $appointment->status === 'no_show',
            'bg-orange-50 text-orange-800' => $appointment->status === 'expired',
            'bg-slate-100 text-slate-600' => $appointment->status === 'cancelled',
        ])>{{ $appointment->status }}</span>
    </div>
@endsection

@section('content')
    <div class="mx-auto grid max-w-7xl gap-6 px-4 py-8 sm:px-6 lg:px-8 lg:grid-cols-[minmax(0,1fr)_18rem]">
        @if (session('status'))
            <div role="status" class="lg:col-span-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</div>
        @endif

        <section class="space-y-6">
            <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="font-bold text-slate-900">Patient information</h2>
                <dl class="mt-5 grid gap-x-8 gap-y-5 sm:grid-cols-2">
                    <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Full name</dt><dd class="mt-1 font-medium text-slate-900">{{ $appointment->name ?: 'Not provided' }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Phone number</dt><dd class="mt-1 font-medium text-slate-900">{{ $appointment->phone ?: 'Not provided' }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Email address</dt><dd class="mt-1 font-medium text-slate-900">{{ $appointment->email ?: 'Not provided' }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Appointment date</dt><dd class="mt-1 font-medium text-slate-900">{{ $appointment->preferred_date?->format('l, F j, Y') ?: 'Not set' }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Appointment time</dt><dd class="mt-1 font-medium text-slate-900">{{ $appointment->slot_start_time ? substr($appointment->slot_start_time, 0, 5).' – '.($appointment->slot_end_time ? substr($appointment->slot_end_time, 0, 5) : '') : 'To be arranged' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Reason for visit</dt><dd class="mt-1 whitespace-pre-line text-slate-700">{{ $appointment->reason ?: 'No reason provided.' }}</dd></div>
                </dl>
            </article>

            @if ($appointment->isNoShow())
                <article class="rounded-2xl border border-rose-200 bg-rose-50 p-6">
                    <h2 class="font-bold text-rose-900">No-show record</h2>
                    <p class="mt-2 text-sm text-rose-800">Marked {{ $appointment->marked_no_show_at?->format('M j, Y, g:i a') ?: 'at an unknown time' }}.</p>
                    <p class="mt-1 text-sm text-rose-800">Reason: {{ $appointment->no_show_reason ?: 'No reason provided.' }}</p>
                </article>
            @endif

            @if ($repeatNoShowCount > 0)
                <p class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
                    This patient has {{ $repeatNoShowCount }} previous no-show{{ $repeatNoShowCount === 1 ? '' : 's' }}.
                </p>
            @endif

            <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="font-bold text-slate-900">Request history</h2>
                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">Received</dt><dd class="mt-1 font-medium text-slate-800">{{ $appointment->created_at?->format('M j, Y, g:i a') }}</dd></div>
                    <div><dt class="text-slate-500">Confirmed by you</dt><dd class="mt-1 font-medium text-slate-800">{{ $appointment->confirmed_by_doctor_at?->format('M j, Y, g:i a') ?: 'Not confirmed' }}</dd></div>
                </dl>
            </article>
        </section>

        <aside class="h-fit rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="font-bold text-slate-900">Next action</h2>
            <p class="mt-2 text-sm leading-6 text-slate-500">Keep the patient’s appointment status up to date.</p>
            <div class="mt-5 grid gap-3">
                @if ($appointment->status === 'pending')
                    <form method="POST" action="{{ route('doctor.appointments.confirm', $appointment->id) }}">
                        @csrf
                        <button type="submit" class="w-full rounded-lg bg-cyan-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-cyan-800">Confirm appointment</button>
                    </form>
                @endif
                @if ($appointment->status === 'confirmed')
                    <form method="POST" action="{{ route('doctor.appointments.complete', $appointment->id) }}">
                        @csrf
                        <button type="submit" class="w-full rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-800">Mark completed</button>
                    </form>
                @endif
                @if ($appointment->status === 'confirmed' && $appointment->payment_status === 'paid')
                    <form method="POST" action="{{ route('doctor.appointments.no-show', $appointment->id) }}">
                        @csrf
                        <label class="mb-2 block text-xs font-medium text-slate-600" for="no-show-reason">Reason (optional)</label>
                        <input id="no-show-reason" name="reason" maxlength="255" class="mb-2 w-full rounded-lg border-slate-300 text-sm" placeholder="e.g. Patient did not arrive">
                        <button type="submit" class="w-full rounded-lg bg-rose-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-rose-800">Mark No-Show</button>
                    </form>
                @endif
                @if (! in_array($appointment->status, ['pending', 'confirmed'], true))
                    <p class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">No further actions are available for this appointment.</p>
                @endif
            </div>
        </aside>
    </div>
@endsection
