@extends('layouts.app')

@section('title', 'Announcement delivery')

@section('content')
    <div class="mx-auto max-w-6xl space-y-6">
        @if (session('status'))
            <p role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-900">{{ session('status') }}</p>
        @endif
        @if (session('error'))
            <p role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-rose-800">{{ session('error') }}</p>
        @endif
        <header class="space-y-2">
            <a href="{{ route('admin.announcements.index') }}" class="text-sm font-semibold text-sky-800 hover:underline">← Announcements</a>
            <h1 class="text-3xl font-bold tracking-tight text-slate-900">Announcement delivery</h1>
            <p class="text-sm text-slate-600">Status: <span class="font-semibold capitalize">{{ $announcement->status }}</span> · {{ $announcement->recipients_count }} recipients</p>
        </header>
        <section class="rounded-xl border border-slate-200 bg-white p-5">
            <p class="whitespace-pre-wrap text-slate-800">{{ $announcement->message }}</p>
            @if ($announcement->scheduled_for)
                <p class="mt-3 text-sm text-slate-500">Scheduled for {{ $announcement->scheduled_for->format('M j, Y g:i a') }}</p>
            @endif
        </section>
        <section class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Recipient</th><th class="px-4 py-3">Phone</th><th class="px-4 py-3">Message</th><th class="px-4 py-3">Delivery</th><th class="px-4 py-3">Sent</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($announcement->recipients as $recipient)
                        <tr><td class="px-4 py-3">{{ $recipient->recipient_name }}</td><td class="px-4 py-3">{{ $recipient->recipient_phone }}</td><td class="max-w-lg px-4 py-3">{{ $recipient->rendered_message }}</td><td class="px-4 py-3 capitalize">{{ $recipient->delivery_status }}</td><td class="px-4 py-3">{{ $recipient->sent_at?->format('M j, Y g:i a') ?? '—' }}</td></tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">No recipients have been recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>
@endsection
