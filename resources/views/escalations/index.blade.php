@extends('layouts.app')

@section('content')
    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <header class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div>
                <p class="text-sm font-semibold uppercase tracking-widest text-rose-700">Human support</p>
                <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-950">Escalation queue</h1>
                <p class="mt-2 text-sm text-slate-600">Review patient context, claim conversations, and follow up.</p>
            </div>
            @if ($queueRoutePrefix === 'admin')
                <a href="{{ route('admin.escalations.exportCsv') }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:border-rose-300">Export CSV</a>
            @endif
        </header>

        @if (session('status'))
            <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800">{{ session('error') }}</div>
        @endif

        <form method="GET" action="{{ route($queueRoutePrefix.'.escalations.index') }}" class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row">
            <label class="sr-only" for="escalation-search">Search by patient name, phone, or question</label>
            <input id="escalation-search" type="search" name="q" maxlength="255" value="{{ request('q') }}" placeholder="Search name, phone, or question" class="min-w-0 flex-1 rounded-lg border-slate-200 text-sm focus:border-rose-500 focus:ring-rose-500">
            <label class="sr-only" for="escalation-status">Filter by status</label>
            <select id="escalation-status" name="status" class="rounded-lg border-slate-200 text-sm focus:border-rose-500 focus:ring-rose-500">
                @foreach ([
                    'all' => 'All statuses',
                    'pending' => 'Pending',
                    'in_progress' => 'In progress',
                    'resolved' => 'Resolved',
                ] as $status => $label)
                    <option value="{{ $status }}" @selected(request('status', 'all') === $status)>{{ $label }}</option>
                @endforeach
            </select>
            <button type="submit" class="rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white hover:bg-rose-800">Filter queue</button>
        </form>

        <div
            x-data="escalationQueue"
            data-refresh-url="{{ request()->fullUrl() }}"
            class="space-y-3"
        >
            <div class="flex items-center justify-between gap-3 text-xs text-slate-500">
                <p>Queue refreshes every 15 seconds.</p>
                <p role="status" aria-live="polite" data-queue-refresh-status></p>
            </div>
            @include('escalations.queue')
        </div>
    </div>
@endsection
