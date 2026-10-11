@extends('layouts.app')

@section('title', 'Payments | '.pearlie_config('hospital.name'))

@section('content')
    <div class="mx-auto max-w-screen-2xl xl:max-w-[90rem] 2xl:max-w-[110rem] space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <header>
            <p class="text-sm font-semibold uppercase tracking-widest text-sky-700">{{ pearlie_config('hospital.name') }}</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-900">M-Pesa payments</h1>
            <p class="mt-2 text-slate-600">Transactions and appointment references for this hospital.</p>
        </header>

        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr><th class="px-4 py-3">Transaction</th><th class="px-4 py-3">Appointment</th><th class="px-4 py-3">Amount</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Receipt</th><th class="px-4 py-3">Processed</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($payments as $payment)
                        <tr>
                            <td class="px-4 py-4"><p class="font-semibold text-slate-900">{{ $payment->checkout_request_id ?: 'Transaction '.$payment->id }}</p><p class="mt-1 text-xs text-slate-500">{{ $payment->phone }}</p></td>
                            <td class="px-4 py-4"><p class="font-semibold text-slate-800">{{ $payment->appointment?->name ?: 'Appointment '.$payment->appointment_request_id }}</p><p class="mt-1 text-xs text-slate-500">{{ $payment->appointment?->reason }}</p></td>
                            <td class="px-4 py-4 font-semibold text-slate-900">KSh {{ number_format($payment->amount, 2) }}</td>
                            <td class="px-4 py-4"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold capitalize text-slate-700">{{ str_replace('_', ' ', $payment->status) }}</span><p class="mt-2 max-w-xs text-xs text-slate-500">{{ $payment->result_description }}</p></td>
                            <td class="px-4 py-4 text-slate-700">{{ $payment->mpesa_receipt ?: '—' }}</td>
                            <td class="px-4 py-4 text-slate-600">{{ $payment->processed_at?->format('M j, Y g:i A') ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-600">No M-Pesa transactions have been recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $payments->links() }}
    </div>
@endsection
