@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Appointment #{{ $appt->id }}</h1>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div role="alert" class="my-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $errors->first() }}</div>
    @endif

    <div class="card mb-3">
        <div class="card-body">
            <p><strong>Name:</strong> {{ $appt->name }}</p>
            <p><strong>Phone:</strong> {{ $appt->phone }}</p>
            <p><strong>Email:</strong> {{ $appt->email ?: 'Not provided' }}</p>
            <p><strong>Preferred Date:</strong> {{ $appt->preferred_date }}</p>
            <p><strong>Reason:</strong> {{ $appt->reason }}</p>
            <p><strong>Status:</strong> {{ $appt->status }}</p>
            @if ($appt->patient_id === null)
                <p class="my-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                    Guest booking (not linked to a patient account)
                </p>
            @endif
            @if ($repeatNoShowCount > 0)
                <p class="my-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
                    This patient has {{ $repeatNoShowCount }} previous no-show{{ $repeatNoShowCount === 1 ? '' : 's' }}.
                </p>
            @endif
            @if ($appt->isNoShow())
                <section class="my-4 rounded-lg border border-rose-200 bg-rose-50 p-4">
                    <h2 class="font-semibold text-rose-900">No-show record</h2>
                    <p class="mt-2 text-sm text-rose-800">Marked {{ $appt->marked_no_show_at?->format('M j, Y, g:i a') ?: 'at an unknown time' }}.</p>
                    <p class="mt-1 text-sm text-rose-800">Reason: {{ $appt->no_show_reason ?: 'No reason provided.' }}</p>
                </section>
            @endif

            @php
                $mpesaPayment = $appt->mpesaPayment;
                $paymentStatus = $appt->payment_status ?: 'unpaid';
                $paymentAmount = $appt->payment_amount ?? $mpesaPayment?->amount ?? $appt->booking_fee;
                $paymentReceipt = $appt->mpesa_receipt ?? $mpesaPayment?->mpesa_receipt;
                $checkoutRequestId = $mpesaPayment?->checkout_request_id ?? $appt->mpesa_checkout_request_id;
            @endphp
            <section class="my-5 rounded-xl border border-slate-200 bg-slate-50 p-4" aria-labelledby="payment-heading">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="payment-heading" class="font-semibold text-slate-900">M-Pesa payment</h2>
                    <span @class([
                        'rounded-full px-3 py-1 text-xs font-semibold capitalize',
                        'bg-emerald-100 text-emerald-800' => $paymentStatus === 'paid',
                        'bg-amber-100 text-amber-800' => $paymentStatus === 'pending',
                        'bg-rose-100 text-rose-800' => in_array($paymentStatus, ['failed', 'unpaid'], true),
                        'bg-slate-200 text-slate-700' => ! in_array($paymentStatus, ['paid', 'pending', 'failed', 'unpaid', 'refunded'], true),
                        'bg-violet-100 text-violet-800' => $paymentStatus === 'refunded',
                    ])>{{ $paymentStatus }}</span>
                </div>
                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-slate-500">Amount</dt>
                        <dd class="mt-1 font-medium text-slate-900">{{ $paymentAmount !== null ? 'KSh '.number_format((float) $paymentAmount, 2) : 'Not set' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Paid at</dt>
                        <dd class="mt-1 font-medium text-slate-900">{{ $appt->paid_at?->format('M j, Y g:i a') ?? 'Not paid' }}</dd>
                    </div>
                    @if ($appt->payment_method)
                        <div>
                            <dt class="text-slate-500">Payment method</dt>
                            <dd class="mt-1 font-medium capitalize text-slate-900">{{ str_replace('_', ' ', $appt->payment_method) }}</dd>
                        </div>
                    @endif
                    @if ($paymentReceipt)
                        <div class="sm:col-span-2">
                            <dt class="text-slate-500">M-Pesa receipt</dt>
                            <dd class="mt-1 font-mono font-semibold text-slate-900">{{ $paymentReceipt }}</dd>
                        </div>
                    @endif
                </dl>

                @if ($checkoutRequestId)
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <button
                            type="button"
                            id="verify-mpesa-payment"
                            data-status-url="{{ url('/api/mpesa/status/'.rawurlencode($checkoutRequestId)).'?hospital='.rawurlencode(hospital()?->slug ?? 'pearl') }}"
                            class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-cyan-800 disabled:cursor-wait disabled:opacity-60"
                        >Verify Payment</button>
                        <p id="mpesa-verification-result" role="status" aria-live="polite" class="text-sm text-slate-600"></p>
                    </div>
                @endif
                @if ($paymentStatus === 'pending' && $appt->payment_due_at === 'at_hospital' && in_array($appt->status, ['pending', 'confirmed'], true))
                    <form method="POST" action="{{ route('admin.appointments.mark-paid', $appt->id) }}" class="mt-5 grid gap-4 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-3">
                        @csrf
                        <label class="block text-sm font-semibold text-slate-700">Amount received (KES)
                            <input class="mt-1 block w-full rounded-lg border-slate-300 text-sm" type="number" name="amount" min="0.01" max="10000000" step="0.01" required value="{{ old('amount', $paymentAmount ?? 0) }}">
                            <span class="mt-1 block text-xs font-normal text-slate-500">Enter the full outstanding amount; partial or excess payments are not supported.</span>
                        </label>
                        <label class="block text-sm font-semibold text-slate-700">Payment method
                            <select class="mt-1 block w-full rounded-lg border-slate-300 text-sm" name="payment_method" required>
                                @foreach (['cash' => 'Cash', 'card' => 'Card', 'bank' => 'Bank transfer', 'other' => 'Other'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('payment_method', 'cash') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm font-semibold text-slate-700">Notes (optional)
                            <input class="mt-1 block w-full rounded-lg border-slate-300 text-sm" name="notes" maxlength="1000" value="{{ old('notes') }}">
                        </label>
                        <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800 sm:col-span-3 sm:justify-self-start">Mark as paid and generate receipt</button>
                    </form>
                @endif
                @if ($appt->receipt)
                    <div class="mt-4 flex flex-wrap gap-2">
                        <a href="{{ $appt->receipt->signedDownloadUrl() }}" class="inline-flex rounded-lg bg-sky-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-sky-900">Download receipt PDF</a>
                        <a href="{{ $appt->receipt->signedPrintUrl() }}" target="_blank" rel="noopener" class="inline-flex rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">Print receipt</a>
                    </div>
                @endif
            </section>

            <form method="POST" action="{{ route('admin.appointments.updateStatus', $appt->id) }}">
                @csrf
                <div class="form-group">
                    <label for="status">Update status</label>
                    <select name="status" id="status" class="form-control">
                        @foreach (array_diff(\App\Models\AppointmentRequest::statuses(), [\App\Models\AppointmentRequest::STATUS_NO_SHOW]) as $status)
                            <option value="{{ $status }}" @selected($appt->status === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn btn-primary mt-2">Update</button>
            </form>

            <form method="POST" action="{{ route('admin.appointments.confirm', $appt->id) }}" style="display:inline">
                @csrf
                <button type="submit" class="btn btn-success mt-2">Confirm Appointment & Notify</button>
            </form>
            @if ($appt->status === 'confirmed' && $paymentStatus === 'paid')
                <form method="POST" action="{{ route('admin.appointments.no-show', $appt->id) }}" class="mt-4 grid gap-2 sm:max-w-md">
                    @csrf
                    <label for="no-show-reason" class="text-sm font-medium text-slate-700">No-show reason (optional)</label>
                    <input id="no-show-reason" name="reason" maxlength="255" class="rounded-lg border-slate-300 text-sm" placeholder="e.g. Patient did not arrive">
                    <button type="submit" class="rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-800">Mark No-Show</button>
                </form>
            @endif

            @if (in_array($appt->status, ['pending', 'confirmed'], true))
                <section class="mt-6 grid gap-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <h2 class="font-semibold text-slate-900">Appointment lifecycle override</h2>
                    <form method="POST" action="{{ route('admin.appointments.cancel', $appt->id) }}" class="flex flex-wrap items-center gap-2" data-confirm="Cancel this appointment?">
                        @csrf
                        <label for="admin-cancellation-reason" class="sr-only">Cancellation reason</label>
                        <input id="admin-cancellation-reason" name="reason" maxlength="1000" class="rounded-lg border-slate-300 text-sm" placeholder="Cancellation reason">
                        <button type="submit" class="rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-800">Cancel appointment</button>
                    </form>
                    <form method="POST" action="{{ route('admin.appointments.reschedule', $appt->id) }}" class="grid gap-3 sm:grid-cols-2">
                        @csrf
                        <label class="grid gap-1 text-sm font-medium text-slate-700">Doctor
                            <select name="doctor_id" required class="rounded-lg border-slate-300 text-sm">
                                @foreach ($doctors as $doctor)
                                    <option value="{{ $doctor->id }}" @selected(old('doctor_id', $appt->doctor_id) == $doctor->id)>{{ $doctor->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="grid gap-1 text-sm font-medium text-slate-700">Date
                            <input type="date" name="date" required min="{{ now()->toDateString() }}" max="{{ now()->addYear()->toDateString() }}" class="rounded-lg border-slate-300 text-sm">
                        </label>
                        <label class="grid gap-1 text-sm font-medium text-slate-700">Time
                            <input type="time" name="time" required class="rounded-lg border-slate-300 text-sm">
                        </label>
                        <label class="grid gap-1 text-sm font-medium text-slate-700">Reason (optional)
                            <input name="reason" maxlength="1000" class="rounded-lg border-slate-300 text-sm">
                        </label>
                        <button type="submit" class="rounded-lg bg-cyan-800 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-700 sm:col-span-2">Reschedule appointment</button>
                    </form>
                </section>
            @endif

            <section class="mt-6 rounded-xl border border-slate-200 bg-white p-4">
                <h2 class="font-semibold text-slate-900">Appointment change history</h2>
                @forelse ($appt->histories as $history)
                    <div class="mt-3 border-l-2 border-cyan-700 pl-3">
                        <p class="font-medium capitalize text-slate-800">{{ str_replace('_', ' ', $history->action) }} by {{ $history->actor_role }}</p>
                        @if ($history->action === 'rescheduled')
                            <p class="text-sm text-slate-600">
                                {{ $history->old_date?->format('d/m/Y') }} at {{ $history->old_start_time ? substr((string) $history->old_start_time, 0, 5) : '—' }}
                                → {{ $history->new_date?->format('d/m/Y') }} at {{ $history->new_start_time ? substr((string) $history->new_start_time, 0, 5) : '—' }}
                            </p>
                        @else
                            <p class="text-sm text-slate-600">{{ $history->reason ?: 'No reason provided.' }}</p>
                        @endif
                        <p class="text-xs text-slate-500">{{ $history->created_at?->format('M j, Y, g:i a') }}</p>
                    </div>
                @empty
                    <p class="mt-2 text-sm text-slate-500">No appointment changes have been recorded.</p>
                @endforelse
            </section>
        </div>
    </div>

    <a href="{{ route('admin.appointments.index') }}" class="btn btn-link">Back to list</a>
</div>
@endsection
