@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Escalation #{{ $esc->id }}</h1>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card mb-3">
        <div class="card-body">
            <h5>User message</h5>
            <p>{{ $esc->user_message }}</p>

            @if($esc->ai_response)
                <h5>AI response</h5>
                <p>{{ $esc->ai_response }}</p>
            @endif

            <p><strong>Status:</strong> {{ $esc->status }}</p>
            <p><strong>Session:</strong> {{ $esc->session_id }}</p>
            <p><strong>Created:</strong> {{ $esc->created_at }}</p>

            <form method="POST" action="{{ route('admin.escalations.updateStatus', $esc->id) }}">
                @csrf
                <div class="form-group">
                    <label for="status">Update status</label>
                    <select name="status" id="status" class="form-control">
                        <option value="pending" {{ $esc->status === 'pending' ? 'selected' : '' }}>Pending</option>
                        <option value="in_progress" {{ $esc->status === 'in_progress' ? 'selected' : '' }}>In Progress</option>
                        <option value="resolved" {{ $esc->status === 'resolved' ? 'selected' : '' }}>Resolved</option>
                        <option value="closed" {{ $esc->status === 'closed' ? 'selected' : '' }}>Closed</option>
                    </select>
                </div>
                <button class="btn btn-primary mt-2">Update</button>
            </form>

            <form method="POST" action="{{ route('admin.escalations.resend', $esc->id) }}" style="display:inline">
                @csrf
                <button class="btn btn-secondary mt-2">Resend Notification</button>
            </form>
        </div>
    </div>

    <h3>Conversation (latest)</h3>
    <div>
        @foreach($conversation as $c)
            <div style="margin-bottom:12px">
                <strong>User:</strong> {{ $c->user_message }}<br/>
                <strong>AI:</strong> {{ $c->ai_response }}
            </div>
        @endforeach
    </div>

    <a href="{{ route('admin.escalations.index') }}" class="btn btn-link">Back to list</a>
</div>
@endsection
