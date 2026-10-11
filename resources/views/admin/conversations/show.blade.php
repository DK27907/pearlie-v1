@extends('layouts.app')

@section('title', 'Conversation transcript | '.pearlie_config('hospital.name'))

@section('content')
    <div class="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <header>
            <a href="{{ route('admin.conversations.index') }}" class="text-sm font-semibold text-sky-800 hover:underline">← All conversations</a>
            <h1 class="mt-3 text-3xl font-bold tracking-tight text-slate-900">Transcript</h1>
            <p class="mt-2 break-all text-sm text-slate-500">Session {{ $sessionId }}</p>
        </header>

        <ol class="space-y-4">
            @foreach ($transcript as $message)
                <li class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="mb-3 text-xs font-medium text-slate-500">{{ $message->created_at?->format('M j, Y g:i A') }} · {{ ucfirst($message->channel ?: 'web') }}</p>
                    <h2 class="text-sm font-bold text-slate-900">Patient</h2>
                    <p class="mt-1 whitespace-pre-wrap text-sm leading-6 text-slate-700">{{ $message->user_message }}</p>
                    <h2 class="mt-4 text-sm font-bold text-sky-900">Assistant</h2>
                    <p class="mt-1 whitespace-pre-wrap text-sm leading-6 text-slate-700">{{ $message->ai_response }}</p>
                </li>
            @endforeach
        </ol>
    </div>
@endsection
