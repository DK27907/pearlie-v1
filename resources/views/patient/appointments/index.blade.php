@extends('layouts.app')

@section('header')
    <div>
        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-cyan-700">Your care</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">My Appointments</h1>
        <p class="mt-1 text-sm text-slate-500">Review your appointments and payment details.</p>
    </div>
@endsection

@section('content')
    <div class="mx-auto max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] space-y-5 px-4 py-8 sm:px-6 lg:px-8">
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th scope="col" class="px-5 py-3 font-semibold">Date</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Doctor</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Service</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Amount</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Status</th>
                            <th scope="col" class="px-5 py-3 font-semibold">Receipt</th>
                            <th scope="col" class="px-5 py-3 font-semibold"><span class="sr-only">Action</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($appointments as $appointment)
                            <tr class="transition hover:bg-slate-50">
                                <td class="whitespace-nowrap px-5 py-4 font-medium text-slate-800">
                                    {{ $appointment->preferred_date?->format('M j, Y') ?: 'Date not set' }}
                                    <p class="mt-1 font-mono text-xs text-slate-500">{{ $appointment->booking_reference ?: 'Not available' }}</p>
                                </td>
                                <td class="px-5 py-4 text-slate-700">
                                    {{ $appointment->doctor?->name ?: 'Not assigned' }}
                                </td>
                                <td class="px-5 py-4 text-slate-700">
                                    {{ $appointment->service?->name ?: ($appointment->reason ?: 'General appointment') }}
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 text-slate-700">
                                    KSh {{ number_format((float) ($appointment->payment_amount ?? $appointment->service?->price ?? $appointment->booking_fee ?? 0), 2) }}
                                </td>
                                <td class="px-5 py-4">
                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold capitalize text-slate-700">{{ $appointment->status }}</span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 text-slate-700">
                                    {{ $appointment->mpesaPayment?->mpesa_receipt ?: $appointment->mpesa_receipt ?: '—' }}
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 text-right">
                                    <a href="{{ route('appointments.show', $appointment) }}" class="font-semibold text-cyan-800 hover:text-cyan-600">Details <span aria-hidden="true">→</span></a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-14 text-center">
                                    <p class="font-semibold text-slate-800">You haven't booked any appointments yet</p>
                                    <a href="{{ route('tenant.home', hospital()->slug) }}" class="mt-3 inline-flex font-semibold text-cyan-800 hover:text-cyan-600">Explore hospital services</a>
                                </td>
                            </tr>
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
