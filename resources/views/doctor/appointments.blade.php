@extends('layouts.app')

@section('header')
    <div>
        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-cyan-700">Patient care</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Appointments</h1>
        <p class="mt-1 text-sm text-slate-500">Review and manage your patient visits.</p>
    </div>
@endsection

@section('content')
    <div class="mx-auto max-w-7xl space-y-5 px-4 py-8 sm:px-6 lg:px-8">
        @if (session('status'))
            <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</div>
        @endif

        <form method="GET" action="{{ route('doctor.appointments') }}" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-[1fr_1fr_auto_auto] sm:items-end">
            <label class="grid gap-1.5 text-sm font-medium text-slate-700">
                Date
                <input type="date" name="date" value="{{ request('date') }}" class="rounded-lg border-slate-300 text-sm focus:border-cyan-600 focus:ring-cyan-600">
            </label>
            <label class="grid gap-1.5 text-sm font-medium text-slate-700">
                Status
                <select name="status" class="rounded-lg border-slate-300 text-sm focus:border-cyan-600 focus:ring-cyan-600">
                    <option value="">All statuses</option>
                    @foreach (\App\Models\AppointmentRequest::statuses() as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit" class="rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-cyan-800">Apply filters</button>
            <a href="{{ route('doctor.appointments') }}" class="rounded-lg border border-slate-200 px-4 py-2.5 text-center text-sm font-semibold text-slate-600 hover:bg-slate-50">Clear</a>
        </form>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th scope="col" class="px-5 py-3 font-semibold">Patient</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Date &amp; time</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Reason</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Status</th>
                            <th scope="col" class="px-5 py-3 font-semibold"><span class="sr-only">Action</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($appointments as $appointment)
                            <tr class="transition hover:bg-slate-50">
                                <td class="px-5 py-4">
                                    <p class="font-semibold text-slate-900">{{ $appointment->name ?: 'Name not provided' }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ $appointment->phone ?: 'No phone provided' }}</p>
                                </td>
                                <td class="whitespace-nowrap px-5 py-4">
                                    <p class="font-medium text-slate-800">{{ $appointment->preferred_date?->format('M j, Y') ?: 'Date not set' }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ $appointment->slot_start_time ? substr($appointment->slot_start_time, 0, 5) : 'Time pending' }}</p>
                                </td>
                                <td class="max-w-xs px-5 py-4 text-slate-600">{{ \Illuminate\Support\Str::limit($appointment->reason ?: '—', 72) }}</td>
                                <td class="px-5 py-4">
                                    <span @class([
                                        'rounded-full px-2.5 py-1 text-xs font-semibold capitalize',
                                        'bg-amber-50 text-amber-800' => $appointment->status === 'pending',
                                        'bg-cyan-50 text-cyan-800' => $appointment->status === 'confirmed',
                                        'bg-emerald-50 text-emerald-800' => $appointment->status === 'completed',
                                        'bg-rose-50 text-rose-800' => $appointment->status === 'no_show',
                                        'bg-orange-50 text-orange-800' => $appointment->status === 'expired',
                                        'bg-slate-100 text-slate-600' => $appointment->status === 'cancelled',
                                    ])>{{ $appointment->status }}</span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 text-right">
                                    <a href="{{ route('doctor.appointments.show', $appointment->id) }}" class="font-semibold text-cyan-800 hover:text-cyan-600">Details <span aria-hidden="true">→</span></a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-14 text-center">
                                <p class="font-semibold text-slate-800">No appointments found</p>
                                <p class="mt-1 text-sm text-slate-500">Try changing the date or status filters.</p>
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($appointments->hasPages())
                <div class="border-t border-slate-100 px-5 py-4">{{ $appointments->links() }}</div>
            @endif
        </div>
    </div>
@endsection
