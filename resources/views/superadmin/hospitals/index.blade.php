@extends('layouts.app')

@section('content')
    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm font-semibold uppercase tracking-widest text-indigo-700">MediDesk AI platform</p>
                <h1 class="mt-2 text-3xl font-bold text-slate-900">Hospitals</h1>
                <p class="mt-2 text-slate-600">Manage tenant accounts, subscriptions, and support access.</p>
            </div>
            <a href="{{ route('superadmin.hospitals.create') }}" class="rounded-xl bg-indigo-700 px-4 py-3 text-sm font-semibold text-white hover:bg-indigo-800">Add hospital</a>
        </div>

        @if (session('status'))
            <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-900">{{ session('status') }}</div>
        @endif

        <form method="GET" class="flex gap-3">
            <label class="sr-only" for="search">Search hospitals</label>
            <input id="search" name="search" value="{{ request('search') }}" placeholder="Search by hospital, slug, or email" class="min-w-0 flex-1 rounded-xl border-slate-300">
            <button class="rounded-xl border border-slate-300 bg-white px-4 py-2 font-semibold text-slate-700">Search</button>
        </form>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr><th class="px-5 py-3">Hospital</th><th class="px-5 py-3">Plan / status</th><th class="px-5 py-3">Users</th><th class="px-5 py-3">Appointments</th><th class="px-5 py-3">Actions</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($hospitals as $hospital)
                            <tr>
                                <td class="px-5 py-4"><a class="font-semibold text-indigo-700 hover:underline" href="{{ route('superadmin.hospitals.show', $hospital) }}">{{ $hospital->name }}</a><div class="text-slate-500">{{ $hospital->slug }} · {{ $hospital->email ?: 'No email' }}</div></td>
                                <td class="px-5 py-4"><span class="font-medium capitalize">{{ $hospital->subscription_plan }}</span><div class="text-slate-500">{{ ucfirst($hospital->subscription_status) }}{{ $hospital->trial_ends_at ? ' · '.$hospital->trial_ends_at->format('M j, Y') : '' }}</div></td>
                                <td class="px-5 py-4">{{ $hospital->users_count }}</td>
                                <td class="px-5 py-4">{{ $hospital->appointments_count }}</td>
                                <td class="px-5 py-4"><div class="flex flex-wrap gap-3"><a class="font-semibold text-indigo-700 hover:underline" href="{{ route('superadmin.hospitals.edit', $hospital) }}">Edit</a><form method="POST" action="{{ route('superadmin.hospitals.impersonate', $hospital) }}">@csrf<button class="font-semibold text-slate-700 hover:underline">View as</button></form></div></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-10 text-center text-slate-500">No hospitals match this search.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-100 px-5 py-4">{{ $hospitals->links() }}</div>
        </div>
    </div>
@endsection
