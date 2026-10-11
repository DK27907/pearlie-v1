@extends('layouts.app')

@section('title', 'Conversations | '.pearlie_config('hospital.name'))

@section('content')
    <div class="mx-auto max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <header>
            <p class="text-sm font-semibold uppercase tracking-widest text-sky-700">{{ pearlie_config('hospital.name') }}</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-900">Conversations</h1>
            <p class="mt-2 text-slate-600">Review patient-assistant transcripts for this hospital.</p>
        </header>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="divide-y divide-slate-100">
                @forelse ($conversations as $conversation)
                    <a href="{{ route('admin.conversations.show', $conversation) }}" class="block p-5 hover:bg-slate-50">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <span class="text-sm font-semibold text-slate-900">Conversation {{ $conversation->session_id }}</span>
                            <span class="text-xs text-slate-500">{{ $conversation->created_at?->format('M j, Y g:i A') }} · {{ ucfirst($conversation->channel ?: 'web') }}</span>
                        </div>
                        <p class="mt-3 text-sm text-slate-700"><span class="font-semibold">Patient:</span> {{ \Illuminate\Support\Str::limit($conversation->user_message, 240) }}</p>
                        <p class="mt-1 text-sm text-slate-600"><span class="font-semibold">Assistant:</span> {{ \Illuminate\Support\Str::limit($conversation->ai_response, 240) }}</p>
                    </a>
                @empty
                    <p class="p-8 text-slate-600">No conversations have been recorded yet.</p>
                @endforelse
            </div>
        </div>

        {{ $conversations->links() }}
    </div>
@endsection
