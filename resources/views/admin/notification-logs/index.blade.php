@extends('layouts.app')

@section('title', 'Notification logs | '.pearlie_config('hospital.name'))

@section('content')
    <div class="mx-auto max-w-screen-2xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <header>
            <p class="text-sm font-semibold uppercase tracking-widest text-sky-700">{{ pearlie_config('hospital.name') }}</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-900">Notification logs</h1>
            <p class="mt-2 text-slate-600">Delivery status for this hospital. Provider response content is redacted.</p>
        </header>

        <form method="GET" class="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <label class="grid gap-1 text-sm font-medium text-slate-700">
                Status
                <input name="status" value="{{ $filters['status'] ?? '' }}" maxlength="32" class="rounded-lg border-slate-300 text-sm">
            </label>
            <label class="grid gap-1 text-sm font-medium text-slate-700">
                Channel
                <input name="channel" value="{{ $filters['channel'] ?? '' }}" maxlength="32" class="rounded-lg border-slate-300 text-sm">
            </label>
            <label class="grid gap-1 text-sm font-medium text-slate-700">
                Provider
                <input name="provider" value="{{ $filters['provider'] ?? '' }}" maxlength="64" class="rounded-lg border-slate-300 text-sm">
            </label>
            <button class="rounded-lg bg-slate-950 px-5 py-2 text-sm font-semibold text-white hover:bg-sky-700">Filter</button>
        </form>

        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Created</th>
                        <th class="px-4 py-3">Channel / provider</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">HTTP / failure</th>
                        <th class="px-4 py-3">Provider response (redacted)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($logs as $log)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-4 text-slate-600">{{ $log->created_at?->format('M j, Y g:i A') }}</td>
                            <td class="px-4 py-4"><span class="font-semibold text-slate-900">{{ $log->channel }}</span><span class="block text-xs text-slate-500">{{ $log->provider }}</span></td>
                            <td class="px-4 py-4">{{ str_replace('_', ' ', $log->status) }}</td>
                            <td class="px-4 py-4 text-slate-600">{{ $log->http_status ?: '—' }}<span class="block text-xs">{{ $log->failure_code ?: '—' }}</span></td>
                            <td class="max-w-xl whitespace-pre-wrap break-words px-4 py-4 font-mono text-xs text-slate-600">{{ $log->scrubbed_provider_response ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-slate-600">No notification logs have been recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $logs->links() }}
    </div>
@endsection
