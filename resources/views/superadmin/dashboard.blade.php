@extends('layouts.app')

@section('content')
    <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div><p class="text-sm font-semibold uppercase tracking-widest text-indigo-700">AxiomForge platform</p><h1 class="mt-2 text-3xl font-bold text-slate-900">MediDesk AI overview</h1><p class="mt-2 text-slate-600">Hospital adoption, subscription status, and monthly activity.</p></div>
            <a href="{{ route('superadmin.hospitals.create') }}" class="rounded-xl bg-indigo-700 px-4 py-3 text-sm font-semibold text-white hover:bg-indigo-800">Add hospital</a>
        </header>

        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Platform statistics">
            @foreach ([['Hospitals', $totalHospitals], ['Active', $activeHospitals], ['On trial', $trialHospitals], ['Paid revenue this month', 'KES '.number_format($monthlyRevenue, 2)]] as [$label, $value])
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm font-medium text-slate-500">{{ $label }}</p><p class="mt-3 text-2xl font-bold text-[#0a2f44]">{{ is_numeric($value) ? number_format($value) : $value }}</p></article>
            @endforeach
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-5"><div><h2 class="font-bold text-slate-900">Hospitals</h2><p class="mt-1 text-sm text-slate-500">Monthly booking volume and collected deposits.</p></div><a class="text-sm font-semibold text-indigo-700 hover:underline" href="{{ route('superadmin.hospitals.index') }}">Manage hospitals</a></div>
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-left text-sm"><thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-3">Hospital</th><th class="px-5 py-3">Plan / status</th><th class="px-5 py-3">Doctors</th><th class="px-5 py-3">Appointments this month</th><th class="px-5 py-3">Revenue this month</th><th class="px-5 py-3">Actions</th></tr></thead><tbody class="divide-y divide-slate-100">
                @forelse ($hospitals as $hospital)
                    <tr><td class="px-5 py-4"><a href="{{ route('superadmin.hospitals.show', $hospital) }}" class="font-semibold text-indigo-700 hover:underline">{{ $hospital->name }}</a><div class="text-slate-500">{{ $hospital->slug }}</div></td><td class="px-5 py-4 capitalize">{{ $hospital->subscription_plan }}<div class="text-slate-500">{{ $hospital->subscription_status }}</div></td><td class="px-5 py-4">{{ number_format($hospital->doctors_count) }}</td><td class="px-5 py-4">{{ number_format($hospital->appointments_this_month_count) }}</td><td class="px-5 py-4">KES {{ number_format((float) $hospital->revenue_this_month, 2) }}</td><td class="px-5 py-4"><form method="POST" action="{{ route('superadmin.hospitals.impersonate', $hospital) }}">@csrf<button class="font-semibold text-slate-700 hover:text-indigo-700">View as</button></form></td></tr>
                @empty
                    <tr><td colspan="6" class="px-5 py-10 text-center text-slate-500">No hospitals have been configured yet.</td></tr>
                @endforelse
            </tbody></table></div>
        </section>
    </div>
@endsection
