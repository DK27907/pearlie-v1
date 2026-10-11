@extends('layouts.app')

@section('title', 'Reports | '.pearlie_config('hospital.name'))

@section('content')
    @php($maxDaily = max(1, (int) ($dailyAppointments->max('total') ?? 1)))
    <div class="mx-auto max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm font-semibold uppercase tracking-widest text-sky-700">{{ pearlie_config('hospital.name') }}</p>
                <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-900">Reports</h1>
                <p class="mt-2 text-slate-600">Appointment activity and completed M-Pesa revenue for the selected period.</p>
            </div>
            <a href="{{ route('admin.reports.export', ['from' => $from->toDateString(), 'to' => $to->toDateString()]) }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-800 hover:bg-slate-50">Export CSV</a>
        </header>

        <form method="GET" action="{{ route('admin.reports.index') }}" class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-end">
            <label class="grid gap-1 text-sm font-semibold text-slate-700">From<input type="date" name="from" value="{{ $from->toDateString() }}" class="rounded-xl border-slate-300 font-normal"></label>
            <label class="grid gap-1 text-sm font-semibold text-slate-700">To<input type="date" name="to" value="{{ $to->toDateString() }}" class="rounded-xl border-slate-300 font-normal"></label>
            <button class="rounded-xl bg-slate-900 px-5 py-2.5 font-semibold text-white hover:bg-slate-700">Apply period</button>
        </form>

        <section class="grid gap-4 sm:grid-cols-2" aria-label="Report totals">
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm font-medium text-slate-600">Appointments</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ number_format($appointmentCount) }}</p></article>
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm font-medium text-slate-600">Completed M-Pesa revenue</p><p class="mt-2 text-3xl font-bold text-emerald-800">KSh {{ number_format($revenue, 2) }}</p></article>
        </section>

        <section class="grid gap-6 lg:grid-cols-2">
            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="font-bold text-slate-900">Appointments by status</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($statusTotals as $status => $total)
                        <div class="flex items-center justify-between gap-4"><span class="capitalize text-slate-600">{{ str_replace('_', ' ', $status) }}</span><span class="font-bold text-slate-900">{{ number_format($total) }}</span></div>
                    @empty
                        <p class="text-sm text-slate-600">No appointments in this period.</p>
                    @endforelse
                </div>
            </article>

            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="font-bold text-slate-900">Daily appointments</h2>
                <div class="mt-5 flex min-h-48 items-end gap-2 overflow-x-auto border-b border-slate-200 pb-2" role="img" aria-label="Daily appointment totals chart">
                    @forelse ($dailyAppointments as $day)
                        <div class="flex min-w-8 flex-col items-center justify-end gap-2 self-stretch">
                            <span class="text-xs font-semibold text-slate-700">{{ $day->total }}</span>
                            <span class="w-6 rounded-t bg-sky-700" style="height: {{ max(8, (int) round(($day->total / $maxDaily) * 100)) }}%"></span>
                            <span class="text-[10px] text-slate-500">{{ \Illuminate\Support\Carbon::parse($day->preferred_date)->format('M j') }}</span>
                        </div>
                    @empty
                        <p class="self-center text-sm text-slate-600">No appointments in this period.</p>
                    @endforelse
                </div>
            </article>
        </section>
    </div>
@endsection
