@extends('layouts.app')

@section('content')
    <div class="mx-auto max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] space-y-8 px-4 py-8 sm:px-6 lg:px-8">
        <header>
            <p class="text-sm font-semibold uppercase tracking-widest text-[#1a5276]">{{ pearlie_config('hospital.name') }}</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-[#0a2f44]">Admin dashboard</h1>
            <p class="mt-2 text-sm text-slate-600">A current overview of patient conversations, appointments, and follow-up.</p>
        </header>

        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 3xl:grid-cols-4 5xl:grid-cols-6" aria-label="Hospital statistics">
            @foreach ([
                ['Total conversations', $total_conversations, 'text-[#1a5276]', '💬'],
                ['Pending escalations', $pending_escalations, 'text-rose-700', '⚠'],
                ['Pending appointments', $pending_appointments, 'text-amber-700', '📅'],
                ['Paid appointments today', $paid_appointments_today, 'text-emerald-700', '✓'],
                ['No-shows this week', $no_shows_this_week, 'text-rose-700', '↗'],
                ['Active doctors', $total_doctors, 'text-[#1a5276]', '✚'],
            ] as [$label, $value, $color, $icon])
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-slate-600">{{ $label }}</p>
                            <p class="mt-3 text-3xl font-bold {{ $color }}">{{ number_format($value) }}</p>
                        </div>
                        <span aria-hidden="true" class="grid h-10 w-10 place-items-center rounded-xl bg-slate-100 text-lg {{ $color }}">{{ $icon }}</span>
                    </div>
                </article>
            @endforeach
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="language-distribution-heading">
            @php
                $languageTotal = $language_breakdown['english'] + $language_breakdown['swahili'];
                $englishWidth = $languageTotal > 0 ? round($language_breakdown['english'] / $languageTotal * 100) : 0;
                $swahiliWidth = $languageTotal > 0 ? round($language_breakdown['swahili'] / $languageTotal * 100) : 0;
            @endphp
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 id="language-distribution-heading" class="font-bold text-slate-900">Language Distribution (This Month)</h2>
                    <p class="mt-2 text-sm text-slate-600">{{ number_format($language_breakdown['english']) }} English | {{ number_format($language_breakdown['swahili']) }} Swahili ({{ $language_breakdown['swahili_percent'] }}%)</p>
                </div>
                @if ($language_breakdown['swahili_percent'] > 20)
                    <p class="rounded-lg bg-teal-50 px-3 py-2 text-sm text-teal-900">Consider adding a Swahili-speaking receptionist.</p>
                @endif
            </div>
            <div class="mt-5 grid gap-3">
                <div class="flex items-center gap-3 text-xs font-medium text-slate-600"><span class="w-16">English</span><div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-cyan-600" style="width: {{ $englishWidth }}%"></div></div></div>
                <div class="flex items-center gap-3 text-xs font-medium text-slate-600"><span class="w-16">Swahili</span><div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-teal-600" style="width: {{ $swahiliWidth }}%"></div></div></div>
            </div>
        </section>

        <section class="grid gap-6 lg:grid-cols-2">
            <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between gap-4 border-b border-slate-100 p-5">
                    <h2 class="font-bold text-slate-900">Recent escalations</h2>
                    <a href="{{ route('admin.escalations.index') }}" class="text-sm font-semibold text-[#1a5276] hover:underline">View all</a>
                </div>
                <div class="divide-y divide-slate-100">
                    @forelse ($recent_escalations as $escalation)
                        <a href="{{ route('admin.escalations.show', $escalation->id) }}" class="block p-4 transition hover:bg-slate-50">
                            <div class="flex items-center justify-between gap-3">
                                <span class="font-semibold text-slate-800">Escalation #{{ $escalation->id }}</span>
                                <span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800">{{ ucfirst(str_replace('_', ' ', $escalation->status)) }}</span>
                            </div>
                            <p class="mt-1 text-sm text-slate-600">{{ \Illuminate\Support\Str::limit($escalation->user_message, 100) }}</p>
                        </a>
                    @empty
                        <p class="p-5 text-sm text-slate-500">No escalations yet.</p>
                    @endforelse
                </div>
            </article>

            <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between gap-4 border-b border-slate-100 p-5">
                    <h2 class="font-bold text-slate-900">Recent appointments</h2>
                    <a href="{{ route('admin.appointments.index') }}" class="text-sm font-semibold text-[#1a5276] hover:underline">View all</a>
                </div>
                <div class="divide-y divide-slate-100">
                    @forelse ($recent_appointments as $appointment)
                        <a href="{{ route('admin.appointments.show', $appointment->id) }}" class="block p-4 transition hover:bg-slate-50">
                            <div class="flex items-center justify-between gap-3">
                                <span class="font-semibold text-slate-800">{{ $appointment->name ?: 'Patient' }} · #{{ $appointment->id }}</span>
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold capitalize text-slate-700">{{ str_replace('_', ' ', $appointment->status) }}</span>
                            </div>
                            <p class="mt-1 text-sm text-slate-600">{{ $appointment->preferred_date?->format('M j, Y') ?: 'Date not set' }} · {{ $appointment->phone ?: 'No phone' }}</p>
                        </a>
                    @empty
                        <p class="p-5 text-sm text-slate-500">No appointment requests yet.</p>
                    @endforelse
                </div>
            </article>
        </section>

        <section class="overflow-hidden rounded-2xl border border-rose-200 bg-white shadow-sm">
            <div class="border-b border-rose-100 bg-rose-50 p-5">
                <h2 class="font-bold text-rose-900">Pending no-shows</h2>
                <p class="mt-1 text-sm text-rose-800">Paid appointments that have passed the configured grace period.</p>
            </div>
            <div class="divide-y divide-slate-100">
                @forelse ($pending_no_shows as $appointment)
                    <a href="{{ route('admin.appointments.show', $appointment->id) }}" class="flex flex-wrap items-center justify-between gap-2 p-4 hover:bg-slate-50">
                        <span class="font-semibold text-slate-800">{{ $appointment->name ?: 'Patient' }} · #{{ $appointment->id }}</span>
                        <span class="text-sm text-slate-600">{{ $appointment->slot_end_time ? substr($appointment->slot_end_time, 0, 5) : '' }} · {{ $appointment->phone }}</span>
                    </a>
                @empty
                    <p class="p-5 text-sm text-slate-500">No appointments are currently overdue.</p>
                @endforelse
            </div>
        </section>
    </div>
@endsection
