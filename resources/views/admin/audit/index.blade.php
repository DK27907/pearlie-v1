@extends('layouts.app')

@section('title', 'Audit trail | '.pearlie_config('hospital.name'))

@section('content')
    <div class="space-y-8">
        <header class="space-y-2">
            <p class="text-sm font-semibold uppercase tracking-widest text-sky-700">{{ pearlie_config('hospital.name') }}</p>
            <h1 class="text-3xl font-bold tracking-tight text-slate-900">Audit trail</h1>
            <p class="text-slate-600">Tenant-scoped appointment, payment, receipt, and delivery records.</p>
        </header>

        <form method="GET" action="{{ route('admin.audit.index') }}" class="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <label class="grid gap-1 text-sm font-medium text-slate-700">
                Appointment ID
                <input name="appointment_id" type="number" min="1" value="{{ $filters['appointment_id'] ?? '' }}" class="rounded-lg border-slate-300 text-sm">
            </label>
            <button class="rounded-lg bg-slate-950 px-5 py-2 text-sm font-semibold text-white hover:bg-sky-700">Filter</button>
            @if ($appointment)
                <a href="{{ route('admin.audit.index') }}" class="rounded-lg border border-slate-300 px-5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Clear</a>
            @endif
        </form>

        @if ($appointment)
            <section class="rounded-2xl border border-sky-200 bg-sky-50 p-4">
                <h2 class="font-semibold text-slate-900">Appointment #{{ $appointment->id }}</h2>
                <p class="mt-1 text-sm text-slate-700">{{ $appointment->name }} · {{ $appointment->preferred_date?->format('M j, Y') }} · {{ str_replace('_', ' ', $appointment->status) }}</p>
            </section>
        @endif

        <section class="space-y-3">
            <h2 class="text-xl font-bold text-slate-900">Appointment history</h2>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Recorded</th><th class="px-4 py-3">Appointment</th><th class="px-4 py-3">Action</th><th class="px-4 py-3">Actor</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($history as $entry)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3">{{ $entry->created_at?->format('M j, Y g:i A') }}</td>
                                <td class="px-4 py-3">#{{ $entry->appointment_request_id }} {{ $entry->appointment?->name }}</td>
                                <td class="px-4 py-3">{{ str_replace('_', ' ', $entry->action) }}</td>
                                <td class="px-4 py-3">{{ $entry->actor?->name ?? $entry->actor_role }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-slate-600">No appointment history recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $history->links() }}
        </section>

        <section class="space-y-3">
            <h2 class="text-xl font-bold text-slate-900">M-Pesa payments and events</h2>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Recorded</th><th class="px-4 py-3">Appointment</th><th class="px-4 py-3">Payment status</th><th class="px-4 py-3">Amount / receipt</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($payments as $payment)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3">{{ $payment->created_at?->format('M j, Y g:i A') }}</td>
                                <td class="px-4 py-3">#{{ $payment->appointment_request_id }} {{ $payment->appointment?->name }}</td>
                                <td class="px-4 py-3">{{ str_replace('_', ' ', $payment->status) }}</td>
                                <td class="px-4 py-3">KES {{ number_format((float) $payment->amount, 2) }}<span class="block text-xs text-slate-500">{{ $payment->mpesa_receipt ?: 'No M-Pesa receipt' }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-slate-600">No M-Pesa payments recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $payments->links() }}

            <h3 class="pt-4 text-lg font-semibold text-slate-900">Payment events</h3>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Recorded</th><th class="px-4 py-3">Appointment</th><th class="px-4 py-3">Event</th><th class="px-4 py-3">Payment status</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($paymentEvents as $event)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3">{{ $event->created_at?->format('M j, Y g:i A') }}</td>
                                <td class="px-4 py-3">#{{ $event->payment?->appointment_request_id }}</td>
                                <td class="px-4 py-3">{{ str_replace('_', ' ', $event->event) }}</td>
                                <td class="px-4 py-3">{{ str_replace('_', ' ', $event->payment?->status ?? 'unknown') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-slate-600">No payment events recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $paymentEvents->links() }}
        </section>

        <section class="space-y-3">
            <h2 class="text-xl font-bold text-slate-900">Receipts</h2>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Issued</th><th class="px-4 py-3">Appointment</th><th class="px-4 py-3">Receipt</th><th class="px-4 py-3">Amount / method</th><th class="px-4 py-3">Issued by</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($receipts as $receipt)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3">{{ $receipt->issued_at?->format('M j, Y g:i A') }}</td>
                                <td class="px-4 py-3">#{{ $receipt->appointment_request_id }} {{ $receipt->appointment?->name }}</td>
                                <td class="px-4 py-3">{{ $receipt->receipt_number }}</td>
                                <td class="px-4 py-3">KES {{ number_format((float) $receipt->amount, 2) }} · {{ str_replace('_', ' ', $receipt->payment_method) }}</td>
                                <td class="px-4 py-3">{{ $receipt->issuedBy?->name ?? 'System' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-slate-600">No receipts recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $receipts->links() }}
        </section>

        <section class="space-y-3">
            <h2 class="text-xl font-bold text-slate-900">Notification delivery</h2>
            @if ($appointment)
                <p class="text-sm text-slate-600">Only notification records linked to this appointment are shown. Older unlinked logs are not attributed to it.</p>
            @endif
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Recorded</th><th class="px-4 py-3">Appointment</th><th class="px-4 py-3">Channel / provider</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">HTTP / failure</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($notifications as $notification)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3">{{ $notification->created_at?->format('M j, Y g:i A') }}</td>
                                <td class="px-4 py-3">{{ $notification->appointment_request_id ? '#'.$notification->appointment_request_id : 'Hospital-wide' }} {{ $notification->appointment?->name }}</td>
                                <td class="px-4 py-3">{{ $notification->channel }}<span class="block text-xs text-slate-500">{{ $notification->provider }}</span></td>
                                <td class="px-4 py-3">{{ str_replace('_', ' ', $notification->status) }}</td>
                                <td class="px-4 py-3">{{ $notification->http_status ?: '—' }} · {{ $notification->failure_code ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-slate-600">No notification logs recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $notifications->links() }}

            <h3 class="pt-4 text-lg font-semibold text-slate-900">Announcements and reminders</h3>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Recorded</th><th class="px-4 py-3">Appointment</th><th class="px-4 py-3">Channel</th><th class="px-4 py-3">Delivery status</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($announcementRecipients as $recipient)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3">{{ $recipient->sent_at?->format('M j, Y g:i A') ?? $recipient->created_at?->format('M j, Y g:i A') }}</td>
                                <td class="px-4 py-3">#{{ $recipient->appointment_request_id }} {{ $recipient->appointment?->name }}</td>
                                <td class="px-4 py-3">{{ $recipient->announcement?->channel ?? '—' }}</td>
                                <td class="px-4 py-3">{{ str_replace('_', ' ', $recipient->delivery_status) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-slate-600">No announcement deliveries recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $announcementRecipients->links() }}
        </section>
    </div>
@endsection
