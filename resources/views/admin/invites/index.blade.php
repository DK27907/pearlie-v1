@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Invites</h1>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="mb-3">
        <form method="POST" action="{{ route('admin.invites.store') }}" class="form-inline">
            @csrf
            <input type="email" name="email" placeholder="user@example.com" class="form-control mr-2" required />
            <input type="number" name="expires_in_days" placeholder="Expires in days (optional)" class="form-control mr-2" min="1" max="365" />
            <button class="btn btn-primary">Create & Email Invite</button>
        </form>
    </div>

    <table class="table">
        <thead><tr><th>ID</th><th>Email</th><th>Token</th><th>Expires At</th><th>Used At</th><th>Actions</th></tr></thead>
        <tbody>
        @foreach($invites as $inv)
            <tr>
                <td>{{ $inv->id }}</td>
                <td>{{ $inv->email }}</td>
                <td>{{ $inv->token ? '••••••••' : '' }}</td>
                <td>{{ $inv->expires_at }}</td>
                <td>{{ $inv->used_at }}</td>
                <td>
                    <form method="POST" action="{{ route('admin.invites.revoke', $inv->id) }}" style="display:inline">@csrf @method('PATCH')<button class="btn btn-sm btn-warning">Revoke</button></form>
                    <form method="POST" action="{{ route('admin.invites.destroy', $inv->id) }}" style="display:inline">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">Delete</button></form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    {{ $invites->links() }}
</div>
@endsection
