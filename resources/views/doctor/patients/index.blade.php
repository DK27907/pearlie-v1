@extends('layouts.app')

@section('header')
    <div>
        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-cyan-700">Patient care</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">My Patients</h1>
        <p class="mt-1 text-sm text-slate-500">Patients who have booked an appointment with you.</p>
    </div>
@endsection

@section('content')
    <div class="mx-auto max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] space-y-5 px-4 py-8 sm:px-6 lg:px-8">
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th scope="col" class="px-5 py-3 font-semibold">Patient</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Phone</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Last Visit</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Next Visit</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Total Visits</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Total Paid</th>
                            <th scope="col" class="px-5 py-3 font-semibold"><span class="sr-only">Action</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($patients as $patient)
                            <tr class="transition hover:bg-slate-50">
                                <td class="px-5 py-4 font-semibold text-slate-900">{{ $patient->name ?: 'Name not provided' }}</td>
                                <td class="whitespace-nowrap px-5 py-4 text-slate-700">{{ $patient->phone ?: 'No phone provided' }}</td>
                                <td class="whitespace-nowrap px-5 py-4 text-slate-700">{{ $patient->last_appointment_date ? \Illuminate\Support\Carbon::parse($patient->last_appointment_date)->format('M j, Y') : '—' }}</td>
                                <td class="whitespace-nowrap px-5 py-4 text-slate-700">{{ $patient->next_appointment_date ? \Illuminate\Support\Carbon::parse($patient->next_appointment_date)->format('M j, Y') : '—' }}</td>
                                <td class="px-5 py-4 text-slate-700">{{ $patient->total_appointments }}</td>
                                <td class="whitespace-nowrap px-5 py-4 text-slate-700">KSh {{ number_format((float) $patient->total_paid, 2) }}</td>
                                <td class="whitespace-nowrap px-5 py-4 text-right">
                                    <a href="{{ route('doctor.appointments', ['patient' => $patient->representative_appointment_id]) }}" class="font-semibold text-cyan-800 hover:text-cyan-600">View Appointments <span aria-hidden="true">→</span></a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-14 text-center">
                                    <p class="font-semibold text-slate-800">No patients have booked with you yet</p>
                                    <p class="mt-1 text-sm text-slate-500">Patients will appear here after making an appointment.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($patients->hasPages())
                <div class="border-t border-slate-100 px-5 py-4">{{ $patients->links() }}</div>
            @endif
        </div>
    </div>
@endsection
