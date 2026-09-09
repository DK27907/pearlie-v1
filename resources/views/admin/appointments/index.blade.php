@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Appointment Requests</h1>
    <p class="text-muted">Review new requests, confirm details, and notify patients.</p>

    <form method="GET" class="form-inline mb-3">
        <input type="text" name="q" class="form-control mr-2" placeholder="Search" value="{{ request('q') }}" />
        <select name="status" class="form-control mr-2">
            <option value="">All statuses</option>
            <option value="pending" {{ request('status')=='pending' ? 'selected':'' }}>Pending</option>
            <option value="confirmed" {{ request('status')=='confirmed' ? 'selected':'' }}>Confirmed</option>
            <option value="cancelled" {{ request('status')=='cancelled' ? 'selected':'' }}>Cancelled</option>
            <option value="completed" {{ request('status')=='completed' ? 'selected':'' }}>Completed</option>
        </select>
        <button class="btn btn-secondary">Filter</button>
    </form>

    <form method="POST" action="{{ route('admin.appointments.bulk') }}">@csrf
    <div class="mb-2">
        <select name="action" class="form-control d-inline-block" style="width:auto;">
            <option value="update_status">Update status</option>
            <option value="delete">Delete</option>
            <option value="export">Export CSV</option>
        </select>
        <select name="status" class="form-control d-inline-block" style="width:auto;">
            <option value="">-- status --</option>
            <option value="pending">pending</option>
            <option value="confirmed">confirmed</option>
            <option value="cancelled">cancelled</option>
            <option value="completed">completed</option>
        </select>
        <button class="btn btn-secondary">Apply</button>
        <a class="btn btn-outline-secondary ml-2" href="{{ route('admin.appointments.exportCsv') }}">Export all CSV</a>
    </div>
    <table class="table table-striped">
        <thead>
            <tr>
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
            <tr>
                            <td><input type="checkbox" name="ids[]" value="{{ $a->id }}"/></td>
                            <td>{{ $a->id }}</td>
                            <td>{{ $a->session_id }}</td>
                            <td>{{ $a->name }}</td>
                            <td>{{ $a->phone }}</td>
                            <td>{{ $a->preferred_date }}</td>
                            <td>{{ $a->status }}</td>
                            <td>{{ $a->created_at }}</td>
                            <td>
                                <a href="{{ route('admin.appointments.show', $a->id) }}" class="btn btn-sm btn-primary">Open</a>
                            </td>
                        </tr>
            @empty
            <tr><td colspan="8">No appointment requests found.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{ $appointments->links() }}
</div>
@endsection
