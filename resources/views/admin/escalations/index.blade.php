@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Escalations</h1>
    <p class="text-muted">Review conversations that need human follow-up and update their status.</p>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <form method="GET" class="form-inline mb-3">
        <input type="text" name="q" class="form-control mr-2" placeholder="Search messages" value="{{ request('q') }}" />
        <select name="status" class="form-control mr-2">
            <option value="">All statuses</option>
            <option value="pending" {{ request('status')=='pending' ? 'selected':'' }}>Pending</option>
            <option value="in_progress" {{ request('status')=='in_progress' ? 'selected':'' }}>In Progress</option>
            <option value="resolved" {{ request('status')=='resolved' ? 'selected':'' }}>Resolved</option>
            <option value="closed" {{ request('status')=='closed' ? 'selected':'' }}>Closed</option>
        </select>
        <button class="btn btn-secondary">Filter</button>
    </form>

    <form method="POST" action="{{ route('admin.escalations.bulk') }}">@csrf
    <div class="mb-2">
        <select name="action" class="form-control d-inline-block" style="width:auto;">
            <option value="update_status">Update status</option>
            <option value="delete">Delete</option>
            <option value="export">Export CSV</option>
        </select>
        <select name="status" class="form-control d-inline-block" style="width:auto;">
            <option value="">-- status --</option>
            <option value="pending">pending</option>
            <option value="in_progress">in_progress</option>
            <option value="resolved">resolved</option>
            <option value="closed">closed</option>
        </select>
        <button class="btn btn-secondary">Apply</button>
        <a class="btn btn-outline-secondary ml-2" href="{{ route('admin.escalations.exportCsv') }}">Export all CSV</a>
    </div>
    <table class="table table-striped">
        <thead>
            <tr>
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
            <tr>
                            <td><input type="checkbox" name="ids[]" value="{{ $esc->id }}"/></td>
                            <td>{{ $esc->id }}</td>
                            <td>{{ $esc->session_id }}</td>
                            <td>{{ Str::limit($esc->user_message, 80) }}</td>
                            <td>{{ $esc->status }}</td>
                            <td>{{ $esc->created_at }}</td>
                            <td>
                                <a href="{{ route('admin.escalations.show', $esc->id) }}" class="btn btn-sm btn-primary">Open</a>
                            </td>
                        </tr>
            @empty
            <tr><td colspan="6">No escalations found.</td></tr>
            @endforelse
        </tbody>
    </table>
</form>

    {{ $escalations->links() }}
</div>
@endsection
