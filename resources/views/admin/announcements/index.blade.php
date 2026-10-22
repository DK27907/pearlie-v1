@extends('layouts.app')

@section('title', 'Announcements')

@section('content')
    <div class="mx-auto max-w-6xl space-y-6">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-slate-900">Announcements</h1>
                <p class="mt-2 text-sm text-slate-600">Review SMS and WhatsApp announcements for this hospital.</p>
            </div>
            <a href="{{ route('admin.announcements.create') }}" class="rounded-lg bg-sky-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-sky-900">Create announcement</a>
        </header>

        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr><th class="px-4 py-3">Created</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Message</th><th class="px-4 py-3">Audience</th><th class="px-4 py-3">Recipients</th><th class="px-4 py-3">Status</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($announcements as $announcement)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3">{{ $announcement->created_at->format('M j, Y g:i a') }}</td>
                            <td class="px-4 py-3 capitalize">{{ $announcement->type }}</td>
                            <td class="max-w-md px-4 py-3"><a class="text-sky-800 hover:underline" href="{{ route('admin.announcements.show', $announcement) }}">{{ \Illuminate\Support\Str::limit($announcement->message, 80) }}</a></td>
                            <td class="px-4 py-3">{{ ucfirst($announcement->audience_filter['appointment_date'] ?? 'all') }}</td>
                            <td class="px-4 py-3">{{ $announcement->recipients_count }}</td>
                            <td class="px-4 py-3 capitalize">{{ $announcement->status }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No announcements yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $announcements->links() }}
    </div>
@endsection
