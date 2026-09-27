@extends('layouts.app')

@section('content')
    @php
        $showRoute = $queueRoutePrefix.'.escalations.show';
        $isAdmin = auth()->user()->isAdmin();
        $isAssignedWorker = (int) $escalation->assigned_worker_id === auth()->id();
    @endphp
    <div class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <a href="{{ route($queueRoutePrefix.'.escalations.index') }}" class="inline-flex items-center gap-2 text-sm font-semibold text-[#1a5276] hover:underline">← Back to escalation queue</a>

        @if (session('status'))
            <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $errors->first() }}</div>
        @endif

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <header class="flex flex-col justify-between gap-4 border-b border-slate-100 p-5 sm:flex-row sm:items-start">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-rose-700">Patient support request</p>
                    <h1 class="mt-2 text-2xl font-bold text-slate-950">Escalation #{{ $escalation->id }}</h1>
                    <p class="mt-1 text-sm text-slate-500">Received {{ $escalation->created_at->format('M j, Y \a\t g:i A') }} · {{ $escalation->created_at->diffForHumans() }}</p>
                </div>
                <span @class([
                    'w-fit rounded-full px-3 py-1.5 text-sm font-semibold capitalize',
                    'bg-rose-50 text-rose-800' => $escalation->status === 'pending',
                    'bg-amber-50 text-amber-800' => $escalation->status === 'in_progress',
                    'bg-emerald-50 text-emerald-800' => $escalation->status === 'resolved',
                    'bg-slate-100 text-slate-700' => ! in_array($escalation->status, ['pending', 'in_progress', 'resolved'], true),
                ])>{{ str_replace('_', ' ', $escalation->status) }}</span>
            </header>

            <div class="grid gap-6 p-5 md:grid-cols-2">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Patient</h2>
                    <p class="mt-2 text-lg font-bold text-slate-900">{{ $escalation->patient_name }}</p>
                    @if ($escalation->patient_phone)
                        <a class="mt-1 inline-block text-sm font-medium text-[#1a5276] hover:underline" href="tel:{{ preg_replace('/[^\d+]/', '', $escalation->patient_phone) }}">{{ $escalation->patient_phone }}</a>
                    @else
                        <p class="mt-1 text-sm text-slate-500">No phone number is saved for this conversation.</p>
                    @endif
                    <p class="mt-3 text-xs text-slate-400">Conversation session: {{ $escalation->session_id }}</p>
                </div>
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Triage</h2>
                    <p class="mt-2 text-sm text-slate-700">AI confidence:
                        <span class="font-semibold">{{ $escalation->confidence_score === null ? 'Unavailable' : number_format($escalation->confidence_score, 2) }}</span>
                    </p>
                    <p class="mt-1 text-sm text-slate-700">Assigned health worker:
                        <span class="font-semibold">{{ $escalation->assignedWorker?->name ?: 'Not yet claimed' }}</span>
                    </p>
                    @if ($escalation->claimed_at)
                        <p class="mt-1 text-sm text-slate-500">Claimed {{ $escalation->claimed_at->diffForHumans() }}</p>
                    @endif
                    @if ($escalation->resolved_at)
                        <p class="mt-1 text-sm text-slate-500">Resolved by {{ $escalation->resolvedBy?->name ?: 'health worker' }} {{ $escalation->resolved_at->diffForHumans() }}</p>
                    @endif
                </div>
            </div>

            <div class="border-t border-slate-100 p-5">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Escalation question</h2>
                <p class="mt-3 whitespace-pre-wrap rounded-xl bg-rose-50 p-4 text-sm leading-6 text-slate-800">{{ $escalation->user_message }}</p>
                @if ($escalation->ai_response)
                    <h3 class="mt-5 text-sm font-semibold uppercase tracking-wide text-slate-500">Pearlie response</h3>
                    <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-700">{{ $escalation->ai_response }}</p>
                @endif
            </div>

            <div class="flex flex-wrap gap-3 border-t border-slate-100 p-5">
                @if ($escalation->status === 'pending')
                    <form method="POST" action="{{ route($queueRoutePrefix.'.escalations.claim', $escalation->id) }}">
                        @csrf
                        <button type="submit" class="rounded-lg bg-rose-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-rose-800">Claim conversation</button>
                    </form>
                @endif
                @if ($escalation->status === 'in_progress' && ($isAdmin || $isAssignedWorker))
                    <form method="POST" action="{{ route($queueRoutePrefix.'.escalations.resolve', $escalation->id) }}">
                        @csrf
                        <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">Resolve escalation</button>
                    </form>
                @endif
                @if ($queueRoutePrefix === 'admin')
                    <form method="POST" action="{{ route('admin.escalations.resend', $escalation->id) }}">
                        @csrf
                        <button type="submit" class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Resend notifications</button>
                    </form>
                @endif
            </div>
        </section>

        @if ($escalation->status === 'in_progress' && ($isAdmin || $isAssignedWorker))
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-bold text-slate-900">Reply to patient</h2>
                <p class="mt-1 text-sm text-slate-500">The reply is sent through the selected channel to the patient’s saved phone.</p>
                <form method="POST" action="{{ route($queueRoutePrefix.'.escalations.reply', $escalation->id) }}" class="mt-4 space-y-4">
                    @csrf
                    <label class="block text-sm font-medium text-slate-700" for="reply-channel">Delivery channel</label>
                    <select id="reply-channel" name="channel" class="w-full rounded-lg border-slate-300 text-sm focus:border-cyan-600 focus:ring-cyan-600 sm:max-w-xs">
                        <option value="whatsapp">WhatsApp</option>
                        <option value="sms">SMS</option>
                    </select>
                    <label class="block text-sm font-medium text-slate-700" for="reply-message">Message</label>
                    <textarea id="reply-message" name="message" rows="4" maxlength="1000" required class="w-full rounded-lg border-slate-300 text-sm focus:border-cyan-600 focus:ring-cyan-600" placeholder="Write a clear, supportive reply…">{{ old('message') }}</textarea>
                    <button type="submit" class="rounded-lg bg-[#1a5276] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[#0a2f44]">Send reply</button>
                </form>
            </section>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Conversation context</h2>
                    <p class="mt-1 text-sm text-slate-500">Full saved context from this patient session.</p>
                </div>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">{{ count($conversation) }} messages</span>
            </div>
            <div class="mt-5 space-y-4">
                @forelse ($conversation as $turn)
                    <article class="rounded-xl border border-slate-100 p-4">
                        @if ($turn->channel !== 'human' && $turn->user_message !== '')
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Patient · {{ $turn->created_at->format('M j, g:i A') }}</p>
                            <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-800">{{ $turn->user_message }}</p>
                        @endif
                        @if ($turn->ai_response)
                            <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-[#1a5276]">
                                {{ $turn->channel === 'human' ? 'Health worker' : ($turn->channel === 'human_handoff' ? 'Handoff update' : 'Pearlie') }}
                                · {{ $turn->created_at->format('M j, g:i A') }}
                            </p>
                            <p class="mt-1 whitespace-pre-wrap text-sm leading-6 text-slate-700">{{ $turn->ai_response }}</p>
                        @endif
                    </article>
                @empty
                    <p class="rounded-xl bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">No saved conversation turns were found for this session.</p>
                @endforelse
            </div>
        </section>
    </div>
@endsection
