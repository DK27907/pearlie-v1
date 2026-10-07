@extends('layouts.app')

@section('content')
    <div class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <a href="{{ route('superadmin.hospitals.index') }}" class="text-sm font-semibold text-indigo-700 hover:underline">← All hospitals</a>
        @if (session('status'))<div role="status" class="rounded-xl bg-emerald-50 p-4 text-emerald-900">{{ session('status') }}</div>@endif
        <header class="flex flex-wrap items-end justify-between gap-4"><div><p class="text-sm font-semibold uppercase tracking-widest text-indigo-700">{{ $hospital->slug }}</p><h1 class="mt-2 text-3xl font-bold text-slate-900">{{ $hospital->name }}</h1><p class="mt-2 text-slate-600">{{ $hospital->email }} · {{ $hospital->phone }}</p></div><div class="flex gap-3"><a href="{{ route('superadmin.hospitals.edit', $hospital) }}" class="rounded-xl border border-slate-300 px-4 py-3 font-semibold">Edit</a><form method="POST" action="{{ route('superadmin.hospitals.impersonate', $hospital) }}">@csrf<button class="rounded-xl bg-indigo-700 px-4 py-3 font-semibold text-white">View as hospital</button></form></div></header>
        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">@foreach ([['Users', $hospital->users_count], ['Doctors', $hospital->doctors_count], ['Appointments', $hospital->appointments_count], ['Escalations', $hospital->escalations_count]] as [$label, $count])<article class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-sm text-slate-500">{{ $label }}</p><p class="mt-2 text-3xl font-bold text-slate-900">{{ number_format($count) }}</p></article>@endforeach</section>
        <section class="rounded-2xl border border-slate-200 bg-white p-6"><h2 class="text-lg font-bold">Subscription</h2><p class="mt-2 capitalize text-slate-700">{{ $hospital->subscription_plan }} · {{ $hospital->subscription_status }}{{ $hospital->trial_ends_at ? ' · expires '.$hospital->trial_ends_at->format('M j, Y') : '' }}</p><p class="mt-4 text-sm text-slate-600">{{ $hospital->address }} {{ $hospital->city ? '· '.$hospital->city : '' }}</p></section>
        <form method="POST" action="{{ route('superadmin.hospitals.invite-admin', $hospital) }}" class="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-6">
            @csrf
            <label class="min-w-56 flex-1 text-sm font-semibold text-slate-700">Invite hospital administrator<input type="email" name="email" required class="mt-1 w-full rounded-xl border-slate-300 font-normal" placeholder="admin@example.com"></label>
            <button class="rounded-xl bg-indigo-700 px-5 py-3 font-semibold text-white">Send setup invitation</button>
        </form>
        @if ($hospital->subscription_status === 'suspended' || ! $hospital->is_active)
            <form method="POST" action="{{ route('superadmin.hospitals.activate', $hospital) }}">@csrf<button class="text-sm font-semibold text-emerald-700 hover:underline">Restore hospital access</button></form>
        @else
            <form method="POST" action="{{ route('superadmin.hospitals.suspend', $hospital) }}" onsubmit="return confirm('Suspend this hospital account? Data will be retained.')">@csrf<button class="text-sm font-semibold text-rose-700 hover:underline">Suspend hospital access</button></form>
        @endif
    </div>
@endsection
