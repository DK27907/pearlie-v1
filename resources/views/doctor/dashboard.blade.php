@extends('layouts.app')

@section('header')
    <div>
        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-cyan-700">Doctor workspace</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Good day, {{ auth()->user()->name }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ now()->format('l, F j, Y') }} <span class="px-1 text-slate-300">/</span> {{ auth()->user()->specialization ?: 'Clinical team' }}</p>
    </div>
@endsection

@section('content')
    <div class="mx-auto max-w-7xl space-y-7 px-4 py-8 sm:px-6 lg:px-8">
        @if (session('status'))
            <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</div>
        @endif

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['label' => 'Patients today', 'value' => $today_count, 'detail' => 'Appointments scheduled for today', 'icon' => '👥'],
                ['label' => 'This week', 'value' => $week_count, 'detail' => 'Appointments across this week', 'icon' => '📅'],
                ['label' => 'Upcoming', 'value' => $upcoming_count, 'detail' => 'Pending or confirmed visits', 'icon' => '⏭'],
                ['label' => 'Open escalations', 'value' => $escalation_count, 'detail' => 'Patients waiting for a health worker', 'icon' => '⚠'],
            ] as $stat)
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-slate-500">{{ $stat['label'] }}</p>
                            <p class="mt-3 text-4xl font-bold tracking-tight text-slate-950">{{ $stat['value'] }}</p>
                            <p class="mt-2 text-xs text-slate-500">{{ $stat['detail'] }}</p>
                        </div>
                        <span aria-hidden="true" class="grid h-10 w-10 place-items-center rounded-xl bg-cyan-50 text-lg font-bold text-cyan-800">{{ $stat['icon'] }}</span>
                    </div>
                </article>
            @endforeach
        </section>
        <a href="{{ route('doctor.escalations.index', ['status' => 'pending']) }}" class="inline-flex items-center gap-2 rounded-lg bg-rose-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-rose-800">
            Open escalation queue <span aria-hidden="true">→</span>
        </a>

        <section class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_19rem]">
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                    <div>
                        <h2 class="font-bold text-slate-900">Today’s appointments</h2>
                        <p class="mt-1 text-sm text-slate-500">Your schedule for {{ now()->format('F j') }}</p>
                    </div>
                    <a href="{{ route('doctor.appointments', ['date' => now()->toDateString()]) }}" class="text-sm font-semibold text-cyan-800 hover:text-cyan-600">View schedule <span aria-hidden="true">→</span></a>
                </div>

                <div class="divide-y divide-slate-100">
                    @forelse ($today_appointments as $appointment)
                        <a href="{{ route('doctor.appointments.show', $appointment->id) }}" class="flex flex-col gap-3 border-l-4 border-cyan-500 bg-cyan-50/30 px-5 py-4 transition hover:bg-cyan-50 sm:flex-row sm:items-center">
                            <div class="w-20 shrink-0">
                                <p class="font-semibold text-slate-900">{{ $appointment->slot_start_time ? substr($appointment->slot_start_time, 0, 5) : 'Time TBC' }}</p>
                                <p class="text-xs text-slate-500">{{ $appointment->slot_end_time ? substr($appointment->slot_end_time, 0, 5) : '' }}</p>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-semibold text-slate-900">{{ $appointment->name ?: 'Patient name not provided' }}</p>
                                <p class="mt-1 truncate text-sm text-slate-500">{{ $appointment->reason ?: 'Appointment request' }}</p>
                            </div>
                            <span @class([
                                'w-fit rounded-full px-2.5 py-1 text-xs font-semibold capitalize',
                                'bg-amber-50 text-amber-800' => $appointment->status === 'pending',
                                'bg-cyan-50 text-cyan-800' => $appointment->status === 'confirmed',
                                'bg-emerald-50 text-emerald-800' => $appointment->status === 'completed',
                                'bg-slate-100 text-slate-600' => $appointment->status === 'cancelled',
                            ])>{{ $appointment->status }}</span>
                        </a>
                    @empty
                        <div class="px-5 py-12 text-center">
                            <p class="font-semibold text-slate-800">No appointments today</p>
                            <p class="mt-1 text-sm text-slate-500">New patient bookings will appear here.</p>
                        </div>
                    @endforelse
                </div>
            </div>

            <aside class="space-y-4">
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="font-bold text-slate-900">Open slots today</h2>
                            <p class="mt-1 text-sm text-slate-500">Available for new bookings</p>
                        </div>
                        <span class="grid h-10 min-w-10 place-items-center rounded-xl bg-emerald-50 px-2 text-sm font-bold text-emerald-800">{{ count(array_filter($today_available_slots)) }}</span>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @forelse (array_keys(array_filter($today_available_slots)) as $slot)
                            <span class="rounded-lg bg-slate-100 px-2.5 py-1.5 text-xs font-semibold text-slate-700">{{ $slot }}</span>
                        @empty
                            <p class="text-sm text-slate-500">No open slots for today.</p>
                        @endforelse
                    </div>
                    <a href="{{ route('doctor.availability') }}" class="mt-5 inline-flex text-sm font-semibold text-cyan-800 hover:text-cyan-600">Manage availability <span class="ml-1" aria-hidden="true">→</span></a>
                </div>

                <div class="rounded-2xl bg-slate-950 p-5 text-white shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-widest text-cyan-300">Your practice</p>
                    <p class="mt-2 text-lg font-bold">{{ auth()->user()->specialization ?: 'Doctor' }}</p>
                    <p class="mt-1 text-sm text-slate-300">{{ auth()->user()->phone ?: 'Add a phone number to your profile' }}</p>
                </div>
            </aside>
        </section>
    </div>
@endsection
