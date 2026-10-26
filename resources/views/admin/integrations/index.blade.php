@extends('layouts.master')

@section('title', 'API and webhooks')

@section('page')
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">API and webhooks</h1>
            <p class="mt-1 text-sm text-slate-600">Manage tenant-scoped read-only API credentials and signed appointment event subscriptions.</p>
        </div>

        @if (session('status'))
            <div role="status" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</div>
        @endif

        @if ($createdApiToken)
            <div role="alert" class="space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
                <p class="font-bold">Copy this API token now. It will not be shown again.</p>
                <code class="block break-all rounded bg-white p-3">{{ $createdApiToken }}</code>
            </div>
        @endif

        @if ($createdWebhookSecret)
            <div role="alert" class="space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
                <p class="font-bold">Copy this webhook signing secret now. It will not be shown again.</p>
                <code class="block break-all rounded bg-white p-3">{{ $createdWebhookSecret }}</code>
            </div>
        @endif

        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                <ul class="list-inside list-disc space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div>
                <h2 class="text-lg font-bold text-slate-900">API tokens</h2>
                <p class="mt-1 text-sm text-slate-600">Tokens are stored as one-way hashes and can read only this hospital’s appointment metadata.</p>
            </div>

            <form method="POST" action="{{ route('admin.integrations.tokens.store') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                @csrf
                <label class="block flex-1 text-sm font-semibold text-slate-700">Token name
                    <input name="name" maxlength="80" required value="{{ old('name') }}" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                </label>
                <button type="submit" class="rounded-lg bg-sky-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-sky-900">Create token</button>
            </form>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[35rem] text-left text-sm">
                    <thead class="border-b border-slate-200 text-xs uppercase text-slate-500">
                        <tr><th class="py-2 pr-4">Name</th><th class="py-2 pr-4">Last used</th><th class="py-2 pr-4">Status</th><th class="py-2">Action</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($tokens as $token)
                            <tr>
                                <td class="py-3 pr-4 font-semibold">{{ $token->name }}</td>
                                <td class="py-3 pr-4">{{ $token->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                                <td class="py-3 pr-4">{{ $token->revoked_at ? 'Revoked' : 'Active' }}</td>
                                <td class="py-3">
                                    @unless ($token->revoked_at)
                                        <form method="POST" action="{{ route('admin.integrations.tokens.destroy', $token->id) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="font-semibold text-red-700 underline">Revoke</button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-4 text-slate-500">No API tokens have been created.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div>
                <h2 class="text-lg font-bold text-slate-900">Webhook subscriptions</h2>
                <p class="mt-1 text-sm text-slate-600">Only public HTTPS endpoints are allowed. Delivery is signed and retried a bounded number of times.</p>
            </div>

            <form method="POST" action="{{ route('admin.integrations.webhooks.store') }}" class="space-y-4">
                @csrf
                <label class="block text-sm font-semibold text-slate-700">HTTPS endpoint
                    <input type="url" name="url" maxlength="2048" required value="{{ old('url') }}" placeholder="https://api.example.org/hooks/appointments" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                </label>
                <fieldset class="flex flex-wrap gap-4">
                    <legend class="mb-2 text-sm font-semibold text-slate-700">Events</legend>
                    @foreach ($events as $event)
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" name="events[]" value="{{ $event }}" @checked(in_array($event, old('events', $events), true))>
                            {{ $event }}
                        </label>
                    @endforeach
                </fieldset>
                <button type="submit" class="rounded-lg bg-sky-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-sky-900">Add webhook</button>
            </form>

            <div class="space-y-3">
                @forelse ($subscriptions as $subscription)
                    <article class="rounded-lg border border-slate-200 p-4">
                        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                            <div class="min-w-0 space-y-1">
                                <p class="break-all font-semibold text-slate-900">{{ $subscription->url }}</p>
                                <p class="text-sm text-slate-600">{{ implode(', ', $subscription->events ?? []) }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ $subscription->is_active ? 'Active' : 'Disabled' }}
                                    @if ($subscription->deliveries->isNotEmpty())
                                        · Latest delivery: {{ $subscription->deliveries->first()->status }}
                                    @endif
                                </p>
                            </div>
                            @if ($subscription->is_active)
                                <form method="POST" action="{{ route('admin.integrations.webhooks.destroy', $subscription->id) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="whitespace-nowrap font-semibold text-red-700 underline">Disable webhook</button>
                                </form>
                            @endif
                        </div>
                    </article>
                @empty
                    <p class="rounded-lg bg-slate-50 p-4 text-sm text-slate-500">No webhook subscriptions have been created.</p>
                @endforelse
            </div>
        </section>
    </div>
@endsection
