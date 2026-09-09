@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
    <div class="mb-8 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
        <div><p class="text-sm font-semibold uppercase tracking-widest text-cyan-700">Care operations</p><h1 class="mt-2 text-3xl font-bold text-slate-900">Appointment requests</h1><p class="mt-2 text-slate-500">Review new requests, confirm details, and notify patients.</p></div>
        <a class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:border-cyan-300" href="{{ route('admin.appointments.exportCsv') }}">Export CSV</a>
    </div>

    <form method="GET" class="mb-5 flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row">
        <input type="text" name="q" class="w-full rounded-lg border-slate-200 text-sm focus:border-cyan-500 focus:ring-cyan-500 sm:max-w-sm" placeholder="Search name, phone or reason" value="{{ request('q') }}" />
        <select name="status" class="rounded-lg border-slate-200 text-sm focus:border-cyan-500 focus:ring-cyan-500">
            <option value="">All statuses</option>
            <option value="pending" {{ request('status')=='pending' ? 'selected':'' }}>Pending</option>
            <option value="confirmed" {{ request('status')=='confirmed' ? 'selected':'' }}>Confirmed</option>
            <option value="cancelled" {{ request('status')=='cancelled' ? 'selected':'' }}>Cancelled</option>
            <option value="completed" {{ request('status')=='completed' ? 'selected':'' }}>Completed</option>
        </select>
        <button class="rounded-lg bg-slate-950 px-5 py-2 text-sm font-semibold text-white hover:bg-cyan-700">Filter</button>
    </form>

    <form method="POST" action="{{ route('admin.appointments.bulk') }}" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">@csrf
    <div class="flex flex-wrap gap-3 border-b border-slate-100 p-4">
        <select name="action" class="rounded-lg border-slate-200 text-sm">
            <option value="update_status">Update status</option>
            <option value="delete">Delete</option>
            <option value="export">Export CSV</option>
        </select>
        <select name="status" class="rounded-lg border-slate-200 text-sm">
            <option value="">-- status --</option>
            <option value="pending">pending</option>
            <option value="confirmed">confirmed</option>
            <option value="cancelled">cancelled</option>
            <option value="completed">completed</option>
        </select>
        <button class="rounded-lg bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-200">Apply selected</button>
    </div>
    <div class="overflow-x-auto"><table class="min-w-full text-left text-sm">
        <thead>
            <tr class="border-b border-slate-100 text-xs uppercase tracking-wider text-slate-500">
                <th><a href="?sort=id&dir={{ request('dir','desc')=='asc'?'desc':'asc' }}">ID</a></th>
                <th>Session</th>
                <th>Name</th>
                <th>Phone</th>
                <th>Preferred Date</th>
                <th>Status</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($appointments as $a)
            <tr class="border-b border-slate-100 transition hover:bg-slate-50">
                            <td><input type="checkbox" name="ids[]" value="{{ $a->id }}"/></td>
                            <td>{{ $a->id }}</td>
                            <td>{{ $a->session_id }}</td>
                            <td>{{ $a->name }}</td>
                            <td>{{ $a->phone }}</td>
                            <td>{{ $a->preferred_date }}</td>
                            <td><span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">{{ ucfirst($a->status) }}</span></td>
                            <td>{{ $a->created_at }}</td>
                            <td>
                                <a href="{{ route('admin.appointments.show', $a->id) }}" class="btn btn-sm btn-primary">Open</a>
                            </td>
                        </tr>
            @empty
            <tr><td colspan="8">No appointment requests found.</td></tr>
            @endforelse
        </tbody>
    </table></div>

    {{ $appointments->links() }}
</div>
@endsection
