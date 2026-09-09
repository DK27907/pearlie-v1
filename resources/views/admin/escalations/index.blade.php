@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
    <div class="mb-8 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
        <div><p class="text-sm font-semibold uppercase tracking-widest text-rose-600">Needs attention</p><h1 class="mt-2 text-3xl font-bold text-slate-900">Escalations</h1><p class="mt-2 text-slate-500">Review conversations that need human follow-up.</p></div>
        <a class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:border-rose-300" href="{{ route('admin.escalations.exportCsv') }}">Export CSV</a>
    </div>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <form method="GET" class="mb-5 flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row">
        <input type="text" name="q" class="w-full rounded-lg border-slate-200 text-sm focus:border-rose-500 focus:ring-rose-500 sm:max-w-sm" placeholder="Search messages" value="{{ request('q') }}" />
        <select name="status" class="rounded-lg border-slate-200 text-sm focus:border-rose-500 focus:ring-rose-500">
            <option value="">All statuses</option>
            <option value="pending" {{ request('status')=='pending' ? 'selected':'' }}>Pending</option>
            <option value="in_progress" {{ request('status')=='in_progress' ? 'selected':'' }}>In Progress</option>
            <option value="resolved" {{ request('status')=='resolved' ? 'selected':'' }}>Resolved</option>
            <option value="closed" {{ request('status')=='closed' ? 'selected':'' }}>Closed</option>
        </select>
        <button class="rounded-lg bg-slate-950 px-5 py-2 text-sm font-semibold text-white hover:bg-rose-700">Filter</button>
    </form>

    <form method="POST" action="{{ route('admin.escalations.bulk') }}" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">@csrf
    <div class="flex flex-wrap gap-3 border-b border-slate-100 p-4">
        <select name="action" class="rounded-lg border-slate-200 text-sm">
            <option value="update_status">Update status</option>
            <option value="delete">Delete</option>
            <option value="export">Export CSV</option>
        </select>
        <select name="status" class="rounded-lg border-slate-200 text-sm">
            <option value="">-- status --</option>
            <option value="pending">pending</option>
            <option value="in_progress">in_progress</option>
            <option value="resolved">resolved</option>
            <option value="closed">closed</option>
        </select>
        <button class="rounded-lg bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-200">Apply selected</button>
    </div>
    <div class="overflow-x-auto"><table class="min-w-full text-left text-sm">
        <thead>
            <tr class="border-b border-slate-100 text-xs uppercase tracking-wider text-slate-500">
                <th><a href="?sort=id&dir={{ request('dir','desc')=='asc'?'desc':'asc' }}">ID</a></th>
                <th>Session</th>
                <th>Message</th>
                <th><a href="?sort=status&dir={{ request('dir','desc')=='asc'?'desc':'asc' }}">Status</a></th>
                <th><a href="?sort=created_at&dir={{ request('dir','desc')=='asc'?'desc':'asc' }}">Created</a></th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($escalations as $esc)
            <tr class="border-b border-slate-100 transition hover:bg-slate-50">
                            <td><input type="checkbox" name="ids[]" value="{{ $esc->id }}"/></td>
                            <td>{{ $esc->id }}</td>
                            <td>{{ $esc->session_id }}</td>
                            <td>{{ Str::limit($esc->user_message, 80) }}</td>
                            <td><span class="rounded-full bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700">{{ ucfirst(str_replace('_', ' ', $esc->status)) }}</span></td>
                            <td>{{ $esc->created_at }}</td>
                            <td>
                                <a href="{{ route('admin.escalations.show', $esc->id) }}" class="btn btn-sm btn-primary">Open</a>
                            </td>
                        </tr>
            @empty
            <tr><td colspan="6">No escalations found.</td></tr>
            @endforelse
        </tbody>
    </table></div>
</form>

    {{ $escalations->links() }}
</div>
@endsection
